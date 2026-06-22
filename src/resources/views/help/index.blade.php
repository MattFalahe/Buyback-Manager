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
                        <li class="nav-item"><a href="#" class="nav-link" data-section="workflow"><i class="fas fa-route"></i> Offer Workflow</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="detection"><i class="fas fa-file-contract"></i> Contracts &amp; Detection</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="notifications"><i class="fab fa-discord"></i> Discord Notifications</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="public-page"><i class="fas fa-globe"></i> Public Page</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="integration"><i class="fas fa-plug"></i> Manager Core</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="permissions"><i class="fas fa-user-shield"></i> Permissions</a></li>
                        <li class="nav-item"><a href="#" class="nav-link" data-section="custom-styling"><i class="fas fa-paint-brush"></i> Custom Styling</a></li>
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
                    <p>Buyback Manager turns a corporation buyback programme into a simple, fair workflow: a member appraises their items, publishes a locked offer, and creates an in game contract that Buyback Manager detects and records automatically. It runs on its own with free pricing, and grows richer when the rest of the suite is installed.</p>
                    <p>This page is the full reference. Use the navigation on the left to jump to setup, pricing, the offer workflow, contract detection, Discord notifications, and troubleshooting.</p>
                </div>

                {{-- What is Buyback Manager --}}
                <div class="help-card">
                    <h3><i class="fas fa-exchange-alt"></i> What is Buyback Manager?</h3>
                    <p>Buyback Manager lets a corporation buy items from its members at a configurable percentage of market value. A member pastes their items into the appraisal tool, gets a valuation, and publishes it as an <strong>offer</strong>. The offer freezes the prices and hands back a short offer id. The member creates an in game contract for those items and writes that id into the contract's Description. Buyback Manager then detects the contract, pairs it to the frozen offer, and records the deal.</p>

                    <div class="purple-box">
                        <i class="fas fa-quote-left"></i>
                        <strong>Mental model:</strong> an offer is a price quote with a receipt number. The quote is locked the moment it is published, so the value never drifts while the member is hauling and contracting. The receipt number (the offer id) is how the contract is matched back to the quote.
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
                            <p>Frozen-price offers with a configurable lock window, so a member always gets exactly what they were quoted.</p>
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
                            <h5>Offer matching</h5>
                            <p>Contracts pair to offers by a short id, even from an alt on the same account. Noise contracts are ignored.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fab fa-discord"></i>
                            <h5>Discord notifications</h5>
                            <p>Per corp or global webhooks, six categories, role mentions, and a routing map. De duplicated and rate limited.</p>
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
                        <li><strong>Enable the setting and test:</strong> run an <a href="{{ route('buyback.appraisal.index') }}">Appraisal</a> to confirm prices look right, then publish a test offer.</li>
                    </ol>

                    <div class="success-box">
                        <i class="fas fa-check-circle"></i>
                        <strong>That is the whole setup.</strong> Once a corporation setting is enabled, any logged in member can appraise and publish offers against it. Contract detection runs automatically on a schedule.
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
                </div>
            </div>

            {{-- WORKFLOW --}}
            <div id="workflow" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-route"></i> The offer workflow</h3>
                    <p>This is the lifecycle every buyback follows, from appraisal to a recorded deal.</p>

                    <ol class="step-by-step">
                        <li><strong>Appraise.</strong> A member pastes their items into the Appraisal page and gets a valuation using the corporation's provider and rules.</li>
                        <li><strong>Publish as an offer.</strong> The prices are frozen and the offer is given a short id like <code>bb-zj2cc262</code> and an expiry (the lock window you set, in hours).</li>
                        <li><strong>Create the EVE contract.</strong> The member makes an item exchange contract to the target the offer names, and pastes the offer id into the contract's Description field.</li>
                        <li><strong>Detection.</strong> Buyback Manager syncs contracts every 15 minutes (or immediately via the Diagnostic page's Sync Now button), reads the offer id from the Description, and pairs the contract to the pending offer.</li>
                        <li><strong>Match and notify.</strong> The contract is recorded at the offer's frozen value, the offer is marked matched, and any configured Discord webhooks announce it.</li>
                    </ol>

                    <h4>Offer statuses</h4>
                    <table class="plugin-info-table">
                        <tr><td><code>pending</code></td><td>Published and waiting for a matching contract.</td></tr>
                        <tr><td><code>matched</code></td><td>A contract referencing this offer was detected.</td></tr>
                        <tr><td><code>expired</code></td><td>The lock window passed with no contract (swept every 5 minutes).</td></tr>
                        <tr><td><code>cancelled</code></td><td>Withdrawn by the member or an admin.</td></tr>
                        <tr><td><code>rejected</code></td><td>Declined by the operator (player target mode), with a reason.</td></tr>
                    </table>

                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>The offer id must be in the Description.</strong> A contract with no valid offer id is not treated as a buyback and is ignored. This keeps unrelated and deleted contracts out of the Contracts list.
                    </div>

                    <div class="info-box">
                        <i class="fas fa-users"></i>
                        <strong>Alts are fine:</strong> the contract can be issued by any character on the same SeAT account as the member who published the offer. A different account cannot claim someone else's offer id.
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
                        <strong>What gets recorded:</strong> only contracts that carry a valid, claimable offer id appear in the <a href="{{ route('buyback-manager.contracts.index') }}">Contracts</a> list. An id that does not resolve is logged as an unmatched attempt for review, but no contract row is created.
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
                        <tr><td>Offer Published</td><td>An offer is published (also used for expiry and cancellation notices).</td></tr>
                        <tr><td>Offer Matched</td><td>An offer is paired to a contract.</td></tr>
                        <tr><td>Offer Rejected</td><td>An offer or contract is declined.</td></tr>
                        <tr><td>Contract Unmatched</td><td>A contract referenced an offer id that did not resolve (review signal).</td></tr>
                        <tr><td>Contract Completed</td><td>A buyback contract is finished.</td></tr>
                        <tr><td>Contract Cancelled</td><td>A buyback contract is cancelled.</td></tr>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-at"></i>
                        <strong>Role mentions and the Routing Map:</strong> the role picker lists Discord roles detected from your SeAT Discord integration. The Routing Map tab on the Settings page shows which webhook announces which category, so you can confirm coverage and spot gaps.
                    </div>

                    <div class="success-box">
                        <i class="fas fa-shield-alt"></i>
                        <strong>No double pings, no spam:</strong> each event sends at most once per webhook (duplicates are de duplicated), and each webhook is rate limited. A contract's first sighting fires a single matched or unmatched message, never both.
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

                    <h4>What it adds</h4>
                    <ul>
                        <li><strong>Regional market pricing</strong> through Manager Core's pricing service, with its own shared cache.</li>
                        <li><strong>Pricing preferences</strong> in Manager Core, where an admin can override the market used centrally.</li>
                        <li><strong>EventBus</strong> publishing of offer and contract events so other plugins can react.</li>
                    </ul>

                    <div class="purple-box">
                        <i class="fas fa-broadcast-tower"></i>
                        <strong>Events published to the bus:</strong> offers (<code>published</code>, <code>matched</code>, <code>expired</code>, <code>cancelled</code>, <code>rejected</code>) and contracts (<code>created</code>, <code>matched</code>, <code>unmatched</code>, <code>completed</code>, <code>cancelled</code>, <code>rejected</code>). These are an integration surface for other plugins, separate from the Discord categories above.
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
                        <strong>Appraisal and offers are open to all members:</strong> any logged in SeAT user can run an appraisal and publish an offer against an enabled corporation, without either permission. The permissions above gate the director facing surfaces only.
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
                        The <strong>Log in to appraise &amp; sell</strong> button sends visitors through EVE SSO into the normal appraisal flow. Viewing the page needs no login; only publishing an offer does.
                    </div>

                    <h4>Branding</h4>
                    <ul>
                        <li><strong>Headline, blurb and footer</strong> &mdash; your own copy for the hero and a small footer line.</li>
                        <li><strong>Accent colour</strong> &mdash; recolours the buttons and highlights.</li>
                        <li><strong>Background image</strong> &mdash; uploaded and shown behind the hero, with a <strong>dim overlay</strong> slider so the text stays readable over any image.</li>
                        <li><strong>Logo</strong> &mdash; defaults to the EVE corporation logo; upload your own, and optionally sit it on a <strong>solid square backdrop</strong> so a logo with transparency stands out.</li>
                    </ul>

                    <h4>Rates</h4>
                    <ul>
                        <li><strong>Show the rates section</strong> &mdash; the base rate and price-lock cards.</li>
                        <li><strong>Most wanted</strong> &mdash; flag any pricing rule as featured on the Pricing Rules page to spotlight it with a star.</li>
                        <li><strong>List all pricing rules</strong> &mdash; show every rule, not just the featured ones (excluded items are never shown).</li>
                        <li><strong>Market and price side</strong> &mdash; show the sourced market plus each rule's side (buy / sell / split).</li>
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
                    <p>Every plugin page (Appraisal, My Offers, Contracts, Statistics, Settings, Help) renders inside a single wrapper:</p>
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

            {{-- TROUBLESHOOTING --}}
            <div id="troubleshooting" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-stethoscope"></i> Troubleshooting</h3>
                    <p>The admin only <strong>Diagnostic</strong> page is the first stop for anything unexpected. It is not in the sidebar; reach it at <code>/buyback-manager/diagnostic</code> (requires the settings permission). Each tab opens with a short explainer.</p>

                    <table class="plugin-info-table">
                        <tr><td>Prices wrong or zero</td><td>Diagnostic &gt; Settings Health validates each provider config; Master Test live tests a provider.</td></tr>
                        <tr><td>Contract not picked up</td><td>Confirm the offer id is in the Description, then Diagnostic &gt; Contract Trace walks the pipeline.</td></tr>
                        <tr><td>Contract list stale</td><td>Diagnostic &gt; Health Checks shows the last sync time and has a Sync Now button.</td></tr>
                        <tr><td>Discord not arriving</td><td>Diagnostic &gt; Notification Testing lists every webhook with its last result and a test button.</td></tr>
                        <tr><td>Tables or schedules</td><td>Diagnostic &gt; Health Checks and Data Integrity verify tables, schedules, and consistency.</td></tr>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-clock"></i>
                        <strong>Scheduled jobs:</strong> <code>buyback-manager:sync-contracts</code> detects and updates contracts (every 15 minutes) and <code>buyback-manager:expire-offers</code> sweeps lapsed offers (every 5 minutes). Both register automatically; the Health Checks tab confirms they are present.
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
                        <div class="faq-answer">The most common reason is a missing offer id in the contract Description. A contract without a valid offer id is ignored by design. Check the id, then use Diagnostic &gt; Contract Trace. Also confirm the contract target matches where the contract was sent.</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question"><span>Can a member contract from an alt?</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer">Yes. The contract can be issued by any character on the same SeAT account as the member who published the offer. A different account cannot claim someone else's offer id.</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question"><span>How fresh are the prices?</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer">Fuzzwork and Janice prices are cached for the TTL set on each corporation setting (in minutes). Lower it for fresher prices, raise it to reduce upstream calls. Manager Core manages its own freshness and bypasses the local cache.</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question"><span>What happens when an offer expires?</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer">An offer past its lock window is flipped to expired by a job that runs every 5 minutes. The member can appraise again to get a fresh quote at current prices.</div>
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
