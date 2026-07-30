# Buyback Manager

A buyback programme for [SeAT](https://github.com/eveseat/seat) 5.x. Let your corporation buy items at a configurable percentage of market value, with an appraise-then-contract workflow that **needs no login at all** for the person selling.

Buyback Manager works standalone on free Fuzzwork pricing or your own Janice key. [Manager Core](https://github.com/MattFalahe/manager-core) is an optional upgrade that adds regional market pricing, a shared price cache, and a cross-plugin event feed. Nothing here requires it.

> **Mental model:** an appraisal is a price quote with a receipt number. The receipt number (the appraisal key) is how an in-game contract is matched back to the quote it came from, so you can tell whether the seller asked for what you actually offered.

---

## How it works

1. A seller pastes their items into the **Appraisal** tool, or into the public page with no account at all.
2. They get a valuation plus a short single-use **appraisal key** (for example `bb-zj2cc262`) and a shareable page with the full breakdown.
3. They create an in-game item exchange **contract** to the target you name, set the price to the quoted value, and paste the key into the contract's **Description**.
4. Buyback Manager **syncs contracts** on a schedule, resolves the key back to the appraisal, and compares the two.
5. A clean contract is announced normally. Anything off is **flagged for review** with the reason, so a director checks it before paying.

A contract with no key in its Description is ignored, which keeps unrelated and deleted contracts out of the list.

---

## Features

### 🔑 Appraise-then-contract, no login needed
- Every appraisal returns a single-use key and a public, shareable appraisal page.
- Signing in is optional: it only adds history under **My Appraisals**. No account, no ESI scopes, and no permission is needed to sell.
- Two-tier retention keeps months of totals for statistics while pruning the bulky line-item rows early. Housekeeping runs inside the sync cycle, so there is no extra cron.

### 🔍 Checked, not guessed
The quote is a reference, not a price lock. Every contract is compared to its appraisal and flagged when something does not line up:
- the asked price drifts past your tolerance (with the direction, so asking *more* stands out)
- the quote was already stale when they contracted
- the contract's items do not match what was priced
- the key had already been used
- the contract was created outside the locations you accept from

### 🎯 Three contract-target modes
Pick who receives the contracts, matching EVE's own visibility rules:
- **My Corporation** (visible to the whole corp): scans your corporation's contract feed.
- **Specific Corporation** (visible to that corp's directors): reads the named corporation's feed.
- **Specific Player** (visible only to the receiver): reads the designated character's personal feed.

### 💰 Flexible pricing
- Providers: **Fuzzwork** (free, no key), **Janice** (API key, regional markets), or **Manager Core** (optional).
- Both-sides pricing (buy and sell per item) so rules can apply to either side of the spread, or the midpoint.
- Three-layer fallback (configured market, then a Jita fallback, then the local cache) so one upstream outage never zeroes a contract.

### 🧮 Pricing rules
- Override the base percentage per **item**, **group**, or **category**, with item > group > category > base precedence.
- Each rule picks the price side: `buy`, `sell`, or `split`. Rules can also exclude an item entirely, and excluded items are reported to the seller instead of silently dropped.
- Groups and categories come from EVE's own item database, and groups are narrower than their names suggest: a category such as *Charge* covers all ammunition, while covering "all missiles" takes several group rules. Faction and Tech II variants frequently sit in their own group, so confirm which group an item really belongs to before relying on a group rule. If an appraisal prices an item at your base percentage, no rule matched it.

### 📍 Location restrictions
- Optionally restrict buyback to specific **regions, constellations, systems, stations, or citadels** — mix freely, since a region entry covers everything inside it.
- Contracts from anywhere else are flagged for review, and the accepted locations are shown on the public page and every appraisal page. A searchable picker resolves names from the SDE.

### 🔔 Discord notifications
- Per-corporation or global webhooks with six subscribable categories.
- Role mentions via a picker that reads your SeAT Discord integration, plus a Routing Map that shows which webhook announces which category.
- De-duplicated and rate-limited delivery, and a contract's first sighting fires exactly one of matched, flagged or unmatched. No double pings.

### 🌐 Public landing page
- An optional per-corporation page at `/buyback/{ticker}` that advertises your rates, runs the no-login appraisal, and issues keys.
- Brandable per corp: uploaded background and logo (dark, no box, or white square), accent colour, dim overlay, headline, blurb, footer, and a stacked or side-by-side layout. Images are served from your own server, so they need no `storage:link` and satisfy a strict content-security policy.
- Configurable rates: flag "most wanted" items, optionally list every rule, and optionally show the sourced market and each rule's price side.

### 🩺 Diagnostics
- An admin-only Diagnostic page with Health Checks, Master Test, System Validation, Settings Health, Data Integrity, Contract Trace, and Notification Testing.
- The Contract Trace tab walks a single contract through the whole pipeline.

### 📖 In-app help
- A full Help & Documentation page in the sidebar covering every surface above.

---

## Compatibility

| Requirement | Version |
|---|---|
| PHP | ^8.0 |
| SeAT | ^5.0 (`eveseat/web`, `eveseat/services`) |
| Laravel | ^10.0 |
| Manager Core | Optional (`mattfalahe/manager-core`) |

---

## Installation

```bash
composer require mattfalahe/buyback-manager
```

The SeAT Docker stack auto-runs migrations when the container restarts, so a stack restart is all that is needed. Outside Docker, run the standard SeAT plugin migration step:

```bash
php artisan migrate
```

The sidebar entry, scheduled job, and permissions register automatically.

---

## First-run configuration

1. Open **Buyback Manager > Settings** and add the corporation that will run the programme.
2. Choose a **contract target** (My Corporation, a specific corporation, or a specific player).
3. Pick a **pricing provider** and set the base percentage (default 90%).
4. Optionally add **pricing rules**, **locations**, and a **Discord webhook**.
5. Enable the setting, then run an **Appraisal** to confirm prices and that you get a key back.

> **Player target mode** reads a personal contract feed, so the designated character must be registered in SeAT with a token carrying the `read_character_contracts` scope.

---

## Permissions

| Permission | Grants |
|---|---|
| `buyback-manager.view` | View the Contracts list, Statistics, and the Help page. |
| `buyback-manager.settings` | Manage corporation settings, pricing rules, locations, and Discord webhooks. Opens the admin-only Diagnostic page. |

Selling needs no permission at all: the public page works without an account. The permissions gate the director-facing surfaces only.

---

## Manager Core integration (optional)

When [Manager Core](https://github.com/MattFalahe/manager-core) is installed, Buyback Manager detects it automatically and adds:

- **Regional market pricing** through Manager Core's pricing service and shared cache.
- **Pricing preferences** in Manager Core, where an admin can override the market centrally.
- **EventBus** publishing of `buyback.contract.matched / flagged / unmatched / completed / cancelled / nudge` for other plugins to consume.

Install or uninstall Manager Core in any order. There is no composer dependency between them.

---

## Scheduled job

| Command | Cadence | Purpose |
|---|---|---|
| `buyback-manager:sync-contracts` | every 15 minutes | Detect contracts, resolve appraisal keys, raise review flags, notify, nudge idle contracts, and prune old data. |

It registers automatically. The Diagnostic page's Health Checks tab confirms it is present, and its Sync Now button runs a detection pass on demand.

---

## Known limitations

- Player-target detection requires the designated character's token to carry `read_character_contracts`.
- The appraisal key must be pasted into the contract Description; a contract without one is not tracked by design.
- Fuzzwork prices The Forge only. Use Janice or Manager Core for other markets.
- A citadel SeAT does not know about cannot be resolved to a system, so a contract there is flagged when location rules are set.

---

## Support

- **Email:** mattfalahe@gmail.com
- **SeAT Discord:** https://discord.gg/azquy29nqs
- **GitHub:** https://github.com/MattFalahe/Buyback-Manager

---

## License

GPL-2.0-or-later. Author: Matt Falahe.
