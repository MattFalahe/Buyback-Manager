<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackLocationRule;
use BuybackManager\Models\BuybackSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves an EVE location id (NPC station, citadel structure, or system)
 * to its {station/structure, system, constellation, region} hierarchy
 * using SeAT's SDE (mapDenormalize) + universe_structures, and decides
 * whether a contract location satisfies a corp's buyback location rules.
 *
 * mapDenormalize covers NPC stations (groupID 15), systems (5),
 * constellations (4) and regions (3) with their parent ids. Citadels are
 * not in the SDE, so they resolve via universe_structures -> solar system
 * -> mapDenormalize.
 */
class LocationResolver
{
    private const GROUP_REGION = 3;
    private const GROUP_CONSTELLATION = 4;
    private const GROUP_SYSTEM = 5;
    private const GROUP_STATION = 15;

    /**
     * @return array{station_id:?int, structure_id:?int, system_id:?int, constellation_id:?int, region_id:?int}|null
     */
    public function resolve(int $locationId): ?array
    {
        if ($locationId <= 0) {
            return null;
        }

        // NPC station / system / celestial — present in mapDenormalize.
        $row = DB::table('mapDenormalize')
            ->where('itemID', $locationId)
            ->first(['groupID', 'solarSystemID', 'constellationID', 'regionID']);

        if ($row) {
            $groupId = (int) $row->groupID;
            $systemId = $row->solarSystemID ? (int) $row->solarSystemID : ($groupId === self::GROUP_SYSTEM ? $locationId : null);

            return [
                'station_id' => $groupId === self::GROUP_STATION ? $locationId : null,
                'structure_id' => null,
                'system_id' => $systemId,
                'constellation_id' => $row->constellationID ? (int) $row->constellationID : null,
                'region_id' => $row->regionID ? (int) $row->regionID : null,
            ];
        }

        // Citadel / player structure — resolve via universe_structures.
        $struct = DB::table('universe_structures')
            ->where('structure_id', $locationId)
            ->first(['solar_system_id']);

        if ($struct && $struct->solar_system_id) {
            $sys = DB::table('mapDenormalize')
                ->where('itemID', (int) $struct->solar_system_id)
                ->first(['constellationID', 'regionID']);

            return [
                'station_id' => null,
                'structure_id' => $locationId,
                'system_id' => (int) $struct->solar_system_id,
                'constellation_id' => $sys && $sys->constellationID ? (int) $sys->constellationID : null,
                'region_id' => $sys && $sys->regionID ? (int) $sys->regionID : null,
            ];
        }

        return null;
    }

    /**
     * Is a contract at $locationId allowed under this setting's location
     * rules? No rules = unrestricted (true). A station or structure named in
     * the rules matches by its id alone. Anything else has to be placed from
     * SeAT's data, and a location it cannot place cannot be proven in-scope,
     * so it is rejected.
     */
    public function isAllowed(int $locationId, BuybackSetting $setting): bool
    {
        return ! $setting->locationRules()->exists() || $this->matchingRule($locationId, $setting) !== null;
    }

    /**
     * The haul fee a contract made at $locationId pays per m3: the fee of the
     * most specific rule it falls under, or 0 where none is charged.
     */
    public function haulFeePerM3(int $locationId, BuybackSetting $setting): float
    {
        return (float) ($this->matchingRule($locationId, $setting)?->haul_fee_per_m3 ?? 0);
    }

    /**
     * The most specific rule a location falls under: its own station or
     * structure, then its system, constellation and region. Null when it is
     * under none, or cannot be placed.
     */
    public function matchingRule(int $locationId, BuybackSetting $setting): ?BuybackLocationRule
    {
        $rules = $setting->locationRules()->get(['location_type', 'location_id', 'location_name', 'haul_fee_per_m3']);
        if ($rules->isEmpty() || $locationId <= 0) {
            return null;
        }

        // Before the lookup, so a citadel SeAT has no record of still matches
        // its own rule.
        foreach ($rules as $rule) {
            if (in_array($rule->location_type, [BuybackLocationRule::TYPE_STATION, BuybackLocationRule::TYPE_STRUCTURE], true)
                && (int) $rule->location_id === $locationId) {
                return $rule;
            }
        }

        try {
            $resolved = $this->resolve($locationId);
        } catch (\Throwable $e) {
            // Almost always a missing SDE (mapDenormalize / universe_structures
            // absent or not imported). Say so plainly: the symptom otherwise is
            // every contract silently failing its location check with no clue
            // why. Fail closed, since an unverifiable location cannot be proven
            // to be inside the allowed list.
            Log::error('[Buyback Manager] Location lookup failed for location ' . $locationId
                . ' — is the SDE imported? ' . $e->getMessage(), [
                    'location_id' => $locationId,
                    'corporation_id' => $setting->corporation_id,
                    'exception' => get_class($e),
                ]);

            return null;
        }

        if ($resolved === null) {
            Log::info('[Buyback Manager] Location ' . $locationId . ' could not be resolved (unknown station or citadel); treating as outside the allowed locations', [
                'location_id' => $locationId,
                'corporation_id' => $setting->corporation_id,
            ]);

            return null;
        }

        // Narrowest first. Stations and structures were matched by id above.
        foreach ([
            BuybackLocationRule::TYPE_SYSTEM => $resolved['system_id'],
            BuybackLocationRule::TYPE_CONSTELLATION => $resolved['constellation_id'],
            BuybackLocationRule::TYPE_REGION => $resolved['region_id'],
        ] as $type => $id) {
            $rule = $id === null ? null : $rules->first(fn ($r) => $r->location_type === $type && (int) $r->location_id === $id);
            if ($rule !== null) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Display name for a single location id of a given type, for caching
     * when an operator adds a rule. Null when unknown.
     */
    public function nameFor(string $type, int $id): ?string
    {
        if ($type === BuybackLocationRule::TYPE_STRUCTURE) {
            return DB::table('universe_structures')->where('structure_id', $id)->value('name');
        }

        return DB::table('mapDenormalize')->where('itemID', $id)->value('itemName');
    }

    /**
     * Name search for the location picker. Returns up to $limit
     * [['id'=>int,'name'=>string], ...] for the given type.
     */
    public function search(string $type, string $query, int $limit = 20): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        if ($type === BuybackLocationRule::TYPE_STRUCTURE) {
            return DB::table('universe_structures')
                ->whereNotNull('name')
                ->where('name', 'like', '%' . $query . '%')
                ->orderBy('name')
                ->limit($limit)
                ->get(['structure_id as id', 'name'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])
                ->all();
        }

        $groupId = match ($type) {
            BuybackLocationRule::TYPE_REGION => self::GROUP_REGION,
            BuybackLocationRule::TYPE_CONSTELLATION => self::GROUP_CONSTELLATION,
            BuybackLocationRule::TYPE_SYSTEM => self::GROUP_SYSTEM,
            BuybackLocationRule::TYPE_STATION => self::GROUP_STATION,
            default => null,
        };
        if ($groupId === null) {
            return [];
        }

        return DB::table('mapDenormalize')
            ->where('groupID', $groupId)
            ->where('itemName', 'like', '%' . $query . '%')
            ->orderBy('itemName')
            ->limit($limit)
            ->get(['itemID as id', 'itemName as name'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])
            ->all();
    }
}
