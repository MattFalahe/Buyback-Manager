<?php

namespace BuybackManager\Jobs;

use BuybackManager\Models\BuybackPackagedVolume;
use BuybackManager\Services\VolumeLookup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the packaged volume of items SeAT's static data does not know from
 * ESI's public type endpoint: one newer than SeAT's copy, or any ship when
 * SeAT has no copy. Queued by a quote that met them, one call per type, so
 * ESI is only ever asked for something somebody actually sells.
 */
class FetchPackagedVolumes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ESI_URL = 'https://esi.evetech.net/latest/universe/types/%d/';

    /** Types per job. Each call is quick, so a batch stays far inside the timeout. */
    public const BATCH = 50;

    /** A type asked for is not asked again for this long, however often it is quoted. */
    public const ASK_AGAIN_SECONDS = 3600;

    private const ASKED_KEY = 'buyback-manager:packaged-volume-asked:';

    private const HTTP_TIMEOUT_SECONDS = 10;

    public int $timeout = 300;

    public int $tries = 2;

    public int $backoff = 120;

    /** @var int[] */
    public array $typeIds;

    /** @param  int[]  $typeIds */
    public function __construct(array $typeIds)
    {
        $this->typeIds = array_values(array_unique(array_map('intval', $typeIds)));
    }

    /**
     * Queue the types not asked for within the hour.
     *
     * @param  int[]  $typeIds
     */
    public static function request(array $typeIds): void
    {
        $due = [];
        foreach (array_unique(array_map('intval', $typeIds)) as $typeId) {
            if ($typeId > 0 && Cache::add(self::ASKED_KEY . $typeId, 1, self::ASK_AGAIN_SECONDS)) {
                $due[] = $typeId;
            }
        }

        foreach (array_chunk($due, self::BATCH) as $batch) {
            self::dispatch($batch);
        }
    }

    public function handle(): void
    {
        $found = [];
        $groups = [];

        foreach ($this->typeIds as $typeId) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get(sprintf(self::ESI_URL, $typeId), ['datasource' => 'tranquility']);
            } catch (\Throwable $e) {
                Log::warning('[Buyback Manager] ESI did not answer for the packaged volume of type ' . $typeId . ': ' . $e->getMessage());
                continue;
            }

            // ESI's error limit is shared with the rest of SeAT, so a refusal
            // stops the batch rather than spending more of it.
            if (in_array($response->status(), [420, 429], true)) {
                Log::warning('[Buyback Manager] ESI refused packaged volume lookups with HTTP ' . $response->status() . '; the rest wait for the next quote that needs them');
                break;
            }

            // A type that does not repackage has no packaged_volume; its own
            // volume is then the one it hauls at, and keeping it stops it
            // being asked again.
            $volume = $response->successful()
                ? (float) ($response->json('packaged_volume') ?? $response->json('volume') ?? 0)
                : 0.0;
            if ($volume > 0) {
                $found[$typeId] = $volume;
                if ($response->json('group_id') !== null) {
                    $groups[$typeId] = (int) $response->json('group_id');
                }
            } elseif (! $response->successful()) {
                Log::info('[Buyback Manager] ESI returned HTTP ' . $response->status() . ' for the packaged volume of type ' . $typeId);
            }
        }

        // Until a type is here its quotes count it assembled, which only
        // overstates the haul fee and says so on the quote.
        VolumeLookup::remember($found, BuybackPackagedVolume::SOURCE_ESI, $groups);
    }
}
