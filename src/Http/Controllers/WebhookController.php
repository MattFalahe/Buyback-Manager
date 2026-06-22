<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Models\BuybackNotificationLog;
use BuybackManager\Models\BuybackWebhook;
use BuybackManager\Services\DiscordRoleResolver;
use BuybackManager\Services\WebhookDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Web\Http\Controllers\Controller;

/**
 * Admin-only CRUD for buyback webhooks.
 *
 *   GET    /settings/webhooks          list + add form
 *   POST   /settings/webhooks          create or update
 *   DELETE /settings/webhooks/{id}     remove
 *   POST   /settings/webhooks/{id}/test  send a synthetic test event
 *                                        through WebhookDispatcher
 *
 * Webhooks are corp-scoped via corporation_id (null = global, fires
 * for every corp's events). Categories chosen as checkboxes map to
 * the buyback.* events through WebhookDispatcher's EVENT_TO_CATEGORY.
 */
class WebhookController extends Controller
{
    protected WebhookDispatcher $dispatcher;

    public function __construct(WebhookDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function index()
    {
        // Webhook management is embedded in the main settings page's
        // Discord Webhooks tab. This route is kept for old bookmarks /
        // links and simply redirects there.
        return redirect()->to(self::settingsWebhooksUrl());
    }

    /**
     * URL of the inline webhooks tab on the settings page. Form actions
     * redirect here so the operator lands back on the right tab instead
     * of a separate "hidden" page.
     */
    protected static function settingsWebhooksUrl(): string
    {
        return route('buyback-manager.settings.index') . '#webhooks';
    }

    /**
     * JSON endpoint for the role-picker modal in settings/webhooks.
     */
    public function listRoles()
    {
        return response()->json([
            'provider'  => DiscordRoleResolver::detectProvider(),
            'label'     => DiscordRoleResolver::providerLabel(),
            'available' => DiscordRoleResolver::isAvailable(),
            'roles'     => DiscordRoleResolver::listRoles(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|integer|exists:buyback_webhooks,id',
            'corporation_id' => 'nullable|integer|exists:corporation_infos,corporation_id',
            'name' => 'required|string|max:100',
            'url' => 'required|url|max:500',
            'enabled' => 'boolean',
            'role_mention' => 'nullable|string|max:50',
            'categories' => 'required|array|min:1',
            'categories.*' => 'in:' . implode(',', BuybackWebhook::ALL_CATEGORIES),
        ]);

        $validated['enabled'] = $request->has('enabled');
        $validated['corporation_id'] = $validated['corporation_id'] ?: null;

        // Discord URLs only. Webhooks pointing elsewhere are a footgun
        // (no shared secret, no signature). Cheap guard:
        if (! str_starts_with($validated['url'], 'https://discord.com/api/webhooks/')
            && ! str_starts_with($validated['url'], 'https://discordapp.com/api/webhooks/')) {
            return redirect()->to(self::settingsWebhooksUrl())
                ->withInput()
                ->with('error', 'Webhook URL must be a Discord webhook (https://discord.com/api/webhooks/...).');
        }

        // Auto-wrap bare numeric role IDs as Discord role mentions so
        // operators who paste just the snowflake (the most common case
        // when copy-from-Developer-Mode) get a working mention instead
        // of plain text "1285075838924099678" in the channel.
        // Already-wrapped values (<@&id> or <@id>) pass through verbatim.
        if (! empty($validated['role_mention'])) {
            $rm = trim($validated['role_mention']);
            if (preg_match('/^\d{15,25}$/', $rm)) {
                $rm = '<@&' . $rm . '>';
            }
            $validated['role_mention'] = $rm;
        }

        if (! empty($validated['id'])) {
            $hook = BuybackWebhook::findOrFail($validated['id']);
            unset($validated['id']);
            $hook->update($validated);
            $msg = 'Webhook updated';
        } else {
            BuybackWebhook::create($validated);
            $msg = 'Webhook added';
        }

        return redirect()->to(self::settingsWebhooksUrl())->with('success', $msg);
    }

    public function destroy(int $id)
    {
        $hook = BuybackWebhook::findOrFail($id);
        $hook->delete();

        return redirect()->to(self::settingsWebhooksUrl())
            ->with('success', 'Webhook removed');
    }

    /**
     * Send a synthetic test event through this webhook. Bypasses the
     * dedup ledger (test-fires are always allowed) so the operator
     * can re-test as often as needed.
     *
     * Redirects back() so a test-fire triggered from the diagnostic
     * page's Notification Testing tab returns to that tab, and one
     * triggered from the webhooks index returns to the index.
     */
    public function testFire(Request $request, int $id)
    {
        // The inline settings panel sends return_to=settings_webhooks so
        // we bounce back to that tab; the diagnostic page sends nothing
        // and gets the default back() to wherever it submitted from.
        $redirect = fn($with) => $request->input('return_to') === 'settings_webhooks'
            ? redirect()->to(self::settingsWebhooksUrl())->with($with[0], $with[1])
            : back()->with($with[0], $with[1]);

        $hook = BuybackWebhook::findOrFail($id);
        if (! $hook->enabled) {
            return $redirect(['error', 'Webhook is disabled. Enable before test-firing.']);
        }

        // Build a synthetic envelope that looks like a real
        // buyback.offer.published event so the dispatcher's embed
        // builder produces a recognisable preview.
        $synthetic = [
            'source_plugin' => 'buyback-manager',
            'schema_version' => 1,
            'event_id' => 'bb-test-' . \Illuminate\Support\Str::uuid()->toString(),
            'corporation_id' => $hook->corporation_id ?? 0,
            'offer_public_id' => 'bb-TEST123',
            'mode' => 'public',
            'status' => 'pending',
            'total_market_value' => 100000000.00,
            'total_buyback_value' => 90000000.00,
            'average_percentage' => 90.0,
            'market' => 'jita',
            'provider' => 'fuzzwork',
            'expires_at' => now()->addHours(24)->toIso8601String(),
            'items_count' => 3,
            'url' => route('buyback-manager.settings.webhooks.index'),
        ];

        // Drop the dedup row if one already exists for this exact
        // synthetic payload — keeps test-fires re-runnable.
        $payloadHash = BuybackNotificationLog::computeHash('buyback.offer.published', $synthetic);
        BuybackNotificationLog::where('webhook_id', $hook->id)
            ->where('payload_hash', $payloadHash)
            ->delete();

        try {
            $this->dispatcher->dispatch('buyback.offer.published', $synthetic);
            return $redirect(['success', 'Test event dispatched. Check the Recent dispatches log for the result.']);
        } catch (\Throwable $e) {
            return $redirect(['error', 'Test dispatch threw: ' . $e->getMessage()]);
        }
    }
}
