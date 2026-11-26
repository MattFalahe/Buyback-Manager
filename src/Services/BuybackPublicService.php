<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackAppraisal;
use BuybackManager\Models\BuybackContract;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\Pricing\PriceProviderService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Seat\Eveapi\Models\Sde\InvCategory;
use Seat\Eveapi\Models\Sde\InvGroup;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * Backing service for the public buyback landing page.
 *
 * Resolves a programme's public page by the name in its link, builds the
 * rates and look it shares with the members page, and stores/deletes the
 * uploaded background + logo images. Image handling
 * mirrors HR Manager's hero-image
 * pattern: store on a configurable disk, serve later via a streaming route
 * (NOT Storage::url()), so it works without `php artisan storage:link` on
 * Docker and bare-metal alike and serves from SeAT's own origin (CSP-safe).
 */
class BuybackPublicService
{
    public function uploadDisk(): string
    {
        return config('buyback-manager.config.public.upload_disk', 'public');
    }

    /**
     * Resolve the public page for the name in its link, or null when no
     * programme goes by it.
     *
     * The name is whoever the contract goes to (see the setting's
     * public_slug), and failing that the programme's own ticker, which is
     * what every link carried before the name followed the contract, so
     * old links still land. Names are not unique: two programmes can send
     * contracts to the same corporation, and a closed corporation keeps its
     * ticker. A published page wins over an unpublished one, so the images
     * follow the page, and between two of a kind the programme whose
     * corporation SeAT refreshed last wins.
     */
    public function resolveByLink(string $link, bool $requireEnabled = true): ?BuybackSetting
    {
        $programmes = $this->programmesFor(trim($link), $requireEnabled);

        return $programmes['named'][0] ?? $programmes['ticker'][0] ?? null;
    }

    /**
     * The other programmes whose link carries the same name as this one's.
     * Only the first of them is reachable, so the page settings warn.
     *
     * @return Collection<int, BuybackSetting>
     */
    public function sharingLink(BuybackSetting $setting): Collection
    {
        return collect($this->programmesFor($setting->public_slug, false)['named'])
            ->reject(fn (BuybackSetting $other) => (int) $other->id === (int) $setting->id)
            ->values();
    }

    /**
     * Programmes whose link name is $link, and those whose own ticker is,
     * each in the order the lookup takes them.
     *
     * @return array{named: BuybackSetting[], ticker: BuybackSetting[]}
     */
    private function programmesFor(string $link, bool $requireEnabled): array
    {
        if ($link === '') {
            return ['named' => [], 'ticker' => []];
        }

        // A handful of programmes at most, so the names are worked out here
        // rather than taught to SQL.
        $programmes = BuybackSetting::with(['corporation', 'targetCorporation', 'character'])
            ->when($requireEnabled, fn ($q) => $q
                ->where('public_page_enabled', true)
                ->where('enabled', true))
            ->get()
            ->sortBy(fn (BuybackSetting $s) => [
                $s->public_page_enabled && $s->enabled ? 0 : 1,
                -$this->refreshedAt($s),
            ])
            ->values();

        return [
            'named' => $programmes->filter(fn (BuybackSetting $s) => strcasecmp($s->public_slug, $link) === 0)->values()->all(),
            'ticker' => $programmes->filter(fn (BuybackSetting $s) => strcasecmp($s->corp_ticker, $link) === 0)->values()->all(),
        ];
    }

    private function refreshedAt(BuybackSetting $setting): int
    {
        $updated = optional($setting->corporation)->updated_at;

        return $updated ? Carbon::parse($updated)->getTimestamp() : 0;
    }

    /**
     * When a quote stops holding. A contract made after this is still
     * matched, but flagged for a director to re-check the price.
     */
    public function validUntil(BuybackAppraisal $appraisal, BuybackSetting $setting): ?Carbon
    {
        return $appraisal->created_at?->copy()->addHours($setting->staleAfterHours());
    }

