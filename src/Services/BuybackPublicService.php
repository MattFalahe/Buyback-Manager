<?php

namespace BuybackManager\Services;

use BuybackManager\Models\BuybackSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Backing service for the public buyback landing page.
 *
 * Resolves a corp's public page by ticker and stores/deletes the uploaded
 * background + logo images. Image handling mirrors HR Manager's hero-image
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
     * Resolve the published public page for a corp ticker, or null when no
     * such corp exists or its public page is not enabled. EVE tickers are
     * unique per corporation, so ticker -> corp is 1:1.
     */
    public function resolveByTicker(string $ticker, bool $requireEnabled = true): ?BuybackSetting
    {
        $ticker = trim($ticker);
        if ($ticker === '') {
            return null;
        }

        $corpId = DB::table('corporation_infos')->where('ticker', $ticker)->value('corporation_id');
        if (! $corpId) {
            return null;
        }

        return BuybackSetting::with('corporation')
            ->where('corporation_id', $corpId)
            ->when($requireEnabled, fn ($q) => $q->where('public_page_enabled', true)->where('enabled', true))
            ->first();
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
}
