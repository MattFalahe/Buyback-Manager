<?php

namespace BuybackManager\Http\Controllers;

use BuybackManager\Models\BuybackOffer;
use BuybackManager\Models\BuybackSetting;
use BuybackManager\Services\OfferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;

/**
 * Member-facing offer endpoints.
 *
 *   GET  /offers                      issuer's own offers (paginated)
 *   GET  /offers/{public_id}          canonical detail page (corp-scoped
 *                                     for public mode, designated-only
 *                                     for private mode)
 *   POST /offers                      publish (re-appraises live and
 *                                     freezes the result into an offer)
 *   POST /offers/{public_id}/cancel   issuer-only, while pending
 *   POST /offers/{public_id}/reject   designated character (or admin)
 *                                     captures the why behind a matched
 *                                     offer's EVE-side rejection
 *
 * No permission middleware on publish/list — any authenticated SeAT
 * user can do these, matching the existing Appraisal page policy.
 * Access to detail pages is gated per offer via authoriseViewing().
 */
class OfferController extends Controller
{
    protected OfferService $offerService;

    public function __construct(OfferService $offerService)
    {
        $this->offerService = $offerService;
    }

    /**
     * Member's offer list — defaults to "issued by my characters."
     * Admins (buyback-manager.settings) see all corp offers via
     * ?scope=corp.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user && $user->can('buyback-manager.settings');
        $scope = (string) $request->get('scope', 'mine');

        $query = BuybackOffer::with(['corporation', 'issuer', 'targetCharacter', 'targetCorporation'])
            ->orderBy('created_at', 'desc');

        if ($scope === 'corp' && $isAdmin) {
            // Admin view — all offers across all corps.
        } else {
            $myCharacterIds = $this->myCharacterIds($user);
            if (empty($myCharacterIds)) {
                $offers = collect()->paginate(20);
                return view('buyback-manager::offers.index', compact('offers', 'scope', 'isAdmin'));
            }
            $query->whereIn('issuer_character_id', $myCharacterIds);
        }

        $offers = $query->paginate(20)->withQueryString();
        return view('buyback-manager::offers.index', compact('offers', 'scope', 'isAdmin'));
    }

    /**
     * Canonical offer detail page. URL is the shareable artefact —
     * members paste this in Discord / corp chat.
     */
    public function show(Request $request, string $publicId)
    {
        $offer = BuybackOffer::with(['items', 'corporation', 'issuer', 'targetCharacter', 'targetCorporation', 'rejectedBy', 'linkedContract'])
            ->where('public_id', $publicId)
            ->firstOrFail();

        $this->authoriseViewing($request, $offer);

        $setting = BuybackSetting::where('corporation_id', $offer->corporation_id)->first();

        return view('buyback-manager::offers.show', compact('offer', 'setting'));
    }

    /**
     * Publish a new offer from the appraisal raw input. Re-appraises
     * live, freezes the result, persists, fires events.
     */
    public function publish(Request $request)
    {
        $validated = $request->validate([
            'corporation_id' => 'required|integer|exists:corporation_infos,corporation_id',
            'items' => 'required|string|min:3',
        ]);

        $user = $request->user();
        $issuerCharacterId = $this->resolveIssuerCharacterId($user, $validated['corporation_id']);
        if ($issuerCharacterId === null) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Could not determine your issuer character. Add a refresh token for a character in this corporation.');
        }

        $result = $this->offerService->publishFromAppraisal(
            $validated['items'],
            (int) $validated['corporation_id'],
            $issuerCharacterId
        );

        if (! $result['success']) {
            return redirect()->back()
                ->withInput()
                ->with('error', $result['message'] ?? 'Could not publish offer');
        }