    /**
     * What became of the contract that used this key, worded for the
     * seller, or null while no contract has used it.
     *
     * Anyone with the link can read this, so it gives the state and its
     * date only, never who issued or accepted the contract.
     *
     * @return array{state: string, tone: string, title: string, text: string}|null
     */
    public function keyStatus(BuybackAppraisal $appraisal): ?array
    {
        if (! $appraisal->isClaimed()) {
            return null;
        }

        $contract = $appraisal->contract;
        $again = 'The key cannot be used again, so run a new appraisal if you still want to sell.';

        if (! $contract) {
            return $this->status('used', 'warn', 'This key has already been used',
                'A contract has used it, so it cannot be used again. Run a new appraisal if you have more to sell.');
        }

        $status = $contract->displayStatus();
        $on = fn ($date) => $date ? ' on ' . $date->format('j M Y, H:i') . ' EVE' : '';

        if (in_array($status, BuybackContract::COMPLETED_STATES, true)) {
            return $this->status('paid', 'ok', 'Paid',
                'We accepted your contract' . $on($contract->completed_date) . ', so the ISK has been paid to you.');
        }

        return match ($status) {
            'outstanding', 'in_progress' => $this->status('received', 'info', 'Contract received',
                'We have your contract' . ($contract->issued_date ? ', issued' . $on($contract->issued_date) : '')
                . '. It is waiting for us to accept it.'),
            'expired' => $this->status('expired', 'warn', 'Contract expired',
                'Your contract expired before we accepted it. ' . $again),
            'cancelled', 'deleted' => $this->status('cancelled', 'warn', 'Contract cancelled',
                'The contract made with this key was withdrawn. ' . $again),
            'rejected' => $this->status('declined', 'warn', 'Contract declined',
                'We declined the contract made with this key. Ask us if you are unsure why. ' . $again),
            default => $this->status('closed', 'warn', 'Contract closed',
                'The contract made with this key was closed without being paid. ' . $again),
        };
    }

    private function status(string $state, string $tone, string $title, string $text): array
    {
        return compact('state', 'tone', 'title', 'text');
    }

    /**
     * Store an uploaded image and return its disk-relative path.
     *
     * @param  string  $kind  'background' | 'logo' (filename prefix only)
     * @throws \RuntimeException with an operator-actionable message on any
     *         PHP-level or filesystem failure.
     */
    public function storeImage(UploadedFile $file, string $kind): string
    {
        if (! $file->isValid()) {
            throw new \RuntimeException(
                'Upload was rejected by PHP. Likely cause: file exceeded '
                . 'upload_max_filesize / post_max_size. Error code: ' . $file->getError()
            );
        }

        $disk = $this->uploadDisk();

        // Prefer the client extension; derive from MIME when missing (some
        // browsers strip it on paste-upload) so the streaming route can
        // still serve a sensible content type.
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if ($ext === '' || strlen($ext) > 5) {
            $ext = match ($file->getMimeType()) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'bin',
            };
        }

        $prefix = $kind === 'logo' ? 'logo_' : 'bg_';
        $filename = $prefix . Str::random(40) . '.' . $ext;

        try {
            $path = $file->storeAs('buyback-manager/public', $filename, $disk);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Filesystem write failed on disk '{$disk}': " . $e->getMessage(), 0, $e);
        }

        if ($path === false || $path === null || $path === '') {
            throw new \RuntimeException(
                "Filesystem write returned no path. Disk '{$disk}' is likely not writable. "
                . 'Check the filesystems config + container volume mounts.'
            );
        }

        // Belt + braces: confirm the bytes landed. Disks that silently
        // swallow writes (mis-mounted volume, full disk) would otherwise
        // leave a DB row pointing at a 404.
        if (! Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException(
                "Stored path '{$path}' does not exist on disk '{$disk}' immediately after write. "
                . 'The disk is misconfigured or the volume is read-only.'
            );
        }

