# Changelog

All notable changes to Buyback Manager will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Ran a development build before 1.0.0? Clean up after it

Every build before 1.0.0 kept its data in tables named `buyback_*`. 1.0.0 uses tables named `buyback_manager_*` and a new set of migrations, so it installs clean next to anything an earlier build left behind, and nothing has to be reset first. The old tables just sit there unused until you run the upgrade command, which carries your programme over if you want it and removes them. Diagnostics shows a warning while they are there. A fresh install needs none of this.

1. **Back up your database**, or at least every table whose name starts with `buyback_`.
2. **Move to a release.** In SeAT Docker's `.env`, `SEAT_PLUGINS` must name the package alone, `mattfalahe/buyback-manager`, with no branch after it. A development branch pulls unfinished work at every restart, and the command refuses to run until you change it. Then restart SeAT (down, then up). The restart installs 1.0.0 and runs its migrations next to the old tables. It also updates every plugin and library that isn't pinned to an exact version, as any restart does. Without Docker, run `composer require mattfalahe/buyback-manager` and `php artisan migrate`.
3. **Run the upgrade command**, before you save anything in Buyback's Settings:

   ```
   docker exec -it seat-docker-front-1 php artisan buyback-manager:upgrade-dev-build
   ```

   It lists what the old build left and asks what to do with your programme:

   - **Carry it over** copies the settings, pricing and location rules, appraisals with their keys, contracts, price cache, webhooks, notification log and Programme Access into the new tables, ids included, so the keys sellers already hold keep working and the public page keeps its images. It needs a build that already had appraisal keys (it has a `buyback_appraisals` table) and a 1.0.0 with no programme saved yet. Where an old build held two pricing rules for the same item, the first is kept. A Janice key or webhook URL stored as typed is encrypted on the way.
   - **Start fresh** copies nothing. Set the programme up again in Settings (provider, rates, rules, locations, webhooks, Programme Access and the public page) and upload the public page images again, since they're still on disk but no longer linked. Appraisal keys sellers already hold stop working, so ask them to appraise again. An older, offer-based build can only start fresh.

   Either way it then drops the old tables, deletes their migration records and removes the schedule row of `buyback-manager:expire-offers`, a command that no longer exists. Copying is all or nothing: if it fails, nothing is copied and nothing is removed. `--carry-over` or `--fresh` picks without asking, and `--force` skips the confirmation. Run without `-it`, it asks nothing, so give it both.

> ⚠️ **Have, or had, seat-buyback installed?** Its tables also start with `buyback_`, and its `buyback_contracts` has the same name as the contracts table of Buyback Manager's development builds. The command tells the two apart by seat-buyback's `contractId` column and leaves seat-buyback's tables alone.
>
> If SeAT stopped starting after a development build went in next to seat-buyback's tables (a migration failing because `buyback_contracts` already exists), back up and do step 2. 1.0.0 never touches that table, so SeAT starts again. A build that never finished migrating has no programme to carry over, so the command starts fresh.

<details>
<summary>Exactly what the command removes</summary>

Every table a development build ever made, where it exists: `buyback_settings`, `buyback_pricing_rules`, `buyback_location_rules`, `buyback_appraisals`, `buyback_appraisal_items`, `buyback_contracts`, `buyback_contract_items`, `buyback_price_cache`, `buyback_price_status`, `buyback_sync_status`, `buyback_subscribed_types`, `buyback_webhooks`, `buyback_notification_log`, `buyback_packaged_volumes`, `buyback_programme_access`, `buyback_offers`, `buyback_offer_items` and `buyback_prices`. `buyback_contracts` only when it is a development build's. It never touches a `buyback_manager_*` table, or HR Manager's `hr_manager_buyback_*` tables.

The migration records of every file name a development build ever used, named one by one so no other plugin's record is touched:

