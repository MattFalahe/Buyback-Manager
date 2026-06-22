# Buyback Manager

A buyback programme for [SeAT](https://github.com/eveseat/seat) 5.x. Let your corporation buy items from members at a configurable percentage of market value, with a quote-then-contract workflow that locks the price the moment a member accepts it.

Buyback Manager works standalone on free Fuzzwork pricing or your own Janice key. [Manager Core](https://github.com/MattFalahe/manager-core) is an optional upgrade that adds regional market pricing, a shared price cache, and a cross-plugin event feed. Nothing here requires it.

> **Mental model:** an offer is a price quote with a receipt number. The quote is locked the moment it is published, so the value never drifts while the member is hauling and contracting. The receipt number (the offer id) is how the in-game contract is matched back to the quote.

---

## How it works

1. A member pastes their items into the **Appraisal** tool and gets a valuation using the corporation's provider and pricing rules.
2. They **publish it as an offer**. The prices freeze and the offer gets a short id (for example `bb-zj2cc262`) and an expiry (the lock window you set).
3. The member creates an in-game item exchange **contract** to the target the offer names, and pastes the offer id into the contract's **Description**.
4. Buyback Manager **syncs contracts** on a schedule, reads the offer id from the Description, and pairs the contract to the pending offer at its frozen value.
5. The offer is marked matched and any configured **Discord webhooks** announce it.

A contract with no valid offer id in its Description is ignored, which keeps unrelated and deleted contracts out of the list.

---

## Features

### 💱 Quote-then-contract workflow
- Frozen-price offers with a configurable lock window, so a member always gets exactly the value they were quoted.
- Account-aware matching: a member can quote with their main and contract from an alt on the same SeAT account. A different account cannot claim someone else's offer id.
- Offer lifecycle: pending, matched, expired, cancelled, rejected. Lapsed offers are swept automatically.
- Export the filtered contracts list to CSV for payout reconciliation and record-keeping.

### 🎯 Three contract-target modes
Pick who receives the contracts, matching EVE's own visibility rules:
- **My Corporation** (visible to the whole corp): scans your corporation's contract feed.
- **Specific Corporation** (visible to that corp's directors): reads the named corporation's feed.
- **Specific Player** (visible only to the receiver): reads the designated character's personal contract feed.

### 💰 Flexible pricing
- Providers: **Fuzzwork** (free, no key), **Janice** (API key, regional markets), or **Manager Core** (optional).
- Both-sides pricing (buy and sell per item) so rules can apply to either side of the spread, or the midpoint.
- Three-layer fallback (configured market, then a Jita fallback, then the local cache) so one upstream outage never zeroes a contract.
- Local price cache with a per-corp TTL. Manager Core bypasses it because it maintains its own.

### 🧮 Pricing rules
- Override the base percentage per **item**, **group**, or **category**, with item > group > category > base precedence.
- Each rule picks the price side: `buy`, `sell`, or `split`. Rules can also exclude an item entirely.

### 🔔 Discord notifications
- Per-corporation or global webhooks with six subscribable categories.
- Role mentions via a picker that reads your SeAT Discord integration, plus a Routing Map that shows which webhook announces which category.
- De-duplicated and rate-limited delivery. No double pings.

### 🌐 Public landing page
- An optional per-corporation public page at `/buyback/{ticker}` that advertises your rates, shows config-driven "how to sell" instructions, and links members into the appraisal via EVE SSO. No login required to view.
- Brandable per corp: uploaded background and logo (with an optional square backdrop behind the logo), accent colour, dim overlay, headline, blurb, and footer. Images are served from your own server, so they need no `storage:link` and satisfy SeAT's content-security policy on Docker and bare-metal alike.
- Configurable rates: flag "most wanted" items, optionally list every rule (not just the featured ones), and optionally show the sourced market and each rule's price side (buy / sell / split).

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

The sidebar entry, scheduled jobs, and permissions register automatically.

---

## First-run configuration

1. Open **Buyback Manager > Settings** and add the corporation that will run the programme.
2. Choose a **contract target** (My Corporation, a specific corporation, or a specific player).
3. Pick a **pricing provider** and set the base percentage (default 90%).
4. Optionally add **pricing rules** and a **Discord webhook**.
5. Enable the setting, then run an **Appraisal** to confirm prices and publish a test offer.

> **Player target mode** reads a personal contract feed, so the designated character must be registered in SeAT with a token carrying the `read_character_contracts` scope.

---

## Permissions

| Permission | Grants |
|---|---|
| `buyback-manager.view` | View the Contracts list, Statistics, and the Help page. |
| `buyback-manager.settings` | Manage corporation settings, pricing rules, and Discord webhooks. Opens the admin-only Diagnostic page. |

Appraisal and publishing offers are open to any logged-in member without either permission. The permissions gate the director-facing surfaces only.

---

## Manager Core integration (optional)

When [Manager Core](https://github.com/MattFalahe/manager-core) is installed, Buyback Manager detects it automatically and adds:

- **Regional market pricing** through Manager Core's pricing service and shared cache.
- **Pricing preferences** in Manager Core, where an admin can override the market centrally.
- **EventBus** publishing of offer and contract events (`offer.published / matched / expired / cancelled / rejected` and `contract.created / matched / unmatched / completed / cancelled / rejected`) for other plugins to consume.

Install or uninstall Manager Core in any order. There is no composer dependency between them.

---

## Scheduled jobs

| Command | Cadence | Purpose |
|---|---|---|
| `buyback-manager:sync-contracts` | every 15 minutes | Detect and update buyback contracts. |
| `buyback-manager:expire-offers` | every 5 minutes | Sweep offers past their lock window. |

Both register automatically. The Diagnostic page's Health Checks tab confirms they are present, and its Sync Now button runs a detection pass on demand.

---

## Known limitations

- Player-target detection requires the designated character's token to carry `read_character_contracts`.
- The offer id must be pasted into the contract Description; a contract without one is not tracked by design.
- Fuzzwork prices The Forge only. Use Janice or Manager Core for other markets.

---

## Support

- **Email:** mattfalahe@gmail.com
- **SeAT Discord:** https://discord.gg/azquy29nqs
- **GitHub:** https://github.com/MattFalahe/Buyback-Manager

---

## License

GPL-2.0-or-later. Author: Matt Falahe.