        return $path;
    }

    public function deleteImage(?string $path): void
    {
        if (! $path) {
            return;
        }
        $disk = $this->uploadDisk();
        try {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (\Throwable $e) {
            Log::warning('[Buyback Manager] public image delete failed: ' . $e->getMessage());
        }
    }

    /**
     * What every page in the programme's look carries of the corporation:
     * its name, colours and images. The public pages and the members page
     * share it.
     */
    public function branding(BuybackSetting $setting): array
    {
        // Relative asset URLs: an absolute one inherits the scheme Laravel
        // derived from APP_URL / proxy headers, which breaks under a strict
        // CSP (or as mixed content) when that scheme does not match how the
        // page was actually served. Root-relative is always same-origin.
        $backgroundUrl = ! empty($setting->public_background_path)
            ? route('buyback-manager.public.image', ['ticker' => $setting->public_slug, 'kind' => 'background'], false)
            : null;

        $logoUrl = ! empty($setting->public_logo_path)
            ? route('buyback-manager.public.image', ['ticker' => $setting->public_slug, 'kind' => 'logo'], false)
            : ('https://images.evetech.net/corporations/' . $setting->corporation_id . '/logo?size=128');

        return [
            'corpName'      => optional($setting->corporation)->name ?? ('Corporation #' . $setting->corporation_id),
            'badge'         => $setting->publicTargetBadge(),
            'link'          => $setting->public_slug,
            'accent'        => self::safeAccent($setting->public_accent_color),
            'overlay'       => max(0, min(100, (int) ($setting->public_overlay_opacity ?? 55))) / 100,
            'backgroundUrl' => $backgroundUrl,
            'logoUrl'       => $logoUrl,
            'logoStyle'     => in_array($setting->public_logo_style, ['dark', 'none', 'light'], true) ? $setting->public_logo_style : 'dark',
        ];
    }

    /**
     * Validate the stored accent colour before it reaches an inline style,
     * falling back to the default teal. Defence-in-depth on top of the
     * save-time regex so a page can never become a CSS-injection surface.
     */
    public static function safeAccent(?string $color): string
    {
        $color = (string) $color;

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#1d9e75';
    }

    /**
     * Advertised rates: base %, lock window, and the pricing rules (with SDE
     * names resolved). Honours two optional display flags:
     *   - public_show_all_rules: list every non-excluded rule, else only
     *     the featured ("most wanted") ones.
     *   - public_show_pricing_detail: include the provider and market + each
     *     rule's price side (buy / sell / split). Excluded rules are never
     *     advertised regardless.
     *
     * At member rates each percentage carries the member bonus, and the
     * public one beside it. The public page never asks for member rates.
     */
    public function rates(BuybackSetting $setting, PriceProviderService $prices, bool $member = false): array
    {
        $query = $setting->pricingRules()->where('excluded', false);
        if (! $setting->public_show_all_rules) {
            $query->where('featured', true);
        }
        $rules = $query->orderBy('featured', 'desc')->orderBy('priority', 'desc')->get();

        $names = $this->resolveRuleNames($rules);
        $showDetail = (bool) $setting->public_show_pricing_detail;
        $rate = fn (float $percentage) => $member ? $setting->memberPercentage($percentage) : $percentage;

        $items = $rules->map(fn ($rule) => [
            'name' => $rule->display_name ?: ($names[$rule->type][$rule->type_id] ?? (ucfirst($rule->type) . ' #' . $rule->type_id)),
            'percentage' => $rate((float) $rule->percentage),
            'public_percentage' => (float) $rule->percentage,
            'featured' => (bool) $rule->featured,
            'price_side' => $showDetail ? $this->sideLabel($rule->price_side) : null,
            'refine_yield' => $rule->refined
                ? rtrim(rtrim(number_format($rule->refine_yield !== null ? (float) $rule->refine_yield : $setting->programmeRefineYield(), 2), '0'), '.')
                : null,
        ])->values()->all();

        return [
            'base_percentage' => $rate((float) $setting->base_percentage),
            'public_base_percentage' => (float) $setting->base_percentage,
            'member' => $member,
            'stale_hours' => $setting->staleAfterHours(),
            'items' => $items,
            'show_detail' => $showDetail,
            'source' => $showDetail ? $prices->currentSourceLabel($setting) : null,
        ];
    }

    /**
     * Short, public-friendly label for a rule's price side. Null (use the
     * corp's default side) returns null so no badge is shown.
     */
    private function sideLabel(?string $side): ?string
    {
        return match ($side) {
            'buy' => 'of buy',
            'sell' => 'of sell',
            'split' => 'of split',
            default => null,
        };
    }

    /**
     * Batch-resolve SDE display names for a set of pricing rules,
     * grouped by rule type to avoid N+1 lookups.
     *
     * @return array{item: array, group: array, category: array}
     */
    private function resolveRuleNames($rules): array
    {
        $byType = ['item' => [], 'group' => [], 'category' => []];
        foreach ($rules as $rule) {
            if (isset($byType[$rule->type])) {
                $byType[$rule->type][] = $rule->type_id;
            }
        }

        $names = ['item' => [], 'group' => [], 'category' => []];
        if ($byType['item']) {
            $names['item'] = InvType::whereIn('typeID', $byType['item'])->pluck('typeName', 'typeID')->all();
        }
        if ($byType['group']) {
            $names['group'] = InvGroup::whereIn('groupID', $byType['group'])->pluck('groupName', 'groupID')->all();
        }
        if ($byType['category']) {
            $names['category'] = InvCategory::whereIn('categoryID', $byType['category'])->pluck('categoryName', 'categoryID')->all();
        }

        return $names;
    }
}