        return redirect()
            ->route('buyback-manager.offers.show', $result['offer']->public_id)
            ->with('success', 'Offer published — share the URL with your buyback director.');
    }

    /**
     * Issuer (or admin) cancels a pending offer.
     */
    public function cancel(Request $request, string $publicId)
    {
        $offer = BuybackOffer::where('public_id', $publicId)->firstOrFail();
        $user = $request->user();
        $isAdmin = $user && $user->can('buyback-manager.settings');

        $byCharacterId = null;
        if ($isAdmin) {
            $byCharacterId = $this->primaryCharacterId($user) ?? 0;
        } else {
            $byCharacterId = $this->resolveCharacterIdMatching($user, $offer->issuer_character_id);
        }

        if ($byCharacterId === null) {
            abort(403, 'Only the offer issuer or an admin can cancel.');
        }

        $ok = $this->offerService->cancel($offer, $byCharacterId, $isAdmin);
        if (! $ok) {
            return redirect()->route('buyback-manager.offers.show', $publicId)
                ->with('error', 'Offer cannot be cancelled (already matched, expired, or cancelled).');
        }

        return redirect()->route('buyback-manager.offers.show', $publicId)
            ->with('success', 'Offer cancelled.');
    }

    /**
     * Designated character (or admin) records the rejection reason
     * after rejecting the linked contract in-game.
     */
    public function reject(Request $request, string $publicId)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $offer = BuybackOffer::where('public_id', $publicId)->firstOrFail();
        $user = $request->user();
        $isAdmin = $user && $user->can('buyback-manager.settings');

        $byCharacterId = null;
        if ($isAdmin) {
            $byCharacterId = $this->primaryCharacterId($user) ?? 0;
        } elseif ($offer->target_character_id !== null) {
            $byCharacterId = $this->resolveCharacterIdMatching($user, $offer->target_character_id);
        }

        if ($byCharacterId === null) {
            abort(403, 'Only the designated buyback operator or an admin can reject an offer.');
        }

        $ok = $this->offerService->reject($offer, (int) $byCharacterId, $validated['reason'] ?? null);
        if (! $ok) {
            return redirect()->route('buyback-manager.offers.show', $publicId)
                ->with('error', 'Offer cannot be rejected (not in matched state).');
        }

        return redirect()->route('buyback-manager.offers.show', $publicId)
            ->with('success', 'Rejection recorded.');
    }

    // ------------------------------------------------------------
    // Authorisation helpers
    // ------------------------------------------------------------

    /**
     * Detail-page visibility rules:
     *   admin (buyback-manager.settings)        → always
     *   issuer (any of their character ids)     → always
     *   private mode + target character match   → always
     *   public mode + a character in the corp   → always
     *   otherwise                               → 403
     */
    protected function authoriseViewing(Request $request, BuybackOffer $offer): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }
        if ($user->can('buyback-manager.settings')) {
            return;
        }

        $myCharacters = $this->myCharacterIds($user);
        $myCorps = $this->myCorpIds($user);

        // Issuer always sees their own.
        if (in_array((int) $offer->issuer_character_id, $myCharacters, true)) {
            return;
        }

        if ($offer->isPrivate()) {
            if ($offer->target_character_id !== null
                && in_array((int) $offer->target_character_id, $myCharacters, true)) {
                return;
            }
            abort(403, 'This offer is private to its designated operator.');
        }

        // Public mode — any character in the offer's corp can see it.
        if (in_array((int) $offer->corporation_id, $myCorps, true)) {
            return;
        }

        abort(403, 'You do not have access to this offer.');
    }

    // ------------------------------------------------------------
    // Character-id resolution helpers
    // ------------------------------------------------------------

    protected function myCharacterIds($user): array
    {
        if ($user === null) {
            return [];
        }
        return DB::table('refresh_tokens')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->pluck('character_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    protected function myCorpIds($user): array
    {
        if ($user === null) {
            return [];
        }
        return DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', $user->id)
            ->whereNull('refresh_tokens.deleted_at')
            ->pluck('character_affiliations.corporation_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    protected function primaryCharacterId($user): ?int
    {
        if ($user === null) {
            return null;
        }
        $id = DB::table('refresh_tokens')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('character_id');
        return $id !== null ? (int) $id : null;
    }

    protected function resolveCharacterIdMatching($user, ?int $targetCharacterId): ?int
    {
        if ($targetCharacterId === null || $user === null) {
            return null;
        }
        $mine = $this->myCharacterIds($user);
        return in_array($targetCharacterId, $mine, true) ? $targetCharacterId : null;
    }

    /**
     * For publishing: pick which of the user's characters issues the
     * offer. Resolution order:
     *
     *   1. The user's main character (users.main_character_id), if
     *      it's in the target corp. Honours the SeAT user's chosen
     *      identity and produces an issuer_character_id that will
     *      match the EVE contract the user is most likely to create.
     *   2. Any other character of theirs that's in the target corp,
     *      lowest refresh_token id first (deterministic tie-break).
     *   3. Their primary character regardless of corp (fallback so
     *      publish never silently fails when the user has no character
     *      in the target corp — admin / pre-recruit workflows).
     */
    protected function resolveIssuerCharacterId($user, int $corporationId): ?int
    {
        if ($user === null) {
            return null;
        }

        // Step 1: prefer main character when it's a member of the target corp.
        // All refresh_tokens columns in joined queries must be table-
        // qualified because character_affiliations also has a character_id
        // column (and Laravel doesn't auto-prefix WHERE clauses on the
        // base table when a join is present).
        $mainId = $user->main_character_id ?? null;
        if ($mainId) {
            $mainInCorp = DB::table('refresh_tokens')
                ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
                ->where('refresh_tokens.user_id', $user->id)
                ->where('refresh_tokens.character_id', $mainId)
                ->whereNull('refresh_tokens.deleted_at')
                ->where('character_affiliations.corporation_id', $corporationId)
                ->select('refresh_tokens.character_id')
                ->first();
            if ($mainInCorp !== null) {
                return (int) $mainInCorp->character_id;
            }
        }

        // Step 2: any other character of theirs in the corp.
        $row = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', $user->id)
            ->whereNull('refresh_tokens.deleted_at')
            ->where('character_affiliations.corporation_id', $corporationId)
            ->select('refresh_tokens.character_id')
            ->orderBy('refresh_tokens.id')
            ->first();
        if ($row !== null) {
            return (int) $row->character_id;
        }

        // Step 3: fallback to primary character.
        return $this->primaryCharacterId($user);
    }
}
