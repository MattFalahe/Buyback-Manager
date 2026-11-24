<?php

namespace BuybackManager\Jobs;

use BuybackManager\Models\BuybackPackagedVolume;
use BuybackManager\Services\SdeVolumes;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reads the packaged volumes out of SeAT's copy of CCP's static data, once
 * for each build SeAT downloads. The contract sync asks for it, so a new
 * SDE reaches the quotes within one sync.
 */
class ImportPackagedVolumes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A build already queued is not queued again for this long, whatever the sync sees. */
    public const ASK_AGAIN_SECONDS = 3600;

    private const QUEUED_KEY = 'buyback-manager:packaged-volume-import:';

    private const CHUNK = 500;

    public int $timeout = 300;

    public int $tries = 2;

    public int $backoff = 300;

    public ?string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('buyback-manager:packaged-volume-import'))->dontRelease()->expireAfter(600)];
    }

    /** Queue an import when SeAT has static data Buyback has not read yet. Cheap enough for every sync. */
    public static function dispatchIfDue(?string $directory = null): bool
    {
        $build = SdeVolumes::build($directory);
        if ($build === null || $build === self::importedBuild()) {
            return false;
        }

        if (! Cache::add(self::QUEUED_KEY . $build, 1, self::ASK_AGAIN_SECONDS)) {
            return false;
        }

        self::dispatch($directory);

        return true;
    }

    /** The static data build the table was last filled from. */
    public static function importedBuild(): ?int
    {
        $build = BuybackPackagedVolume::where('source', BuybackPackagedVolume::SOURCE_SDE)->max('sde_build');

        return $build === null ? null : (int) $build;
    }

    public function handle(): void
    {
        $build = SdeVolumes::build($this->directory);
        if ($build === null) {
            return;
        }

        $rows = SdeVolumes::read($this->directory);
        if ($rows === []) {
            // A file cut short reads as nothing; the volumes already here
            // are better than an empty table.
            Log::warning('[Buyback Manager] SeAT\'s static data build ' . $build . ' gave no packaged volumes; the ones already read are kept');

            return;
        }

        $now = Carbon::now()->toDateTimeString();
        $batch = [];

        // All or nothing, so a failed run leaves the last build whole and the
        // next sync asks again.
        DB::transaction(function () use ($rows, $build, $now, &$batch) {
            foreach ($rows as $typeId => $row) {
                $batch[] = [
                    'type_id' => (int) $typeId,
                    'group_id' => $row['group_id'],
                    'packaged_volume' => $row['packaged_volume'],
                    'source' => BuybackPackagedVolume::SOURCE_SDE,
                    'sde_build' => $build,
                    'fetched_at' => $now,
                ];
                if (count($batch) === self::CHUNK) {
                    BuybackPackagedVolume::upsert($batch, ['type_id'], ['group_id', 'packaged_volume', 'source', 'sde_build', 'fetched_at']);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                BuybackPackagedVolume::upsert($batch, ['type_id'], ['group_id', 'packaged_volume', 'source', 'sde_build', 'fetched_at']);
            }

            // A type CCP moved out of every repackaging group.
            BuybackPackagedVolume::where('source', BuybackPackagedVolume::SOURCE_SDE)->where('sde_build', '<>', $build)->delete();
        });

        Log::info('[Buyback Manager] Read ' . count($rows) . ' packaged volumes from SeAT\'s static data build ' . $build);
    }
}