```
2026_01_01_000001_create_buyback_settings_table
2026_01_01_000002_create_buyback_pricing_rules_table
2026_01_01_000003_create_buyback_contracts_table
2026_01_01_000004_create_buyback_contract_items_table
2026_01_01_000005_create_buyback_prices_table
2026_01_01_000005_remove_local_pricing_system
2026_01_01_000006_add_manager_core_integration
2026_01_01_000006_add_provider_columns_to_buyback_settings
2026_01_01_000007_create_buyback_price_cache_table
2026_01_01_000007_remove_local_pricing_system
2026_01_01_000008_create_buyback_subscribed_types_table
2026_01_01_000009_create_buyback_offers_table
2026_01_01_000010_create_buyback_offer_items_table
2026_01_01_000011_create_buyback_webhooks_table
2026_01_01_000012_create_buyback_notification_log_table
2026_01_01_000013_add_mode_columns_to_buyback_settings
2026_01_01_000014_add_price_side_to_buyback_pricing_rules
2026_01_01_000015_cleanup_renamed_migration_entries
2026_01_01_000016_add_cache_ttl_to_buyback_settings
2026_01_01_000017_add_contract_target_fields
2026_01_01_000018_add_offer_link_to_buyback_contracts
2026_01_01_000019_add_public_page_fields
2026_01_01_000020_add_public_logo_bg
2026_01_01_000021_add_public_rate_detail_flags
2026_01_01_000022_create_buyback_location_rules_table
2026_01_01_000023_add_public_layout
2026_01_01_000024_add_public_logo_style
2026_01_01_000025_add_nudged_at_to_buyback_contracts
2026_01_08_000001_add_manager_core_integration
2026_01_09_000001_remove_local_pricing_system
2026_02_01_000000_reset_legacy_buyback_schema
2026_02_01_000001_create_buyback_settings_table
2026_02_01_000002_create_buyback_pricing_rules_table
2026_02_01_000003_create_buyback_location_rules_table
2026_02_01_000004_create_buyback_appraisals_table
2026_02_01_000005_create_buyback_appraisal_items_table
2026_02_01_000006_create_buyback_contracts_table
2026_02_01_000007_create_buyback_contract_items_table
2026_02_01_000008_create_buyback_price_cache_table
2026_02_01_000009_create_buyback_subscribed_types_table
2026_02_01_000010_create_buyback_webhooks_table
2026_02_01_000011_create_buyback_notification_log_table
2026_02_01_000012_create_buyback_price_status_table
2026_02_01_000012_update_buyback_pricing_chain
2026_02_01_000013_create_buyback_sync_status_table
2026_02_01_000014_create_buyback_packaged_volumes_table
2026_02_01_000015_create_buyback_programme_access_table
2026_02_01_000016_add_buyback_public_closed_columns
```

And any schedule row for a `buyback-manager:` command this install doesn't have.

</details>

Manager Core needs nothing. Its pricing preference and item subscriptions for Buyback are kept, and Buyback carries on with them.

## [1.0.0] - 2026-11-25 - The Ecosystem Era: Fair Trade

The first public release of Buyback Manager. It turns a corporation buyback programme into an appraise-then-contract workflow that needs no login from the person selling: they appraise their items, get a single-use key, and paste it into an in-game contract. Buyback Manager detects the contract, resolves the key back to the appraisal, compares the two, and flags anything that does not line up for a director to review. The plugin runs standalone on free Fuzzwork pricing or a Janice key, and treats Manager Core as an optional price provider and cross-plugin event feed rather than a dependency.

### Added

**Appraise-then-contract workflow**
- Appraisal tool that values a pasted item list against a corporation's provider and pricing rules, returning both buy and sell prices per item.
- One paste reader for every provider: inventory, assets and contract copies, one item per line (`1,000 x Tritanium`, `1000 Tritanium`, `Tritanium 1000`, `Tritanium x1000`), and EFT fits without their loaded charges. The same item on several lines is added up, and lines that name no item are listed back to the seller.
- Every appraisal is stored and returns a short single-use key (for example `bb-zj2cc262`) plus a public, shareable appraisal page with the full breakdown, the key to copy, the contract instructions, and the accepted locations.
- No account, no ESI scopes, and no permission are needed to sell. Signing in is optional and only adds history under My Appraisals.
- Items a programme does not buy, and items nothing could price, are reported back to the seller as not accepted rather than silently dropped or valued at zero, with a prompt to ask for a custom quote on items the programme does not buy.
- Two-tier retention: bulky line-item rows are pruned early while appraisal totals are kept for months of statistics. Housekeeping runs inside the sync cycle, so no extra cron is needed.

