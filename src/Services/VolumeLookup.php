<?php

namespace BuybackManager\Services;

use BuybackManager\Jobs\FetchPackagedVolumes;
use BuybackManager\Models\BuybackPackagedVolume;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * How many m3 each item takes up in a hauler, for the haul fee and the m3
 * shown on quotes and contracts.
 *
 * SeAT's SDE volume is right for every item that does not repackage. For
 * the ones that do (ships, capital modules, cargo containers) the packaged
 * volume is read from SeAT's own copy of CCP's static data, which fills
 * buyback_manager_packaged_volumes for every type of every group where anything
 * repackages. A type of such a group that is not there yet, or any ship
 * when SeAT has no copy, is counted assembled for this quote and one ESI
 * call is queued for it. Nothing here waits on ESI.
 */
class VolumeLookup
{
    public const SHIP_CATEGORY = 6;

    /** A packaged volume from Janice or ESI is asked again when a quote finds it older than this. */
    public const REFRESH_DAYS = 90;

    /** @var array<int, true>|null */
    private ?array $repackaging = null;

    /**
     * @param  int[]  $typeIds
     * @return array{volumes: array<int, float>, assembled: int[]}  assembled: items counted at their assembled volume
     */
    public function unitVolumes(array $typeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $typeIds), fn ($id) => $id > 0)));
        if ($ids === []) {
            return ['volumes' => [], 'assembled' => []];
        }

        $volumes = [];
        $groups = [];
        $ships = [];
        foreach (DB::table('invTypes')
            ->leftJoin('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
            ->whereIn('invTypes.typeID', $ids)
            ->get(['invTypes.typeID', 'invTypes.groupID', 'invTypes.volume', 'invGroups.categoryID']) as $row) {
            $typeId = (int) $row->typeID;
            $volumes[$typeId] = max(0.0, (float) $row->volume);
            $groups[$typeId] = (int) $row->groupID;
            if ((int) $row->categoryID === self::SHIP_CATEGORY) {
                $ships[$typeId] = true;
            }
        }

        $known = [];
        $refresh = [];
        $staleBefore = Carbon::now()->subDays(self::REFRESH_DAYS);
        foreach (BuybackPackagedVolume::whereIn('type_id', $ids)->get() as $row) {
            $typeId = (int) $row->type_id;
            $known[$typeId] = true;
            if ((float) $row->packaged_volume > 0) {
                $volumes[$typeId] = (float) $row->packaged_volume;
            }
            // SeAT's static data is read again with each build; the others age.
            if ($row->source !== BuybackPackagedVolume::SOURCE_SDE && ($row->fetched_at === null || $row->fetched_at->lt($staleBefore))) {
                $refresh[] = $typeId;
            }
        }

        $repackaging = $this->repackagingGroups();
        $assembled = [];
        foreach ($groups as $typeId => $groupId) {
            if (! isset($known[$typeId]) && (isset($ships[$typeId]) || isset($repackaging[$groupId]))) {
                $assembled[] = $typeId;
            }
        }

        if ($assembled !== [] || $refresh !== []) {
            try {
                FetchPackagedVolumes::request(array_merge($assembled, $refresh));
            } catch (\Throwable $e) {
                Log::warning('[Buyback Manager] Could not queue the packaged volume fetch: ' . $e->getMessage());
            }
        }

        return ['volumes' => $volumes, 'assembled' => $assembled];
    }

    /**
     * The groups in which something packs smaller than it is assembled, as
     * SeAT's copy of CCP's static data says. Empty when SeAT has no copy,
     * and then only ships are looked up.
     *
     * @return array<int, true>
     */
    public function repackagingGroups(): array
    {
        if ($this->repackaging === null) {
            try {
                $this->repackaging = array_fill_keys(
                    BuybackPackagedVolume::where('source', BuybackPackagedVolume::SOURCE_SDE)
                        ->whereNotNull('group_id')
                        ->distinct()
                        ->pluck('group_id')
                        ->map(fn ($id) => (int) $id)
                        ->all(),
                    true
                );
            } catch (\Throwable $e) {
                $this->repackaging = [];
            }
        }

        return $this->repackaging;
    }

    /**
     * Keep packaged volumes that Janice or ESI gave. One read from SeAT's
     * static data is never replaced this way: that copy is the one CCP
     * published, and the next build refreshes it.
     *
     * @param  array<int, float>  $volumes
     * @param  array<int, int>  $groups  group of each type, where the source said
     */
    public static function remember(array $volumes, string $source = BuybackPackagedVolume::SOURCE_JANICE, array $groups = []): void
    {
        $volumes = array_filter($volumes, fn ($volume, $typeId) => (int) $typeId > 0 && $volume > 0, ARRAY_FILTER_USE_BOTH);
        if ($volumes === []) {
            return;
        }

        try {
            $fromSde = BuybackPackagedVolume::whereIn('type_id', array_keys($volumes))
                ->where('source', BuybackPackagedVolume::SOURCE_SDE)
                ->pluck('type_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $now = Carbon::now()->toDateTimeString();
            $rows = [];
            foreach (array_diff_key($volumes, array_flip($fromSde)) as $typeId => $volume) {
                $rows[] = [
                    'type_id' => (int) $typeId,
                    'group_id' => isset($groups[$typeId]) ? (int) $groups[$typeId] : null,
                    'packaged_volume' => (float) $volume,
                    'source' => $source,
                    'sde_build' => null,
                    'fetched_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                BuybackPackagedVolume::upsert($chunk, ['type_id'], ['group_id', 'packaged_volume', 'source', 'sde_build', 'fetched_at']);
            }
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] Could not keep packaged volumes: ' . $e->getMessage());
        }
    }
}
