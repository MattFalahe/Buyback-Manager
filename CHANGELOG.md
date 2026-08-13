# Changelog

All notable changes to Buyback Manager will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - Initial Release

The first public release of Buyback Manager. It turns a corporation buyback programme into an appraise-then-contract workflow that needs no login from the person selling: they appraise their items, get a single-use key, and paste it into an in-game contract. Buyback Manager detects the contract, resolves the key back to the appraisal, compares the two, and flags anything that does not line up for a director to review. The plugin runs standalone on free Fuzzwork pricing or a Janice key, and treats Manager Core as an optional upgrade for regional pricing and a cross-plugin event feed rather than a dependency.

### Added

**Appraise-then-contract workflow**
- Appraisal tool that values a pasted item list against a corporation's provider and pricing rules, returning both buy and sell prices per item.
- Every appraisal is stored and returns a short single-use key (for example `bb-zj2cc262`) plus a public, shareable appraisal page with the full breakdown, the key to copy, the contract instructions, and the accepted locations.
- No account, no ESI scopes, and no permission are needed to sell. Signing in is optional and only adds history under My Appraisals.
- Items a programme does not buy are reported back to the seller rather than silently dropped, with a prompt to ask for a custom quote.
- Two-tier retention: bulky line-item rows are pruned early while appraisal totals are kept for months of statistics. Housekeeping runs inside the sync cycle, so no extra cron is needed.

**Contract detection and review**
- Three contract-target modes that mirror EVE's contract visibility: My Corporation (whole-corp feed), Specific Corporation (named corp's feed), and Specific Player (the designated character's personal feed).
- Detection by appraisal key embedded in the contract Description. Contracts without a valid key are ignored, keeping unrelated and deleted contracts out of the list.
- Every matched contract is compared against its appraisal and flagged when the asked price drifts past the corporation's tolerance (with the direction reported), the quote was already stale, the items do not match, the key had been reused, or the contract was created outside the accepted locations.
- Unmatched-attempt review signal when a contract quotes a key that does not resolve, logged for the operator rather than silently dropped.
- Status-transition tracking that records completion and cancellation of buyback contracts.
- Idle-contract reminder when a matched contract is left unaccepted past the corporation's auto-nudge window (0 disables).
- CSV export of the filtered contracts list, including asked price, deviation and review flags, honouring the same per-user corporation visibility as the on-screen list.

**Pricing**
- Three providers: Fuzzwork (free, The Forge), Janice (API key, regional markets, with a raw appraisal endpoint for large lists), and Manager Core (optional).
- Both-sides pricing so a rule can apply its percentage to the buy price, the sell price, or the midpoint.
- Three-layer resilience: the configured market, then an optional Jita fallback, then the most recent locally cached price.
- Local price cache with a per-corp TTL for Fuzzwork and Janice. Manager Core bypasses the local cache and uses its own.

**Pricing rules**
- A single Pricing Rules page holding the default rate, the price exceptions and the buyback exclusions, with new rules filed into the block they belong to.
- Per-item, per-group, and per-category rules with item > group > category > default precedence.
- Per-rule price side (buy, sell, split) and the ability to exclude an item from buyback entirely.
- Two programme modes: buy everything at the default rate, or buy only the items listed as price exceptions, with everything else reported to the seller as not accepted.

**Location restrictions**
- Optionally restrict buyback to specific regions, constellations, systems, stations, or citadels, mixed freely (a region covers everything inside it). An empty list accepts any location.
- Contracts created outside the accepted locations are tracked and flagged for review rather than dropped, and the accepted locations are shown on the public page and every appraisal page. A searchable picker resolves names from the SDE.

**Discord notifications**
- Per-corporation or global webhooks with six subscribable categories (Contract Matched, Contract Flagged, Contract Unmatched, Contract Completed, Contract Cancelled, Contract Nudge).
- Flagged contracts announce the quoted value, the asked price, the percentage difference and the reasons in one message.
- Role mentions via a picker that reads detected Discord roles from the SeAT Discord integration.
- A Notification Routing Map showing which webhook announces which category.
- De-duplicated, per-webhook rate-limited delivery, and a contract's first sighting fires exactly one of matched, flagged or unmatched.

**Manager Core integration (optional)**
- Automatic detection of Manager Core with a clean standalone fallback when it is absent.
- Regional market pricing and shared cache through Manager Core's pricing service.
- Registration in Manager Core's pricing preferences so an admin can override the market centrally.
- EventBus publishing of `buyback.contract.matched / flagged / unmatched / completed / cancelled / nudge` for other plugins to consume.

**Public landing page**
- An optional per-corporation public page at `/buyback/{ticker}` that advertises rates, runs the no-login appraisal, issues keys, and shows config-driven contract instructions.
- Brandable per corp: uploaded background and logo (dark box, no box, or white square), accent colour, dim overlay, headline, blurb, footer, and a stacked or side-by-side layout. Images stream from the app origin, so they need no `storage:link` and satisfy a strict content-security policy.
- Configurable rate display: a "most wanted" flag for featured items, an option to list every non-excluded rule, and an option to show the sourced market and each rule's price side.

**Diagnostics**
- An admin-only Diagnostic page (not in the sidebar) with Health Checks, Master Test, System Validation, Settings Health, Data Integrity, Contract Trace, and Notification Testing tabs.
- A Contract Trace tab that walks a single contract through the pricing and matching pipeline.
- A Sync Now action to run a detection pass on demand.

**Help & operations**
- A Help & Documentation page in the sidebar covering the full workflow, pricing, rules, locations, detection modes, notifications, Manager Core, the public page, custom CSS styling, permissions, commands & configuration, and troubleshooting.
- One scheduled job registered automatically: `buyback-manager:sync-contracts` (every 15 minutes), which also handles housekeeping.
- Two permissions: `buyback-manager.view` and `buyback-manager.settings`.

[1.0.0]: https://github.com/MattFalahe/Buyback-Manager/releases/tag/1.0.0
