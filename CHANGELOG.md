# Changelog

All notable changes to Buyback Manager will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - Initial Release (2026-06-10)

The first public release of Buyback Manager. It turns a corporation buyback programme into a quote-then-contract workflow: a member appraises their items, publishes a frozen-price offer, and creates an in-game contract carrying the offer id. Buyback Manager detects the contract, pairs it to the offer at its locked value, and announces the result. The plugin runs standalone on free Fuzzwork pricing or a Janice key, and treats Manager Core as an optional upgrade for regional pricing and a cross-plugin event feed rather than a dependency.

### Added

**Offer workflow**
- Appraisal tool that values a pasted item list against a corporation's provider and pricing rules, returning both buy and sell prices per item.
- Publish-as-offer flow that freezes the valuation, assigns a short public offer id (for example `bb-zj2cc262`), and sets an expiry from a configurable lock window.
- Offer lifecycle with pending, matched, expired, cancelled, and rejected states, plus a My Offers page for members to track their own.
- Account-aware matching: a contract may be issued by any character on the same SeAT account as the member who published the offer, so a member can quote with their main and contract from an alt.

**Contract detection**
- Three contract-target modes that mirror EVE's contract visibility: My Corporation (whole-corp feed), Specific Corporation (named corp's feed), and Specific Player (the designated character's personal feed).
- Detection by offer id embedded in the contract Description. Contracts without a valid, claimable offer id are ignored, keeping unrelated and deleted contracts out of the list.
- Unmatched-attempt review signal when a contract references an id that does not resolve, logged for the operator rather than silently dropped.
- Status-transition tracking that records completion, cancellation, and rejection of buyback contracts.
- CSV export of the filtered contracts list, honouring the same per-user corporation visibility as the on-screen list.

**Pricing**
- Three providers: Fuzzwork (free, The Forge), Janice (API key, regional markets, with a raw appraisal endpoint for large lists), and Manager Core (optional).
- Both-sides pricing so a rule can apply its percentage to the buy price, the sell price, or the midpoint.
- Three-layer resilience: the configured market, then an optional Jita fallback, then the most recent locally cached price.
- Local price cache with a per-corp TTL for Fuzzwork and Janice. Manager Core bypasses the local cache and uses its own.

**Pricing rules**
- Per-item, per-group, and per-category rules with item > group > category > base precedence.
- Per-rule price side (buy, sell, split) and the ability to exclude an item from buyback entirely.

**Discord notifications**
- Per-corporation or global webhooks with six subscribable categories (Offer Published, Offer Matched, Offer Rejected, Contract Unmatched, Contract Completed, Contract Cancelled).
- Role mentions via a picker that reads detected Discord roles from the SeAT Discord integration.
- A Notification Routing Map showing which webhook announces which category.
- De-duplicated, per-webhook rate-limited delivery so a single event never double-pings.

**Manager Core integration (optional)**
- Automatic detection of Manager Core with a clean standalone fallback when it is absent.
- Regional market pricing and shared cache through Manager Core's pricing service.
- Registration in Manager Core's pricing preferences so an admin can override the market centrally.
- EventBus publishing of the offer and contract event catalog for other plugins to consume.

**Public landing page**
- An optional per-corporation public page at `/buyback/{ticker}` that advertises rates, shows config-driven contract instructions, and funnels members into the appraisal via EVE SSO. No login required to view.
- Brandable per corp: uploaded background and logo (with an optional solid square backdrop behind the logo), accent colour, dim overlay, headline, blurb, and footer. Images stream from the app origin, so they need no `storage:link` and satisfy CSP on Docker and bare-metal alike.
- Configurable rate display: a "most wanted" flag to spotlight featured items, an option to list every non-excluded pricing rule rather than only the featured ones, and an option to show the sourced market and each rule's price side (buy / sell / split).

**Diagnostics**
- An admin-only Diagnostic page (not in the sidebar) with Health Checks, Master Test, System Validation, Settings Health, Data Integrity, Contract Trace, and Notification Testing tabs.
- A Contract Trace tab that walks a single contract through the pricing and matching pipeline.
- A Sync Now action to run a detection pass on demand.

**Help & operations**
- A Help & Documentation page in the sidebar covering the full workflow, pricing, rules, detection modes, notifications, Manager Core, the public page, custom CSS styling, permissions, and troubleshooting.
- Two scheduled jobs registered automatically: `buyback-manager:sync-contracts` (every 15 minutes) and `buyback-manager:expire-offers` (every 5 minutes).
- Two permissions: `buyback-manager.view` and `buyback-manager.settings`.

[1.0.0]: https://github.com/MattFalahe/Buyback-Manager/releases/tag/1.0.0
