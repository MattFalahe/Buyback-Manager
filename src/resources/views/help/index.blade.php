@extends('web::layouts.grids.12')

@section('title', 'Help & Documentation')
@section('page_header', 'Help & Documentation')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=5">
@endpush

@section('full')
<div class="buyback-manager-wrapper">
    <div class="help-wrapper">

        {{-- Sidebar navigation --}}
        <div class="help-sidebar">
            <div class="card card-dark">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-compass"></i> Navigation</h3>
                </div>
                <div class="card-body p-0">
                    <ul class="nav nav-pills flex-column help-nav">
                        <li class="nav-item"><a href="#" class="nav-link active" data-section="overview"><i class="fas fa-book-open"></i> Overview</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="getting-started"><i class="fas fa-rocket"></i> Getting Started</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="pricing"><i class="fas fa-coins"></i> Pricing &amp; Cache</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="rules"><i class="fas fa-sliders-h"></i> Pricing Rules</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="locations"><i class="fas fa-map-marker-alt"></i> Locations</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="analytics"><i class="fas fa-chart-pie"></i> Analytics</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="workflow"><i class="fas fa-route"></i> Selling Workflow</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="detection"><i class="fas fa-file-contract"></i> Contracts &amp; Detection</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="notifications"><i class="fab fa-discord"></i> Discord Notifications</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="public-page"><i class="fas fa-globe"></i> Public Page</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="integration"><i class="fas fa-plug"></i> Manager Core</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="permissions"><i class="fas fa-user-shield"></i> Permissions</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="custom-styling"><i class="fas fa-paint-brush"></i> Custom Styling</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="commands"><i class="fas fa-terminal"></i> Commands &amp; Config</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="troubleshooting"><i class="fas fa-stethoscope"></i> Troubleshooting</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="faq"><i class="fas fa-question-circle"></i> FAQ</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="support"><i class="fas fa-life-ring"></i> Support</a></li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Content --}}
        <div class="help-content">

            <div class="search-box">
                <input type="text" id="helpSearch" placeholder="Search the documentation..." class="form-control">
                <i class="fas fa-search"></i>
            </div>

            {{-- OVERVIEW --}}
            <div id="overview" class="help-section active">

                {{-- Plugin Information --}}
                <div class="help-card">
                    <h3><i class="fas fa-info-circle"></i> Plugin Information</h3>
                    <p>
                        <strong>Version:</strong>
                        <span class="badge badge-secondary" style="font-size: 0.9rem; vertical-align: middle;">v{{ $version }}</span>
                        <span class="badge" style="background:#667eea; color:#fff; font-size: 0.85rem; vertical-align: middle;">SeAT 5.0</span>
                        <span class="badge" style="background:#3a4049; color:#cbd5e1; font-size: 0.85rem; vertical-align: middle;">{{ $mcAvailable ? 'Manager Core detected' : 'Standalone mode' }}</span>
                    </p>
                    <p><strong>License:</strong> GPL-2.0-or-later</p>
                    <p>
                        <i class="fas fa-user"></i> <strong>Author:</strong> Matt Falahe<br>
                        <i class="fas fa-envelope"></i> <a href="mailto:mattfalahe@gmail.com">mattfalahe@gmail.com</a>
                    </p>

                    <div class="quick-links" style="margin-top: 15px;">
                        <a href="https://github.com/MattFalahe/Buyback-Manager" class="quick-link" target="_blank" rel="noopener" style="padding: 10px;">
                            <i class="fas fa-code-branch" style="font-size: 1rem; margin-bottom: 4px;"></i> GitHub Repo
                        </a>
                        <a href="https://github.com/MattFalahe/Buyback-Manager/blob/Dev-2.0/CHANGELOG.md" class="quick-link" target="_blank" rel="noopener" style="padding: 10px;">
                            <i class="fas fa-list" style="font-size: 1rem; margin-bottom: 4px;"></i> Changelog
                        </a>
                        <a href="https://github.com/MattFalahe/Buyback-Manager/issues" class="quick-link" target="_blank" rel="noopener" style="padding: 10px;">
                            <i class="fas fa-bug" style="font-size: 1rem; margin-bottom: 4px;"></i> Report Issues
                        </a>
                        <a href="https://github.com/MattFalahe/Buyback-Manager/blob/Dev-2.0/README.md" class="quick-link" target="_blank" rel="noopener" style="padding: 10px;">
                            <i class="fas fa-book" style="font-size: 1rem; margin-bottom: 4px;"></i> Readme
                        </a>
                    </div>
                </div>

                {{-- Version Status: delegates to Manager Core's EcosystemVersionChecker
                     when MC is installed; same field shape rendered either way. --}}
                @php
                    $vs = $versionStatus ?? ['current' => '?', 'current_source' => 'config', 'is_dev_branch' => false, 'latest' => null, 'status' => 'unknown', 'message' => '', 'release_url' => null];
                    $statusBadgeClass = [
                        'current'    => 'badge-success',
                        'outdated'   => 'badge-warning',
                        'ahead'      => 'badge-info',
                        'dev_branch' => 'badge-info',
                        'unreleased' => 'badge-secondary',
                        'unknown'    => 'badge-secondary',
                        'offline'    => 'badge-secondary',
                    ][$vs['status']] ?? 'badge-secondary';
                    $statusLabel = [
                        'current'    => 'Up to date',
                        'outdated'   => 'Update available',
                        'ahead'      => 'Pre-release',
                        'dev_branch' => 'Development branch',
                        'unreleased' => 'Coming soon',
                        'unknown'    => 'Unable to check',
                        'offline'    => 'Not installed',
                    ][$vs['status']] ?? 'Unknown';
                    $installedDisplay = ($vs['is_dev_branch'] || empty($vs['current'])) ? ($vs['current'] ?? '?') : ('v' . $vs['current']);
                    $sourceHint = ($vs['current_source'] ?? 'config') === 'composer'
                        ? "resolved via Composer's installed.json"
                        : 'resolved via fallback (Composer metadata unavailable)';
                @endphp
                <div class="help-card">
                    <h3><i class="fas fa-tag"></i> Version Status</h3>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin: 0.5rem 0;">
                        <div>
                            <strong>Installed:</strong>
                            <span class="badge badge-secondary" style="font-size: 0.9rem;" title="{{ $sourceHint }}">{{ $installedDisplay }}</span>
                        </div>
                        <div>
                            <strong>Latest release:</strong>
                            @if(! empty($vs['latest']))
                                <span class="badge badge-secondary" style="font-size: 0.9rem;">v{{ $vs['latest'] }}</span>
                            @else
                                <span class="badge badge-secondary" style="font-size: 0.9rem;">unknown</span>
                            @endif
                        </div>
                        <div>
                            <span class="badge {{ $statusBadgeClass }}" style="font-size: 0.9rem;">{{ $statusLabel }}</span>
                        </div>
                        @if(! empty($vs['release_url']))
                            <div>
                                <a href="{{ $vs['release_url'] }}" target="_blank" rel="noopener" class="btn btn-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff;">
                                    <i class="fas fa-external-link-alt"></i> View release notes
                                </a>
                            </div>
                        @endif
                    </div>
                    @if(! empty($vs['message']))
                        <small class="text-muted">{{ $vs['message'] }}</small>
                    @endif
                    <small class="text-muted" style="display: block; margin-top: 0.4rem; font-size: 0.75rem;">
                        <i class="fas fa-info-circle"></i>
                        Installed version {{ $sourceHint }}. Latest is checked against Packagist's public API (6h cache, safe on outages).
                    </small>
                </div>

                {{-- Welcome --}}
                <div class="help-card">
                    <h3><i class="fas fa-hand-sparkles"></i> Welcome</h3>
                    <p>Buyback Manager turns a corporation buyback programme into a simple, fair workflow: a seller appraises their items, gets a key, and creates an in game contract carrying that key, which Buyback Manager detects, checks and records automatically. It runs on its own with free pricing, and grows richer when the rest of the suite is installed.</p>
                    <p>This page is the full reference. Use the navigation on the left to jump to setup, pricing, the selling workflow, contract detection, Discord notifications, and troubleshooting.</p>
                </div>

                {{-- What is Buyback Manager --}}
                <div class="help-card">
                    <h3><i class="fas fa-exchange-alt"></i> What is Buyback Manager?</h3>
                    <p>Buyback Manager lets a corporation buy items at a configurable percentage of market value. A seller pastes their items into the appraisal tool, gets a valuation and a short <strong>appraisal key</strong>, then creates an in game contract for those items and writes the key into the contract's Description. Buyback Manager detects the contract, resolves the key back to the appraisal, checks the two against each other, and records the deal. None of it requires a login.</p>

                    <div class="purple-box">
                        <i class="fas fa-quote-left"></i>
                        <strong>Mental model:</strong> an appraisal is a price quote with a receipt number. The receipt number (the key) is how a contract is matched back to the quote it came from, so we can tell whether the seller asked for what we actually offered.
                    </div>

                    <div class="info-box">
                        <i class="fas fa-cube"></i>
                        <strong>Works standalone:</strong> it runs on its own using free Fuzzwork pricing or your Janice API key. Manager Core is an optional upgrade that adds regional market pricing, a shared price cache, and a cross plugin event feed. Nothing here requires it.
                    </div>
                </div>

                {{-- Key Features --}}
                <div class="help-card">
                    <h3><i class="fas fa-star"></i> Key features</h3>
                    <div class="feature-grid">
                        <div class="feature-item">
                            <i class="fas fa-route"></i>
                            <h5>Quote-then-contract</h5>
                            <p>Every appraisal returns a single-use key. Paste it into the contract and we match the two up. No login needed.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-crosshairs"></i>
                            <h5>Three contract targets</h5>
                            <p>Send contracts to your corp, another corporation, or a designated player, matching EVE's own visibility.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-coins"></i>
                            <h5>Flexible pricing</h5>
                            <p>Fuzzwork, Janice, or Manager Core, with both-sides prices and a Jita-plus-cache fallback chain.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-sliders-h"></i>
                            <h5>Pricing rules</h5>
                            <p>Per item, group, or category percentages with buy, sell, or split price sides and exclusions.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-tag"></i>
                            <h5>Checked, not guessed</h5>
                            <p>We compare the asked price and items against the quote, and flag anything off for review. Noise contracts are ignored.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fab fa-discord"></i>
                            <h5>Discord notifications</h5>
                            <p>Per corp or global webhooks, six categories, role mentions, and a routing map. De duplicated and rate limited.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-globe"></i>
                            <h5>Public page</h5>
                            <p>An optional branded landing page with your rates and a no-login appraisal tool anyone can use.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-plug"></i>
                            <h5>Manager Core ready</h5>
                            <p>Optional regional pricing, central pricing preferences, and an EventBus feed for other plugins.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-stethoscope"></i>
                            <h5>Diagnostics</h5>
                            <p>An admin diagnostic page with health checks, settings audit, and a single-contract trace.</p>
                        </div>
                    </div>
                </div>

            </div>

            {{-- GETTING STARTED --}}
            <div id="getting-started" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-rocket"></i> Getting started</h3>
                    <p>A director (someone with the <code>buyback-manager.settings</code> permission) sets up a programme once. Members then use it without any further configuration.</p>

                    <ol class="step-by-step">
                        <li><strong>Open Settings.</strong> Go to <a href="{{ route('buyback-manager.settings.index') }}">Buyback Manager &gt; Settings</a> and add the corporation that will run the programme.</li>
                        <li><strong>Choose a contract target:</strong> your own corporation, a specific corporation by name, or a specific player. This drives both the instructions members see and how detection works (see <a href="#" class="js-goto" data-goto="detection">Contracts &amp; Detection</a>).</li>
                        <li><strong>Pick a pricing provider</strong> (Fuzzwork, Janice, or Manager Core) and set your base percentage (the default is 90%).</li>
                        <li><strong>Add pricing rules (optional)</strong> to override the base percentage for specific items, groups, or categories.</li>
                        <li><strong>Add a Discord webhook (optional)</strong> on the Settings page Discord Webhooks tab, and pick a role to mention.</li>
                        <li><strong>Enable the setting and test:</strong> run an <a href="{{ route('buyback.appraisal.index') }}">Appraisal</a> to confirm prices look right and that you get a key back.</li>
                    </ol>

                    <div class="success-box">
                        <i class="fas fa-check-circle"></i>
                        <strong>That is the whole setup.</strong> Once a corporation setting is enabled, anyone can appraise against it and get a key &mdash; from the plugin or the public page. Contract detection runs automatically on a schedule.
                    </div>
                </div>
            </div>

            {{-- PRICING --}}
            <div id="pricing" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-coins"></i> Pricing &amp; cache</h3>
                    <p>Every appraisal fetches both a buy price and a sell price per item, because a pricing rule can apply your percentage to either side of the spread (or the midpoint).</p>

                    <div class="feature-grid">
                        <div class="feature-item">
                            <i class="fas fa-gift"></i>
                            <h5>Fuzzwork</h5>
                            <p>Free, no API key. Region based aggregates for The Forge (Jita). The simplest standalone option.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-bolt"></i>
                            <h5>Janice</h5>
                            <p>Needs an API key. Multiple regional markets, plus a raw appraisal endpoint for large lists.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-plug"></i>
                            <h5>Manager Core</h5>
                            <p>Optional upgrade. Any configured market, using Manager Core's own shared price cache.</p>
                        </div>
                    </div>

                    <h4>The fallback chain</h4>
                    <p>To stop a single upstream outage zeroing out a contract, pricing falls back in three layers:</p>
                    <ol>
                        <li>Your configured market and provider.</li>
                        <li>A Jita fallback (when "fallback to Jita" is enabled on the setting).</li>
                        <li>The most recent locally cached price, if the upstream is unreachable.</li>
                    </ol>

                    <div class="info-box">
                        <i class="fas fa-database"></i>
                        <strong>How the cache works:</strong> Fuzzwork and Janice prices are cached locally and reused until they go stale, controlled by the cache TTL on each setting (in minutes). Manager Core bypasses the local cache because it maintains its own.
                    </div>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Fuzzwork has no regional markets:</strong> it prices The Forge only. For another market hub, use Janice or Manager Core.
                    </div>
                </div>
            </div>

            {{-- RULES --}}
            <div id="rules" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-sliders-h"></i> Pricing rules</h3>
                    <p>A rule overrides the base percentage for part of your item list. Each rule sets a percentage and which side of the market spread to apply it to.</p>

                    <div class="feature-grid">
                        <div class="feature-item">
                            <i class="fas fa-cube"></i>
                            <h5>Item</h5>
                            <p>One specific type. Example: Tritanium at 95%.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-layer-group"></i>
                            <h5>Group</h5>
                            <p>Every type in an inventory group. Example: all Mining Crystals at 80%.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-th-large"></i>
                            <h5>Category</h5>
                            <p>Every type in an inventory category. Example: all Ships excluded.</p>
                        </div>
                    </div>

                    <h4>Price side</h4>
                    <p>Each rule (and the base) applies its percentage to one of:</p>
                    <ul>
                        <li><code>buy</code> the highest buy order price (most conservative for the buyer).</li>
                        <li><code>sell</code> the lowest sell order price (most generous to the member).</li>
                        <li><code>split</code> the midpoint between buy and sell.</li>
                    </ul>

                    <div class="purple-box">
                        <i class="fas fa-sort-amount-down"></i>
                        <strong>Precedence: item &gt; group &gt; category &gt; base.</strong> The most specific matching rule wins. A rule can also exclude an item entirely (no buyback for that type).
                    </div>

                    <h4>How the page is laid out</h4>
                    <p>Everything that decides what you pay lives on the <strong>Pricing Rules</strong> page, in three blocks:</p>
                    <table class="plugin-info-table">
                        <tr><td><strong>Default rate for all items</strong></td><td>The flat rate paid for anything without an exception, and the switch between buying everything and buying only listed items.</td></tr>
                        <tr><td><strong>Price exceptions</strong></td><td>Items, groups or categories bought at a different rate.</td></tr>
                        <tr><td><strong>Buyback exclusions</strong></td><td>Things you never buy. Sellers are told these are not accepted.</td></tr>
                    </table>
                    <p>Adding a rule sends it to whichever block matches: give it a percentage and it becomes a price exception, tick <em>Do not buy this</em> and it becomes an exclusion.</p>

                    <h4>Buy everything, or only what you list</h4>
                    <table class="plugin-info-table">
                        <tr><td><strong>Buy everything</strong> (default)</td><td>Every item is bought at the default rate, with exceptions changing the rate for some and exclusions carving out the rest.</td></tr>
                        <tr><td><strong>Buy only listed items</strong></td><td>An allow list: the default rate is ignored and only items with a price exception are bought. Everything else is reported to the seller as not accepted rather than quoted.</td></tr>
                    </table>
                    <div class="info-box">
                        <i class="fas fa-list-check"></i>
                        Allow-list mode is the clean way to run a narrow programme (for example "we only buy ore and minerals"). Setting the default rate to 0% instead would still quote every unlisted item, at zero, which reads as though you are buying it for nothing.
                    </div>

                    <h4>Choosing between a group and a category</h4>
                    <p>Groups and categories both come straight from EVE's own item database, and <strong>groups are far narrower than their names suggest</strong>. A category is the broad bucket; the groups inside it are small, specific subdivisions:</p>

                    <table class="plugin-info-table">
                        <tr><td>Category <strong>Charge</strong></td><td>Every kind of ammunition: missiles, hybrid charges, crystals, projectile ammo, and more. One rule covers the lot.</td></tr>
                        <tr><td>Group <strong>(one ammo type)</strong></td><td>Only that one kind of ammunition. Covering "all missiles" takes several group rules, not one.</td></tr>
                    </table>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Faction and Tech II variants often sit in their own group.</strong> EVE frequently splits advanced ammunition into a separate group from the Tech I version, so a rule on the basic group will not catch the faction or Tech II equivalents. Always confirm the group an item actually belongs to before relying on a group rule &mdash; a plausible sounding group name is not proof it contains the items you mean.
                    </div>

                    <div class="info-box">
                        <i class="fas fa-search"></i>
                        <strong>How to tell a rule did not match:</strong> run an appraisal and look at the percentage on the line. If an item comes back at your <em>base</em> percentage, no rule matched it. If you expected it to be excluded and it is priced instead, your rule is pointing at the wrong group or category.
                    </div>

                    <div class="success-box">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Practical approach:</strong> use a <strong>category</strong> rule for broad strokes (exclude all ammo, all ships), then add narrower <strong>group</strong> or <strong>item</strong> rules for the specific things you do want to buy. Precedence means the narrower rules win, so you get wide coverage without listing every group by hand.
                    </div>
                </div>
            </div>

            {{-- WORKFLOW --}}
            <div id="workflow" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-route"></i> The selling workflow</h3>
                    <p>This is the lifecycle every buyback follows, from appraisal to a recorded deal. <strong>No login is required</strong> at any point: a seller can do the whole thing from the public page.</p>

                    <ol class="step-by-step">
                        <li><strong>Appraise.</strong> The seller pastes their items into the Appraisal page or the public page, and gets a valuation using the corporation's provider and rules.</li>
                        <li><strong>Get the key.</strong> The appraisal is saved and handed back with a short single-use key like <code>bb-zj2cc262</code>, plus a shareable page showing the full breakdown.</li>
                        <li><strong>Create the EVE contract.</strong> The seller makes an item exchange contract to the named target, sets the price to the quoted value, and pastes the key into the contract's Description.</li>
                        <li><strong>Detection.</strong> Buyback Manager syncs contracts every 15 minutes (or immediately via the Diagnostic page's Sync Now button), reads the key from the Description, and resolves it to the appraisal.</li>
                        <li><strong>Check and notify.</strong> The contract is compared against the appraisal. A clean match is announced as normal; anything off is flagged for a director to review before paying.</li>
                    </ol>

                    <h4>What gets checked</h4>
                    <p>The quote is a <strong>reference, not a guarantee</strong>. Nothing locks a price, so a discrepancy raises a review flag rather than binding you to an old number:</p>
                    <table class="plugin-info-table">
                        <tr><td>Asked price</td><td>The ISK on the contract differs from the quote by more than your tolerance (default 1%). The direction is reported, so asking <em>more</em> than quoted stands out from asking less.</td></tr>
                        <tr><td>Stale quote</td><td>The appraisal was already older than your freshness window when the contract was made, so market prices may have moved.</td></tr>
                        <tr><td>Item mismatch</td><td>The contract's contents do not match the items we priced.</td></tr>
                        <tr><td>Reused key</td><td>The key had already been claimed by an earlier contract.</td></tr>
                        <tr><td>Wrong location</td><td>The contract was created outside the locations you accept from.</td></tr>
                    </table>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>The key must be in the Description.</strong> A contract with no valid key is not treated as a buyback and is ignored. This keeps unrelated and deleted contracts out of the Contracts list.
                    </div>

                    <div class="info-box">
                        <i class="fas fa-key"></i>
                        <strong>Keys are single use.</strong> Each appraisal mints a new one, and once a contract claims it the key cannot be claimed again. Signing in is never required: if the person who contracts is a known SeAT user, the appraisal is filed under their My Appraisals automatically once the contract is detected.
                    </div>
                </div>
            </div>

            {{-- DETECTION --}}
            <div id="detection" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-file-contract"></i> Contracts &amp; detection</h3>
                    <p>The contract target you pick in Settings decides who the contract is visible to in game, and that decides which contract feed Buyback Manager reads. The three modes line up with EVE's own contract visibility.</p>

                    <div class="feature-grid">
                        <div class="feature-item">
                            <i class="fas fa-users"></i>
                            <h5>My Corporation</h5>
                            <p><strong>Visible to the whole corp.</strong> Members contract to the corp, so anyone in it can take the contract. Detection scans your corporation's feed and matches by the issuer's account.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-building"></i>
                            <h5>Specific Corporation</h5>
                            <p><strong>Visible to that corp's directors.</strong> You type a corporation name. Detection reads that corporation's feed (it must be registered in SeAT).</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-user"></i>
                            <h5>Specific Player</h5>
                            <p><strong>Visible only to the receiver.</strong> You name a designated character. Detection reads that character's personal contract feed.</p>
                        </div>
                    </div>

                    <div class="warning-box">
                        <i class="fas fa-key"></i>
                        <strong>Player mode needs the operator's token:</strong> the designated character must be registered in SeAT with a token carrying the <code>read_character_contracts</code> scope, or contracts to that character cannot be seen.
                    </div>

                    <div class="info-box">
                        <i class="fas fa-filter"></i>
                        <strong>What gets recorded:</strong> only contracts that carry a valid appraisal key appear in the <a href="{{ route('buyback-manager.contracts.index') }}">Contracts</a> list. A key that does not resolve is logged as an unmatched attempt for review, but no contract row is created.
                    </div>
                </div>
            </div>

            {{-- NOTIFICATIONS --}}
            <div id="notifications" class="help-section">
                <div class="help-card">
                    <h3><i class="fab fa-discord"></i> Discord notifications</h3>
                    <p>Buyback Manager owns its own Discord delivery. You add webhooks on the Settings page (Discord Webhooks tab), choose which categories each one announces, and optionally mention a role. A webhook can be scoped to one corporation or made global.</p>

                    <h4>The six notification categories</h4>
                    <table class="plugin-info-table">
                        <tr><td>Contract Matched</td><td>A contract matched its appraisal key cleanly, with nothing to review.</td></tr>
                        <tr><td>Contract Flagged</td><td>A contract matched but needs review: price drift, stale quote, item mismatch, reused key, or wrong location.</td></tr>
                        <tr><td>Contract Unmatched</td><td>A contract quoted an appraisal key that did not resolve (review signal).</td></tr>
                        <tr><td>Contract Completed</td><td>A buyback contract is finished.</td></tr>
                        <tr><td>Contract Cancelled</td><td>A buyback contract is cancelled.</td></tr>
                        <tr><td>Contract Nudge</td><td>A matched contract has sat unaccepted past the corp's auto-nudge window.</td></tr>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-at"></i>
                        <strong>Role mentions and the Routing Map:</strong> the role picker lists Discord roles detected from your SeAT Discord integration. The Routing Map tab on the Settings page shows which webhook announces which category, so you can confirm coverage and spot gaps.
                    </div>

                    <div class="success-box">
                        <i class="fas fa-shield-alt"></i>
                        <strong>No double pings, no spam:</strong> each event sends at most once per webhook (duplicates are de duplicated), and each webhook is rate limited. A contract's first sighting fires exactly one of matched, flagged or unmatched &mdash; never two.
                    </div>
                </div>
            </div>

            {{-- INTEGRATION --}}
            <div id="integration" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-plug"></i> Manager Core integration</h3>
                    <p>Manager Core is an optional companion plugin. When it is installed, Buyback Manager detects it automatically and unlocks a few extras. When it is absent, everything still works on Fuzzwork or Janice.</p>

                    @if($mcAvailable)
                        <div class="success-box">
                            <i class="fas fa-check-circle"></i>
                            <strong>Manager Core is installed.</strong> Regional pricing, the shared price cache, and the cross plugin event feed are available to this plugin.
                        </div>
                    @else
                        <div class="info-box">
                            <i class="fas fa-cube"></i>
                            <strong>Manager Core is not installed.</strong> You are running in standalone mode. Install it later to add the features below without changing anything else.
                        </div>
                    @endif

                    <h4>What Buyback Manager consumes from Manager Core</h4>
                    <ul>
                        <li><strong>Regional market pricing</strong> through Manager Core's pricing service, with its own shared cache (bypasses the local price cache).</li>
                        <li><strong>Pricing preferences</strong> registered in Manager Core, where an admin can override the market used centrally.</li>
                    </ul>

                    <h4>What Buyback Manager publishes to Manager Core</h4>
                    <p>Every contract lifecycle transition is published to Manager Core's EventBus &mdash; an integration surface other plugins can subscribe to, separate from and in addition to the Discord categories above. Standalone installs simply get the Discord notifications.</p>
                    <div class="purple-box">
                        <i class="fas fa-broadcast-tower"></i>
                        <strong>Events published:</strong> <code>buyback.contract.matched</code>, <code>.flagged</code>, <code>.unmatched</code>, <code>.completed</code>, <code>.cancelled</code> and <code>.nudge</code>. Appraisals are not published &mdash; they are generated far too often (every public estimate) to be useful bus traffic.
                    </div>
                </div>
            </div>

            {{-- PERMISSIONS --}}
            <div id="permissions" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-user-shield"></i> Permissions</h3>
                    <p>Buyback Manager uses two permissions, assigned through SeAT's role system.</p>

                    <table class="plugin-info-table">
                        <tr><td><code>view</code></td><td>View the Contracts list, Statistics, and this Help page.</td></tr>
                        <tr><td><code>settings</code></td><td>Manage corporation settings, pricing rules, and Discord webhooks. Also opens the admin only Diagnostic page.</td></tr>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-unlock"></i>
                        <strong>Selling needs no permission at all:</strong> anyone can appraise and get a key from the public page without an account, and any logged in SeAT user can do the same from the plugin. The permissions above gate the director facing surfaces only.
                    </div>
                </div>
            </div>

            {{-- LOCATIONS --}}
            <div id="locations" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-map-marker-alt"></i> Location Restrictions</h3>
                    <p>By default a contract is accepted no matter where it was created. You can restrict buyback to specific places so members can't run your buyback from the other side of the map.</p>

                    <h4>Setting it up</h4>
                    <p>In <strong>Settings</strong>, click the <i class="fas fa-map-marker-alt"></i> location button on a corporation, then add one or more allowed locations. Search by name and pick a <strong>region, constellation, system, station, or citadel</strong>. Mix granularities freely &mdash; a region entry covers every station and structure inside it, so "The Forge" accepts all of it while "Jita IV-4" accepts only that station.</p>

                    <div class="info-box">
                        <i class="fas fa-ban"></i>
                        With one or more locations set, a contract created anywhere else is still tracked but <strong>flagged</strong> with "Created outside the accepted buyback locations", announced through the Contract Flagged category so a director can review and decline it in game. An empty list means no restriction.
                    </div>

                    <p>The accepted locations are listed on the public page and on every appraisal page, so a seller knows where to contract before hauling anywhere.</p>
                </div>
            </div>

            {{-- ANALYTICS --}}
            <div id="analytics" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-chart-pie"></i> Analytics</h3>
                    <p>The <strong>Analytics</strong> page answers two questions the contract list cannot: what the programme actually buys, and how much of what it quotes turns into a sale. Filter by corporation and period; every figure respects the same corporation visibility as the Contracts list.</p>

                    <h4>What each panel tells you</h4>
                    <table class="plugin-info-table">
                        <tr><td><strong>Headline</strong></td><td>ISK paid, average buyback, distinct sellers and items bought for the period.</td></tr>
                        <tr><td><strong>Quote to payout</strong></td><td>Appraisals issued, keys actually used in a contract, and buybacks completed. The gap between the first two is the share of people who were quoted and walked away.</td></tr>
                        <tr><td><strong>Most bought</strong></td><td>Items, groups and categories ranked by the ISK you paid for them &mdash; the practical guide to which pricing rules matter.</td></tr>
                        <tr><td><strong>Top sellers</strong></td><td>Who supplies the programme, by ISK paid.</td></tr>
                        <tr><td><strong>Why contracts were flagged</strong></td><td>Which review reason fires most, so you fix the cause rather than the symptom.</td></tr>
                        <tr><td><strong>Quoted but never sold</strong></td><td>Items people were priced for and did not contract, ranked by the ISK that walked away.</td></tr>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Reading the conversion rate.</strong> A falling rate usually means your rates have drifted behind the market: people still ask for a price, but fewer of them accept it. Cross-check the items at the top of <em>Quoted but never sold</em> &mdash; those are where you are losing the sale.
                    </div>

                    <div class="warning-box">
                        <i class="fas fa-hourglass-half"></i>
                        <strong>One panel has a shorter memory.</strong> Everything about what was <em>bought</em> covers full history, because contract line items are kept for as long as the contract is. <em>Quoted but never sold</em> reads appraisal line items instead, and those are pruned early by design, so it only covers the item-retention window.
                    </div>
                </div>
            </div>

            {{-- PUBLIC PAGE --}}
            <div id="public-page" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-globe"></i> Public Page</h3>
                    <p>Each corporation can publish an optional public, no-login landing page that advertises the buyback programme and funnels sellers in. It lives at <code>/buyback/{ticker}</code> (for example <code>/buyback/MINC</code>) and is safe to share outside SeAT.</p>

                    <h4>Turning it on</h4>
                    <p>Open <strong>Settings</strong>, find the corporation in the Configured Corps table, and click the <i class="fas fa-globe"></i> globe button in its Actions column. Tick <strong>Enable the public page</strong> and save. The editor shows the exact public URL; while the page is disabled that URL returns 404, so nothing is exposed until you choose to publish.</p>
                    <div class="info-box">
                        <i class="fas fa-sign-in-alt"></i>
                        The whole selling flow works with no login, and the page never asks for one: a visitor appraises on the public page, gets a key and a shareable appraisal URL, and contracts in game. Membership is <strong>detected, not declared</strong> &mdash; when the contract is picked up, its issuing character is resolved back to a SeAT account, so a member who appraised without signing in still gets the appraisal filed under My Appraisals. Someone with no SeAT account simply shows as a guest.
                    </div>

                    <div class="success-box">
                        <i class="fas fa-user-shield"></i>
                        <strong>No account and no ESI scopes required.</strong> Buyback Manager never starts its own EVE SSO request and never asks for scopes, so it cannot change or reduce what anyone has already granted SeAT. Appraising touches no character data at all, and contract detection reads the corporation's (or the designated operator's) own contract feed &mdash; never the seller's. Signing in is optional and only adds appraisal history.
                    </div>

                    <h4>Branding &amp; layout</h4>
                    <ul>
                        <li><strong>Headline, blurb and footer</strong> &mdash; your own copy for the hero and a small footer line.</li>
                        <li><strong>Accent colour</strong> &mdash; recolours the buttons and highlights.</li>
                        <li><strong>Background image</strong> &mdash; uploaded and shown behind the hero, with a <strong>dim overlay</strong> slider so the text stays readable over any image.</li>
                        <li><strong>Logo</strong> &mdash; defaults to the EVE corporation logo; upload your own. Choose its background: a dark box (default), no box (logo straight on the image, best for a transparent logo), or a white square.</li>
                        <li><strong>Page layout</strong> &mdash; stacked (rates above instructions) or side by side (rates left, instructions right); it stacks on mobile.</li>
                    </ul>

                    <h4>Rates &amp; estimate</h4>
                    <ul>
                        <li><strong>Show the rates section</strong> &mdash; the base rate and price-lock cards.</li>
                        <li><strong>Most wanted</strong> &mdash; flag any pricing rule as featured on the Pricing Rules page to spotlight it with a star.</li>
                        <li><strong>List all pricing rules</strong> &mdash; show every rule, not just the featured ones (excluded items are never shown).</li>
                        <li><strong>Market and price side</strong> &mdash; show the sourced market plus each rule's side (buy / sell / split).</li>
                        <li><strong>No-login appraisal</strong> &mdash; the calculator that lets anyone paste items, see what you'd pay, and get a contract key. This is the primary seller flow, so it is on by default; turn it off for an internal-only programme. Rate limited, and it does expose your rates to anyone with the link.</li>
                    </ul>

                    <div class="purple-box">
                        <i class="fas fa-shield-alt"></i>
                        <strong>What is never shown.</strong> The public page only displays your rates and the copy you write. Member names, traded volumes, and contract history are never exposed.
                    </div>
                    <div class="info-box">
                        <i class="fas fa-server"></i>
                        Uploaded images are served from your own SeAT server, so the page works without <code>php artisan storage:link</code> and satisfies the content-security policy on Docker and bare-metal alike.
                    </div>
                </div>
            </div>

            {{-- CUSTOM STYLING --}}
            <div id="custom-styling" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-paint-brush"></i> Custom Styling</h3>
                    <p>Every Buyback Manager page is wrapped in a stable set of CSS hook classes, so you can restyle any part of the plugin from SeAT's own custom stylesheet or your theme — without editing the plugin's files, which are overwritten on every update.</p>

                    <h4>The global hook</h4>
                    <p>Every plugin page (Appraisal, My Appraisals, Contracts, Statistics, Settings, Help) renders inside a single wrapper:</p>
                    <ul>
                        <li><code>.buyback-manager-wrapper</code> — present on every Buyback Manager page. Style it to affect the whole plugin at once, or use it as a prefix to scope an override so it only applies inside Buyback Manager.</li>
                    </ul>

                    <h4>Component classes</h4>
                    <p>Most of the plugin's chrome is built from a few shared classes — target these to restyle a widget everywhere it appears:</p>
                    <ul>
                        <li><code>.card-dark</code> — the dark card chrome (header + body) used on every page.</li>
                        <li><code>.card-title</code> / <code>.card-tools</code> — the heading and the button/filter cluster in a card header.</li>
                        <li><code>.btn-bb-primary</code> / <code>.btn-bb-secondary</code> — the primary and secondary buttons.</li>
                        <li><code>.table-bb-styled</code> — the data tables (Contracts, Statistics, and so on).</li>
                        <li><code>.contract-status</code> — the status pills on the Contracts list.</li>
                        <li><code>.pricing-rule</code> — a rule row on the Pricing Rules page.</li>
                        <li><code>.info-box</code> / <code>.success-box</code> / <code>.warning-box</code> / <code>.purple-box</code> — the coloured callouts (as seen on this page).</li>
                    </ul>

                    <h4>Example overrides</h4>

                    <p>Tint the background of every Buyback Manager page:</p>
                    <pre style="background:#1a1d24; border:1px solid #2b3038; padding:12px; border-radius:6px; overflow:auto; color:#e6edf3; margin:8px 0;"><code>.buyback-manager-wrapper { background-color: #0d0d12; }</code></pre>

                    <p>Recolour the primary buttons to your corp colour:</p>
                    <pre style="background:#1a1d24; border:1px solid #2b3038; padding:12px; border-radius:6px; overflow:auto; color:#e6edf3; margin:8px 0;"><code>.buyback-manager-wrapper .btn-bb-primary {
    background-color: #1d9e75;
    border-color: #1d9e75;
}</code></pre>

                    <p>Change the card border accent:</p>
                    <pre style="background:#1a1d24; border:1px solid #2b3038; padding:12px; border-radius:6px; overflow:auto; color:#e6edf3; margin:8px 0;"><code>.buyback-manager-wrapper .card-dark { border-color: #1d9e75; }</code></pre>

                    <div class="info-box">
                        <i class="fas fa-folder-open"></i>
                        <strong>Where to add your CSS:</strong> SeAT auto-loads a stylesheet named <code>custom-layout.css</code> when it exists. On a bare-metal install, drop it into SeAT's <code>public/</code> directory (for example <code>/var/www/seat/public/custom-layout.css</code>). On SeAT Docker, place it in your mounted custom directory and map it to <code>/var/www/seat/public/</code>, then bring the stack back up. It is detected automatically — there is no setting to toggle. See the <a href="https://eveseat.github.io/docs/styling/" target="_blank" rel="noopener">SeAT styling docs</a>.
                    </div>

                    <div class="purple-box">
                        <i class="fas fa-globe"></i>
                        <strong>The public landing page is separate.</strong> It renders outside the SeAT layout, so it does not use these classes and SeAT's <code>custom-layout.css</code> does not reach it. Theme it instead from its own built-in controls — accent colour, background image, logo and dim overlay — under <strong>Settings &rarr; Public Page</strong>.
                    </div>

                    <div class="info-box">
                        <i class="fas fa-lightbulb"></i>
                        Keep your tweaks in SeAT's custom stylesheet, never in the plugin's own files — the plugin's CSS is replaced on every update, but your <code>custom-layout.css</code> survives.
                    </div>
                </div>
            </div>

            {{-- COMMANDS & CONFIG --}}
            <div id="commands" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-terminal"></i> Commands &amp; configuration</h3>

                    <h4>Scheduled commands</h4>
                    <p>Buyback Manager registers one Artisan command and its schedule automatically &mdash; you normally never run it by hand. The Diagnostic page's Health Checks tab confirms it is present with the right cadence.</p>
                    <table class="plugin-info-table">
                        <tr><td><code>buyback-manager:sync-contracts</code></td><td>Every 15 minutes. Reads SeAT's synced contracts, resolves each appraisal key, compares the contract against its quote and raises review flags, fires the lifecycle events and Discord notifications, sends idle-contract nudges, and prunes old appraisals and notification logs.</td></tr>
                    </table>
                    <div class="info-box">
                        <i class="fas fa-play"></i>
                        To force a detection pass on demand without waiting for the schedule, use the <strong>Sync Now</strong> button on the Diagnostic page.
                    </div>

                    <h4>Configuration variables</h4>
                    <p>Almost all configuration is per-corporation on the <strong>Settings</strong> page (rates, provider, contract target, locations, public page). A small set of code-level defaults lives in the plugin's config file (<code>Config/buyback-manager.config.php</code>) and rarely needs changing:</p>
                    <table class="plugin-info-table">
                        <tr><td><code>defaults.base_percentage</code></td><td>Buyback rate a new corporation setting starts with (90).</td></tr>
                        <tr><td><code>defaults.price_source</code></td><td>Price source a new setting starts with (<code>jita</code>).</td></tr>
                        <tr><td><code>defaults.jita_region_id</code></td><td>The Forge region id used for Jita pricing (10000002).</td></tr>
                        <tr><td><code>public.upload_disk</code></td><td>Filesystem disk for public-page image uploads (<code>public</code>). Point it at a shared or S3 disk on a multi-server install.</td></tr>
                        <tr><td><code>public.max_upload_kb</code></td><td>Per-image size ceiling for public-page uploads (5120 KB).</td></tr>
                    </table>

                    <h4>Housekeeping</h4>
                    <p>Appraisals are generated freely and with no login, so they are pruned automatically inside the sync cycle &mdash; there is no extra cron to add. Retention is two tier and set per corporation on the Settings page:</p>
                    <table class="plugin-info-table">
                        <tr><td>Keep item detail</td><td>Line-by-line appraisal rows, the bulk of the data (default 14 days). Safe to drop early, because a matched contract keeps its own copy of the item snapshot.</td></tr>
                        <tr><td>Keep appraisals</td><td>The appraisal totals, kept far longer for statistics (default 180 days).</td></tr>
                    </table>
                </div>
            </div>

            {{-- TROUBLESHOOTING --}}
            <div id="troubleshooting" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-stethoscope"></i> Troubleshooting</h3>
                    <p>The admin only <strong>Diagnostic</strong> page is the first stop for anything unexpected. It is not in the sidebar; reach it at <code>/buyback-manager/diagnostic</code> (requires the settings permission). Each tab opens with a short explainer.</p>

                    <table class="plugin-info-table">
                        <tr><td>Prices wrong or zero</td><td>Diagnostic &gt; Settings Health validates each provider config; Master Test live tests a provider.</td></tr>
                        <tr><td>Contract not picked up</td><td>Confirm the appraisal key is in the Description, then Diagnostic &gt; Contract Trace walks the pipeline.</td></tr>
                        <tr><td>Contract list stale</td><td>Diagnostic &gt; Health Checks shows the last sync time and has a Sync Now button.</td></tr>
                        <tr><td>Discord not arriving</td><td>Diagnostic &gt; Notification Testing lists every webhook with its last result and a test button.</td></tr>
                        <tr><td>Tables or schedules</td><td>Diagnostic &gt; Health Checks and Data Integrity verify tables, schedules, and consistency.</td></tr>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-clock"></i>
                        <strong>Scheduled job:</strong> <code>buyback-manager:sync-contracts</code> detects and checks contracts every 15 minutes, and handles housekeeping. It registers automatically; the Health Checks tab confirms it is present.
                    </div>
                </div>
            </div>

            {{-- FAQ --}}
            <div id="faq" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-question-circle"></i> Frequently asked questions</h3>

                    <div class="faq-item">
                        <div class="faq-question"><span>Do I need Manager Core?</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer">No. Buyback Manager works standalone on Fuzzwork (free) or Janice. Manager Core only adds regional pricing, a shared cache, and a cross plugin event feed. You can install it later with no other changes.</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question"><span>Why is my contract not showing up?</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer">The most common reason is a missing appraisal key in the contract Description. A contract without a valid key is ignored by design. Check the key, then use Diagnostic &gt; Contract Trace. Also confirm the contract target matches where the contract was sent.</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question"><span>Can a member contract from an alt?</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer">Yes. The key is not tied to a character or an account, so a member can appraise on one character and contract from another. Whoever issued the contract is recorded from the contract itself, and abuse is caught by the item and price checks rather than by an account gate.</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question"><span>How fresh are the prices?</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer">Fuzzwork and Janice prices are cached for the TTL set on each corporation setting (in minutes). Lower it for fresher prices, raise it to reduce upstream calls. Manager Core manages its own freshness and bypasses the local cache.</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question"><span>What happens if someone contracts at the wrong price?</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer">The contract is still tracked, but flagged with the asked price, the quoted value and the percentage difference, and announced through the Contract Flagged category. Nothing is paid automatically, so a director reviews it and either honours it or declines the contract in game. Set the tolerance per corporation on the Settings page.</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question"><span>Why does player target mode need a token?</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer">Player mode reads a personal contract feed, which is only visible to the receiver. SeAT needs the designated character's token with the read_character_contracts scope to see those contracts.</div>
                    </div>
                </div>
            </div>

            {{-- SUPPORT --}}
            <div id="support" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-life-ring"></i> Support</h3>
                    <p>Questions, bug reports, and feature requests are welcome.</p>
                    <table class="plugin-info-table">
                        <tr><td><i class="fas fa-envelope"></i> Email</td><td><a href="mailto:mattfalahe@gmail.com">mattfalahe@gmail.com</a></td></tr>
                        <tr><td><i class="fab fa-discord"></i> Discord</td><td><a href="https://discord.gg/azquy29nqs" target="_blank" rel="noopener">discord.gg/azquy29nqs</a></td></tr>
                        <tr><td><i class="fab fa-github"></i> GitHub</td><td><a href="https://github.com/MattFalahe/Buyback-Manager" target="_blank" rel="noopener">github.com/MattFalahe/Buyback-Manager</a></td></tr>
                    </table>

                    <div class="purple-box">
                        <i class="fas fa-puzzle-piece"></i>
                        <strong>Part of a suite:</strong> Buyback Manager is one of a family of EVE Online corporation tools for SeAT. With Manager Core installed they share pricing and an event feed, but each works on its own.
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
    $(document).ready(function () {
        // Section navigation: nav-link[data-section] -> #section
        $('.help-nav .nav-link').on('click', function (e) {
            e.preventDefault();
            var section = $(this).data('section');
            $('.help-nav .nav-link').removeClass('active');
            $(this).addClass('active');
            $('.help-section').removeClass('active');
            $('#' + section).addClass('active');
            window.location.hash = section;
            $('html, body').animate({ scrollTop: 0 }, 150);
        });

        // In-content jump links.
        $('.js-goto').on('click', function (e) {
            e.preventDefault();
            $('.help-nav .nav-link[data-section="' + $(this).data('goto') + '"]').click();
        });

        // Open the section named in the URL hash on load.
        if (window.location.hash) {
            var hash = window.location.hash.substring(1);
            $('.help-nav .nav-link[data-section="' + hash + '"]').click();
        }

        // FAQ accordion.
        $('.faq-question').on('click', function () {
            $(this).closest('.faq-item').toggleClass('open');
        });

        // Search: filter help-cards by text within the active section.
        var searchTimeout;
        $('#helpSearch').on('input', function () {
            clearTimeout(searchTimeout);
            var query = $(this).val().toLowerCase();
            if (query.length < 2) {
                $('.help-card').show();
                return;
            }
            searchTimeout = setTimeout(function () {
                $('.help-card').each(function () {
                    $(this).toggle($(this).text().toLowerCase().indexOf(query) !== -1);
                });
            }, 250);
        });
    });
</script>
@endpush