**Contract detection and review**
- Three contract-target modes that mirror EVE's contract visibility: My Corporation (whole-corp feed), Specific Corporation (named corp's feed), and Specific Player (the designated character's personal feed).
- Each contract keeps the target it was sent to. Changing a programme's target applies to new contracts; one already open is still followed, posted to Discord and credited in HR Manager as where it actually went.
- Detection by appraisal key in the contract Description. A contract with no key is ignored, keeping unrelated contracts out of the list.
- Every matched contract is compared against its appraisal and flagged when the asked price drifts past the corporation's tolerance (with the direction reported), the quote was already stale, the items do not match, the key had been reused, or the contract was created outside the accepted locations.
- A contract quoting a key that matches no appraisal of the corporation is listed for review under "Key matched nothing", with no quote, and kept out of every total.
- Completion, cancellation and expiry tracking. A contract that already finished when the sync first sees it is announced in the same pass. One left to expire is announced once its expiry is an hour behind, and is still read for a week after, so a late update from SeAT still lands.
- Idle-contract reminder when a matched contract is left unaccepted past the corporation's auto-nudge window (0 disables). A contract past its expiry is never nudged.
- The Contracts list shows when each programme's contracts were last read, and filters for contracts awaiting acceptance, needing review, whose key matched nothing, and expired.
- CSV export of the filtered contracts list, including asked price, deviation and review flags, honouring the same per-user corporation visibility as the on-screen list.

**Pricing**
- Three providers: Fuzzwork (free, at Jita, Amarr, Dodixie, Hek or Rens, each at its main station, or at any region), Janice (API key, at the same five hubs), and Manager Core (offered only when it is installed, at any market added there, citadels included).
- Both-sides pricing so a rule can apply its percentage to the buy price, the sell price, or the midpoint.
- Each price comes from the provider, then from a fallback provider when the provider fails outright or cannot answer for an item, then from the last cached price. The fallback prices at the same market, or at Jita when it cannot price there.
- An optional Jita fill: at another market, a side with no orders takes Jita's price.
- Local price cache per market for Fuzzwork and Janice answers, reused for a per-corp TTL. Manager Core keeps its own prices and is read on every quote.
- An item nothing can price is listed as not accepted with "no market price", and the quote notes where its prices came from when they did not all come from the provider.
- A thin-market guard per corporation (10% by default, 0 turns it off). When the best buy order stands more than that above Fuzzwork's average of the top 5% of buy orders, the item is priced at the average instead, marked "thin market" on the quote, and counted in the quote's note. Only lines paid on the buy side or the midpoint change. Only Fuzzwork prices are guarded, asked directly or through Manager Core: the figures Janice and Goonpraisal send under that name can sit far under the market, so their prices stand at the best buy order.
- Hamsters on the appraisal page and the public page while a quote is priced: one of four scenes, picked at random each time, up for at least three seconds with a line that changes every few. Another one beside the paste box waits for loot and counts the lines as they are pasted, also one of four at random. When no provider answered at all, so nothing could be priced, they are out of coffee and the seller is asked to try again in a few minutes, rather than being told the items have no market price. Still for anyone whose system asks for less motion.
- Test Connection on the Settings form prices Tritanium at the chosen market without saving anything. A saved Janice key is never shown again, and it is stored encrypted with your app key, as are the Discord webhook URLs. If the app key ever changes, enter them again.

**Pricing rules**
- A single Pricing Rules page holding the default rate, the price exceptions and the buyback exclusions, with new rules filed into the block they belong to.
- Per-item, per-group, and per-category rules. A rule for the exact item decides; otherwise an exclusion on its group or category wins, then group > category > default.
- A finder on the rule form: type an item, group or category by name or ID to see which group and category an item sits in, and pick one to fill in the rule.
- Per-rule price side (buy, sell, split) and the ability to exclude an item from buyback entirely.
- Two programme modes: buy everything at the default rate, or buy only the items listed as price exceptions, with everything else reported to the seller as not accepted.
- Paying on what ore refines into: a rule ticked to refine values each item it covers at the minerals it reprocesses into, with the rule's rate and price side applied to the minerals. It refines at the programme's refining yield, set next to the default rate and 90% until changed, or at a yield of the rule's own. The programme's yield reaches only ore, ice and moon ore; anything else refines only at a yield set on its rule. The recipes and batch sizes come from SeAT's SDE, only the minerals are priced, and the quote marks each refined line with its yield. A refined line counts as thin when one of its minerals was.
- A name per rule for sellers, shown instead of EVE's on the public page's rates and most wanted list and on each quote line the rule prices, with EVE's name kept beside it on the Pricing Rules page.

**Location restrictions**
- Optionally restrict buyback to specific regions, constellations, systems, stations, or citadels, mixed freely (a region covers everything inside it). An empty list accepts any location. A station or citadel in the list matches by its id, so a citadel SeAT has no record of still counts.
- Contracts created outside the accepted locations are tracked and flagged for review rather than dropped, and the accepted locations are shown on the public page and every appraisal page. A searchable picker resolves names from the SDE.
- A haul fee per accepted location, in ISK per m3 of what is sold, never more than the quote. The narrowest entry a location falls under sets its fee, so a station's own beats its region's. Every quote shows its total m3 and the contract price at each location with a fee, the public estimate too, and the appraisal page has a copy button for each. A contract is checked against the price for where it was made, and its haul fee is kept on the contract, shown on its page and in its Discord posts, and sent with its events.
- Ships, capital modules and containers count at their packaged volume. SeAT keeps CCP's full static data on disk when it updates its SDE, and Buyback Manager reads the packaged volumes from it once for each build: every item type in a group where anything repackages. Everything else hauls at the volume SeAT already has. ESI's public type endpoint is asked only for an item newer than SeAT's copy, once per item type in a queued job and never while a quote waits, and that item's quote counts it assembled and says so. Without SeAT's copy, ships are looked up through what Janice sends with its prices, then ESI.
- Volumes for whoever hauls: each quote line shows its m3, each contract keeps the m3 it carries (from its own items, or the quote's until SeAT has read them), and the Contracts list, the contract's page, the CSV export, Statistics and Analytics show it.

**Discord notifications**
- Per-corporation or global webhooks with eight subscribable categories (Contract matched, Needs review, Key did not resolve, Buyback completed, Cancelled or expired, Waiting too long, Pricing problems, Contract feed).
- A Contract feed alert when SeAT can no longer read the contracts made to a programme's target (no token with the contracts scope, or the sync keeps failing), and again when it can, once per change.
- A Pricing problems alert when a corporation's price provider stops answering and again when it answers, once per change rather than on every quote, saying what the quotes use meanwhile.
- Flagged contracts announce the quoted value, the asked price, the percentage difference and the reasons in one message.
- Role mentions via a picker that reads detected Discord roles from the SeAT Discord integration.
- A Notification Routing Map showing which webhook announces which category.
- De-duplicated, per-webhook rate-limited delivery, and a contract's first sighting fires exactly one of matched, needs review or key did not resolve.
- A post Discord refuses, or one held back by the rate limit, is sent again on the following syncs, up to three tries within a day.
- A Test button on each webhook posts a sample to that webhook alone, whatever it subscribes to, and says what Discord answered.

**Manager Core integration (optional, 1.2.0 or later)**
- Automatic detection of Manager Core 1.2.0 or later, with a clean standalone fallback when it is absent. An older Manager Core is left alone as if it were absent, and Help, Settings and Diagnostics say it needs updating.
- Manager Core as the price provider or the fallback provider, for any market added there. Before a quote, the items it has not priced yet are subscribed with an immediate fetch and the page waits up to 20 seconds for them, so the corporation's provider prices them. Whatever has not arrived by then is priced by the fallback provider for that quote, which says Manager Core is fetching it. An item it was asked for a while ago and still has no price for is not waited on, since it has no orders there.
- A Manager Core price over 8 hours old, which means its refresh has stopped, is priced by the fallback instead, logged, and named in the quote's note. When the fallback has no price either, the old one is kept.
- Each corporation can pick the provider Manager Core prices its market through: the market's own, or MCPraisal, Fuzzwork, Janice or Goonpraisal where Manager Core can price that market with it. Manager Core keeps those prices for Buyback apart from the market's own, so corporations on the same market can price through different providers. The form says why a provider cannot be used at a market, and Settings and Diagnostics flag one that stops working.
- The items a corporation's item and group rules name are subscribed when the rule is saved, so their first quote is Manager Core's price.
- Every subscription is sent to Manager Core again once a day, so one it lost comes back. A market, or a provider at one, that no corporation prices through any more has its items dropped, and what another corporation still uses keeps them.
- The Settings form lists Manager Core's enabled markets, and says so when the installed Manager Core reports that its provider for one prices it somewhere else, such as Fuzzwork pricing a citadel at Jita 4-4.
- Registration in Manager Core's pricing preferences so an admin can override the market, and the provider, centrally for every corporation, with the Settings page saying when an override is in force.
- Events for other plugins: `buyback.contract.matched / flagged / unmatched / completed / cancelled / expired / nudge`, sent through Manager Core's topics, which check each one and key it on its contract.

**Public landing page**
- An optional per-corporation public page at `/buyback/{name}` that advertises rates, runs the no-login appraisal, issues keys, and shows config-driven contract instructions. The name is whoever the contracts go to: the corporation's ticker, the receiving corporation's ticker or typed name, or the receiving player's name. A link with the corporation's own ticker still leads there, and an appraisal link opens by its key whatever name is in front.
- While the page or the programme is off, the page and every appraisal link show a closed notice: temporarily closed or permanently closed, each with its own hamster scene, and the corporation's own message or a default one. Or the URL returns 404, for a corporation that would rather not show it has a programme.
- Brandable per corp: uploaded background and logo (dark box, no box, or white square), accent colour, dim overlay, headline, blurb, footer, and a stacked or side-by-side layout. Images stream from the app origin, so they need no `storage:link` and satisfy a strict content-security policy.
- Configurable rate display: a "most wanted" flag for featured items, an option to list every non-excluded rule, and an option to show the provider and market and each rule's price side. With that option on, the estimate and the appraisal page name them too.
- The appraisal page says when the quote was made and until when it holds. After that it warns that a contract made with it is flagged for a director to check against today's prices.
- Copy buttons on the appraisal page for the key, the contract price in whole ISK, and who to contract to.
- The appraisal page shows what became of the contract that used its key: received, paid, expired, cancelled or declined. Anyone with the link sees the state and its date, never who issued or accepted the contract.
- A ticker a closed corporation still holds finds the corporation that runs the programme, and the page settings warn when another programme's link has the same name.

**Analytics**
- A dedicated Analytics page covering ISK paid, most-bought items, groups and categories, top sellers, a quote-to-payout conversion funnel, and a breakdown of why contracts were flagged.
- A "quoted but never sold" ranking that surfaces items priced but not contracted, limited to the appraisal item-retention window and labelled as such.

**Diagnostics**
- Appraisal funnel health, orphaned appraisal-item detection, and a retention check that flags when the prune has stopped running.
- An admin-only Diagnostic page (not in the sidebar) with Health Checks, Master Test, System Validation, Settings Health, Data Integrity, Contract Trace, and Notification Testing tabs.
- A Contract Trace tab that walks a single contract through the pricing and matching pipeline.
- A price provider health check showing how each corporation's provider last answered, and Settings Health issues for a provider or fallback that cannot run.
- A Contract Sync check per programme: when its contracts were last read, and the last error.
- An Item Volumes check: how many locations charge a haul fee, which build of SeAT's static data SeAT has and which Buyback Manager has read, and how many item types came from Janice or ESI.
- Manager Core is asked through its bridge, never read from its tables: what Buyback published to it in the last day, and how many items it holds for Buyback against the local ledger.
- A Sync Now action to run a detection pass on demand.

**Help & operations**
- A Help & Documentation page in the sidebar covering the full workflow, pricing, rules, locations, analytics, detection modes, notifications, Manager Core, the public page, custom CSS styling, permissions, commands & configuration, and troubleshooting.
- A Plugins & Ecosystem page in Help listing the plugins Buyback Manager works with (Manager Core, HR Manager, SeAT Broadcast, SeAT Connector), each marked installed or not on your server, and Key features showing what each missing one takes away. The search covers every section, not only the open one.
- One scheduled job registered automatically: `buyback-manager:sync-contracts` (every 15 minutes), which also handles housekeeping. It is added only when missing, so a cron you change is kept on every update.
- `buyback-manager:upgrade-dev-build`, run once by hand to move off a development build from before 1.0.0: it carries the old programme over or starts fresh, then removes the old tables. It refuses to run while SeAT is set to install a development branch, and Diagnostics warns while old tables are left.
- Three permissions and Programme Access. An admin names each programme's managers and viewers, as SeAT accounts or whole SeAT roles. A manager needs `buyback-manager.settings` and changes only the programmes they are named on; a viewer needs `buyback-manager.view` and sees only theirs. Having a character in a corporation grants nothing, so a member can run a buyback under an alt corporation without reaching the main one's. `buyback-manager.admin` covers every programme, adds and deletes them, names who runs them, and has the global webhooks and the Diagnostic page. Programme Access and Settings Health mark a name that lacks its permission or has left SeAT.
- Appraisal and My Appraisals are in the menu for every logged-in user, since neither needs a permission.

### Tests

- Unit tests for the price chain, each provider's answer, the paste reader, the pricing rules, refined value, the thin-market guard, haul fees and the volumes they are charged on, the contract sync against SeAT's contract tables, webhook delivery and retries, the upkeep of Manager Core subscriptions, the public page's link lookup and key status, and what Help says each other plugin adds, and checks on the source itself: Blade directives closed the way Blade reads them, routes and views that exist, migrations named as they should be and running on an empty database, the docs keeping up with the commands, and nothing writing to SeAT's tables. They need no SeAT and no MySQL, and run on every push on PHP 8.1 to 8.4.

[1.0.0]: https://github.com/MattFalahe/Buyback-Manager/releases/tag/1.0.0
