@extends('web::layouts.grids.12')

@section('title', 'Help & Documentation')
@section('page_header', 'Help & Documentation')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=6">
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
                        <li class="nav-item"><a href="#" class="nav-link" data-section="plugins"><i class="fas fa-puzzle-piece"></i> Plugins &amp; Ecosystem</a></li>
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
                        <span class="badge badge-secondary" style="font-size: 0.9rem; vertical-align: middle;">{{ ctype_digit(substr($version, 0, 1)) ? 'v' . $version : $version }}</span>
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
                        <a href="https://github.com/MattFalahe/Buyback-Manager/blob/main/CHANGELOG.md" class="quick-link" target="_blank" rel="noopener" style="padding: 10px;">
                            <i class="fas fa-list" style="font-size: 1rem; margin-bottom: 4px;"></i> Changelog
                        </a>
                        <a href="https://github.com/MattFalahe/Buyback-Manager/issues" class="quick-link" target="_blank" rel="noopener" style="padding: 10px;">
                            <i class="fas fa-bug" style="font-size: 1rem; margin-bottom: 4px;"></i> Report Issues
                        </a>
                        <a href="https://github.com/MattFalahe/Buyback-Manager/blob/main/README.md" class="quick-link" target="_blank" rel="noopener" style="padding: 10px;">
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

                {{-- What's New in v1 is the picture of the whole major version and never
                     names a point release. Later updates go in a Recent changes box under
                     it; the changelog keeps the full record. --}}
                <div class="whats-new-box">
                    <h4><span><i class="fas fa-exchange-alt"></i> What's New in v1</span> <span class="whats-new-tag">The Ecosystem Era: Fair Trade</span></h4>
                    <p>Version 1 is the first release of Buyback Manager. A seller gets a quote, puts its key on an in-game contract, and the contract is found and checked against the quote:</p>
                    <ul>
                        <li><strong>Appraisal keys, no login needed.</strong> Paste items on the public page or in the plugin, get a price and a key, and paste the key into the contract's Description. My Appraisals keeps a logged in seller's quotes.</li>
                        <li><strong>Every contract matched to its quote.</strong> Contracts to your corporation, to another corporation or to one player are read on a schedule, the key finds the quote, and the asked price and items are checked against it. Anything that does not add up is flagged for review.</li>
                        <li><strong>A key shows where its contract is.</strong> Opening a quote's link shows whether its contract has been received, paid, cancelled or declined, and until when the quote holds.</li>
                        <li><strong>Pricing you control.</strong> Fuzzwork, Janice or Manager Core, a fallback provider behind it and the last cached price behind that. Rules per item, group or category, buy, sell or split sides, exclusions, and the places a contract may be handed in.</li>
                        <li><strong>Ore paid on what it refines into.</strong> A rule can value ore, ice and moon ore at the minerals they reprocess into, at the yield you refine at, since raw ore barely trades.</li>
                        <li><strong>Haul fees where you need them.</strong> Set so much ISK per m3 at any accepted location. Every quote shows its m3 and the contract price at each location with a fee, and the contract is checked against the price for where it was made.</li>
                        <li><strong>A guard for thin markets.</strong> On Fuzzwork prices, one buy order far above the rest can't set a quote's price: the item is priced at the top-5% average instead, and the quote says so.</li>
                        <li><strong>A public page</strong> with your rates and the appraisal tool, branded per corporation and named after whoever the contracts go to.</li>
                        <li><strong>Discord notifications</strong> per corporation or global, eight categories, role mentions and a routing map, including a warning when SeAT can no longer read the contracts made to you.</li>
                        <li><strong>Each programme run by the people you name.</strong> An admin names each programme's managers and viewers, as SeAT accounts or whole roles, so a member can run a buyback under an alt corporation without reaching yours. Contracts, Statistics and Analytics show each person what they are named on, and admins get a Diagnostic page.</li>
                        <li><strong>Secrets stored encrypted.</strong> The Janice API key and the webhook URLs are encrypted with your SeAT app key.</li>
                        <li><strong>Part of the ecosystem.</strong> With Manager Core each corporation prices at any of its markets, through the provider it picks there, and every contract event is published, which is how HR Manager credits a seller's buybacks. See <a href="#plugins" data-goto="plugins">Plugins &amp; Ecosystem</a>.</li>
                    </ul>
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
                        <strong>Works standalone:</strong> it runs on its own using free Fuzzwork pricing or your Janice API key. Manager Core is optional: once version 1.2.0 or later is installed you can pick it as the price provider, for its markets and citadels, and other plugins can follow buyback events through it. Nothing here requires it.
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
                            <p>Fuzzwork or Janice at the trade hub you pick, with a fallback provider and the last cached price behind it.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-sliders-h"></i>
                            <h5>Pricing rules</h5>
                            <p>Per item, group, or category percentages with buy, sell, or split price sides and exclusions.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-gem"></i>
                            <h5>Refined value</h5>
                            <p>Pay for ore, ice and moon ore on the minerals they refine into, at your programme's refining yield or one set on the rule.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-truck"></i>
                            <h5>Haul fees</h5>
                            <p>ISK per m3 off the quote at the locations you name, with ships, capital modules and containers counted packaged and the price for each location on the quote.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-shield-alt"></i>
                            <h5>Thin-market guard</h5>
                            <p>On Fuzzwork prices, a lone buy order far above the rest is passed over for the top-5% average, so it can't inflate a quote.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-tag"></i>
                            <h5>Checked, not guessed</h5>
                            <p>We compare the asked price and items against the quote, and flag anything off for review. Noise contracts are ignored.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fab fa-discord"></i>
                            <h5>Discord notifications</h5>
                            <p>Per corp or global webhooks, eight categories, role mentions, and a routing map. De duplicated and rate limited.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-globe"></i>
                            <h5>Public page</h5>
                            <p>An optional branded landing page with your rates and a no-login appraisal tool anyone can use, and a closed notice while it is off.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-chart-pie"></i>
                            <h5>Statistics and analytics</h5>
                            <p>What the programme buys, who sells to it, and how many quotes turn into a sale, per corporation and period.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-user-shield"></i>
                            <h5>Named managers</h5>
                            <p>Each programme lists who runs it and who watches it, as SeAT accounts or roles. A character in the corporation grants nothing.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-stethoscope"></i>
                            <h5>Diagnostics</h5>
                            <p>An admin diagnostic page with health checks, settings audit, and a single-contract trace.</p>
                        </div>
                    </div>

                    @include('buyback-manager::help.partials.ecosystem-features')
                </div>

            </div>

            {{-- GETTING STARTED --}}
            <div id="getting-started" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-rocket"></i> Getting started</h3>
                    <p>An admin (someone with the <code>buyback-manager.admin</code> permission) adds a programme and names who runs it. Its managers set it up once, and members then use it without any further configuration.</p>

                    <ol class="step-by-step">
                        <li><strong>Open Settings.</strong> Go to <a href="{{ route('buyback-manager.settings.index') }}">Buyback Manager &gt; Settings</a> and add the corporation that will run the programme. Only an admin can add or delete one.</li>
                        <li><strong>Name who runs it.</strong> Under <em>Programme Access</em>, name its managers and anyone who should only watch it, as SeAT accounts or whole SeAT roles (see <a href="#" class="js-goto" data-goto="permissions">Permissions</a>). Nobody named means admins alone.</li>
                        <li><strong>Choose a contract target:</strong> your own corporation, a specific corporation by name, or a specific player. This drives both the instructions members see and how detection works (see <a href="#" class="js-goto" data-goto="detection">Contracts &amp; Detection</a>).</li>
                        <li><strong>Pick a pricing provider</strong> (Fuzzwork, Janice, or Manager Core when it is installed), its market and a fallback provider, and set your base percentage (the default is 90%). <em>Test Connection</em> checks the provider before you save.</li>
                        <li><strong>Add pricing rules (optional)</strong> to override the base percentage for specific items, groups, or categories.</li>
                        <li><strong>Add a Discord webhook (optional)</strong> on the Settings page Discord Webhooks tab, and pick a role to mention.</li>
                        <li><strong>Enable the setting and test:</strong> run an <a href="{{ route('buyback.appraisal.index') }}">Appraisal</a> to confirm prices look right and that you get a key back.</li>
                    </ol>

                    <div class="success-box">
                        <i class="fas fa-check-circle"></i>
                        <strong>That is the whole setup.</strong> Once a corporation setting is enabled, anyone can appraise against it and get a key, from the plugin or the public page. Contract detection runs automatically on a schedule.
                    </div>
                </div>
            </div>

            {{-- PLUGINS & ECOSYSTEM --}}
            <div id="plugins" class="help-section">
                @include('buyback-manager::help.partials.ecosystem-plugins')
            </div>

            {{-- PRICING --}}
            <div id="pricing" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-coins"></i> Pricing &amp; cache</h3>
                    <p>Every appraisal asks for both a buy price and a sell price per item, because a pricing rule can pay on either side of the spread or the midpoint. Each corporation picks its price provider, the market it prices at, and a fallback provider on the Settings page.</p>

                    <div class="feature-grid">
                        <div class="feature-item">
                            <i class="fas fa-gift"></i>
                            <h5>Fuzzwork</h5>
                            <p>Free, no API key. Prices at Jita, Amarr, Dodixie, Hek or Rens, each at its main station, or at any region by its id.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-bolt"></i>
                            <h5>Janice</h5>
                            <p>Needs a Janice API key, and prices at Jita, Amarr, Dodixie, Hek or Rens. Once saved the key is never shown again, and it is stored encrypted with SeAT's app key, like the Discord webhook URLs. If the app key changes, enter both again.</p>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-plug"></i>
                            <h5>Manager Core</h5>
                            <p>Offered only when Manager Core 1.2.0 or later is installed. Prices at any market added there, citadels included, through the market's own provider or one the corporation names.</p>
                        </div>
                    </div>

                    <h4>Where each price comes from</h4>
                    <p>One source having a bad day never stops a quote. Each item takes its price from the first of these that has one:</p>
                    <ol>
                        <li><strong>The provider</strong> at the corporation's market. A Fuzzwork or Janice answer is reused for the setting's cache TTL (0 asks every time). Manager Core keeps prices of its own, so it is read on every quote. A price of its that is over 8 hours old goes to the fallback, and is kept only when the fallback has none.</li>
                        <li><strong>The fallback provider</strong>, when the provider fails outright, and for any item it could not answer for. It prices at the same market, or at Jita when it cannot price there.</li>
                        <li><strong>The last cached price</strong>, however old, when neither of them can.</li>
                    </ol>
                    <p>With <em>Use Jita's price for a side with no orders at the market</em> ticked and a market other than Jita, a side nobody is trading at the market takes Jita's price for that side.</p>

                    <h4>Thin markets</h4>
                    <p>Where few people are buying, one buy order far above the rest can set the price for a whole quote. Fuzzwork sends the average of the top 5% of buy orders beside the best one, whether Buyback asks it directly or Manager Core prices through it. When the best buy order stands more than the corporation's <em>Thin-market guard</em> above that average (10% by default), the item is priced at the average instead. The item is marked <em>thin market</em> on the quote, and the quote says how many items it passed over. Only a line paid on the buy side or the midpoint changes, and a refined line is marked when one of its minerals was. Set the guard to 0 to turn it off.</p>
                    <p>Prices from Janice, Goonpraisal and the other Manager Core providers aren't guarded, and stand at the best buy order. Their figures by that name aren't the same average, and some sit far under the market: one 0.01 ISK buy order for millions of units sets Goonpraisal's, which would price a T2 drone at 0.01 ISK.</p>

                    <div class="info-box">
                        <i class="fas fa-receipt"></i>
                        <strong>The quote says so.</strong> When prices did not all come from the provider, the appraisal carries a short note saying how many came from the fallback, the cache or Jita. The public page shows that note only when its pricing detail is switched on.
                    </div>

                    <div class="info-box">
                        <i class="fas fa-hourglass-half"></i>
                        <strong>Hamsters at work.</strong> While a quote is priced, on the appraisal page and the public page, one of four hamster scenes plays, picked at random each time: the server room, the call centre, the Jita run or the trading floor. They stay up for at least three seconds, so a quote that needs no fresh prices still gets them, and the line under them changes every few seconds. Beside the paste box on both pages, another hamster (also one of four, picked at random) waits for loot, and counts the lines as soon as anything is pasted. When nothing could be priced because no provider answered at all (the provider, its fallback and the cache all came up empty), they are out of coffee, and the seller is asked to try again in a few minutes. A list of items nobody trades is not that: those are listed as <em>no market price</em>.
                    </div>

                    <div class="warning-box">
                        <i class="fas fa-ban"></i>
                        <strong>No price means not accepted.</strong> An item nothing could price, on the side its rule pays on, is listed as not accepted with <em>no market price</em>. It is never valued at zero or at a guess, so the seller knows to leave it out of the contract.
                    </div>

                    <h4>When the provider stops answering</h4>
                    <p>A request that fails, or an answer with no prices at all for items it has priced before, counts as the provider not answering. Items nobody trades do not. The Settings list then shows <em>Not answering since</em> on that corporation, with what the quotes use meanwhile, and a webhook subscribed to <strong>Pricing problems</strong> hears about it once when it stops and once when it answers again, never on every quote.</p>

                    <div class="success-box">
                        <i class="fas fa-flask"></i>
                        <strong>Test before you save.</strong> <em>Test Connection</em> on the Settings form prices Tritanium at the market you picked, with the key you typed or the one already saved, and changes nothing. The Diagnostic page's Health Checks tab shows how each corporation's provider last answered.
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
                    <p>A rule with no side of its own, and the default rate, pay on the sell side, or on the <em>Price method</em> picked for Janice when Janice is the provider.</p>

                    <h4>Paying on what ore refines into</h4>
                    <p>Raw ore barely trades, so its own buy orders say little about what it is worth. Tick <strong>Pay on what it refines into</strong> on a rule and each item it covers is valued at the minerals it reprocesses into, with the rule's percentage and price side applied to the minerals. With a yield of 87.5% and a rate of 90% of buy, a seller is paid 90% of what the minerals from refining at 87.5% would sell to buy orders for. The recipes come from SeAT's SDE, so new ore needs nothing more than an SDE update. An item that refines into nothing is priced as itself, and an exclusion never refines.</p>
                    <p>The yield comes from one of two places:</p>
                    <table class="plugin-info-table">
                        <tr><td><strong>The programme's refining yield</strong></td><td>Set next to the default rate on the Pricing Rules page, 90% until you change it. Every refining rule without a yield of its own uses it, so moving to a better refinery means changing one number. It reaches only ore, ice and moon ore, compressed or not (EVE's Asteroid category).</td></tr>
                        <tr><td><strong>A yield on the rule</strong></td><td>Overrides the programme's for that rule. Anything outside the Asteroid category needs one: modules and ships reprocess for far less than ore, so the programme's yield would overpay for them, and a refining rule on them without a yield is refused.</td></tr>
                    </table>
                    <div class="info-box">
                        <i class="fas fa-gem"></i>
                        <strong>Set the yield you actually refine at.</strong> It depends on your structure, rigs, skills and implants. Set it too high and you pay for minerals you never get. The quote marks each refined line with its yield.
                    </div>

                    <h4>Names sellers see</h4>
                    <p>EVE's names are not always what miners call things. Give a rule a <strong>Name shown to sellers</strong> and they read it instead of EVE's name: a group rule on <em>Rare Moon Asteroids</em> can read <em>Moon Goo</em> on the public page's rates and most wanted list, and on each line of a quote it prices. The Pricing Rules page shows your name with EVE's under it.</p>

                    <div class="purple-box">
                        <i class="fas fa-sort-amount-down"></i>
                        <strong>Precedence: item, then exclusions, then group &gt; category &gt; base.</strong> A rule for that exact item always decides. Otherwise an exclusion on the item's group or category wins over any rate, and then the most specific rate applies.
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
                        <strong>Faction and Tech II variants often sit in their own group.</strong> EVE frequently splits advanced ammunition into a separate group from the Tech I version, so a rule on the basic group will not catch the faction or Tech II equivalents. Always confirm the group an item actually belongs to before relying on a group rule: a plausible sounding group name is not proof it contains the items you mean. <strong>Find an item, group or category</strong>, at the top of the Add a rule form, shows it: type a name or an ID and every match comes with its group and category, ready to fill in the rule.
                    </div>

                    <div class="info-box">
                        <i class="fas fa-search"></i>
                        <strong>How to tell a rule did not match:</strong> run an appraisal and look at the percentage on the line. If an item comes back at your <em>base</em> percentage, no rule matched it. If you expected it to be excluded and it is priced instead, your rule is pointing at the wrong group or category.
                    </div>

                    <div class="success-box">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Practical approach:</strong> use <strong>category</strong> rules for broad strokes (ore at 90%, all ships excluded), <strong>group</strong> rules to pay a different rate or leave out part of a category, and <strong>item</strong> rules for the exceptions to all of that. Only an item rule brings something back out of an exclusion, so exclude broadly and name the few items you still want.
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
                        <li><strong>Create the EVE contract.</strong> The seller makes an item exchange contract to the named target, sets the price to the quoted value (less the haul fee, where one comes off at the location they contract from), and pastes the key into the contract's Description.</li>
                        <li><strong>Detection.</strong> Buyback Manager syncs contracts every 15 minutes (or immediately via the Diagnostic page's Sync Now button), reads the key from the Description, and resolves it to the appraisal.</li>
                        <li><strong>Check and notify.</strong> The contract is compared against the appraisal. A clean match is announced as normal; anything off is flagged for a director to review before paying.</li>
                    </ol>

                    <h4>What a seller can paste</h4>
                    <table class="plugin-info-table">
                        <tr><td>From the game</td><td>An inventory, assets or contract window copied with Ctrl+C.</td></tr>
                        <tr><td>One item per line</td><td><code>1,000 x Tritanium</code>, <code>1000 Tritanium</code>, <code>Tritanium 1000</code> or <code>Tritanium x1000</code>.</td></tr>
                        <tr><td>An EFT fit</td><td>The ship, its modules, drones and cargo. Charges loaded in a module are not counted.</td></tr>
                    </table>
                    <p>The same item on several lines is added up. Lines that name no item are listed back to the seller rather than guessed at, and a paste is capped at 1,000 item types or 5,000 lines.</p>

                    <h4>What gets checked</h4>
                    <p>The quote is a <strong>reference, not a guarantee</strong>. Nothing locks a price, so a discrepancy raises a review flag rather than binding you to an old number:</p>
                    <table class="plugin-info-table">
                        <tr><td>Asked price</td><td>The ISK on the contract differs from the quote, less the haul fee where it was made, by more than your tolerance (default 1%). The direction is reported, so asking <em>more</em> than quoted stands out from asking less.</td></tr>
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
                        <strong>What gets recorded:</strong> every contract to your buyback target that quotes an appraisal key appears in the <a href="{{ route('buyback-manager.contracts.index') }}">Contracts</a> list. One whose key matches no appraisal of your corporation is listed too, with no quote, under "Key matched nothing", so it can be reviewed. It never counts toward the statistics.
                    </div>

                    <div class="info-box">
                        <i class="fas fa-exchange-alt"></i>
                        <strong>Changing the target:</strong> only new contracts follow it. A contract already open keeps the target it was sent to, in its Discord posts and in what HR Manager credits, and Buyback Manager keeps following it until it ends.
                    </div>
                </div>
            </div>

            {{-- NOTIFICATIONS --}}
            <div id="notifications" class="help-section">
                <div class="help-card">
                    <h3><i class="fab fa-discord"></i> Discord notifications</h3>
                    <p>Buyback Manager owns its own Discord delivery. You add webhooks on the Settings page (Discord Webhooks tab), choose which categories each one announces, and optionally mention a role. A webhook can be scoped to one corporation or made global.</p>

                    <h4>The eight notification categories</h4>
                    <table class="plugin-info-table">
                        <tr><td>Contract matched</td><td>A contract matched its appraisal key cleanly, with nothing to review.</td></tr>
                        <tr><td>Needs review</td><td>A contract matched but needs review: price drift, stale quote, item mismatch, reused key, or wrong location.</td></tr>
                        <tr><td>Key did not resolve</td><td>A contract quoted an appraisal key that does not exist, has expired, or belongs to another corporation.</td></tr>
                        <tr><td>Buyback completed</td><td>The contract was accepted in game and the buyback is done.</td></tr>
                        <tr><td>Cancelled or expired</td><td>The contract was cancelled, rejected or deleted, or passed its expiry date without being accepted.</td></tr>
                        <tr><td>Waiting too long</td><td>A matched contract has sat unaccepted past the corp's auto-nudge window.</td></tr>
                        <tr><td>Pricing problems</td><td>The price provider stopped answering, and again when it answers. Quotes meanwhile use the fallback provider or the last cached prices.</td></tr>
                        <tr><td>Contract feed</td><td>SeAT can no longer read the contracts made to your target (no token with the contracts scope, or the sync keeps failing), and again when it can. Until then new contracts go unseen.</td></tr>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-at"></i>
                        <strong>Role mentions and the Routing Map:</strong> the role picker lists Discord roles detected from your SeAT Discord integration. The Routing Map tab on the Settings page shows which webhook announces which category, so you can confirm coverage and spot gaps.
                    </div>

                    <div class="success-box">
                        <i class="fas fa-shield-alt"></i>
                        <strong>No double pings, no spam:</strong> each event sends at most once per webhook (duplicates are de duplicated), and each webhook is rate limited. A contract's first sighting fires exactly one of matched, needs review or key did not resolve, never two. A post Discord refuses, or one held back by the rate limit, is sent again on the next contract sync, for up to a day and three tries.
                    </div>
                </div>
            </div>

            {{-- INTEGRATION --}}
            <div id="integration" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-plug"></i> Manager Core integration</h3>
                    <p>Manager Core is an optional companion plugin. When it is installed, Buyback Manager detects it automatically and offers it as a price provider. When it is absent, everything still works on Fuzzwork or Janice. Buyback Manager needs Manager Core 1.2.0 or later: an older one is left alone, as if it were not installed.</p>

                    @if($mcAvailable)
                        <div class="success-box">
                            <i class="fas fa-check-circle"></i>
                            <strong>Manager Core is installed.</strong> It can be picked as the price provider or as the fallback provider, and buyback events are published to its event feed.
                        </div>
                    @elseif($mcNote)
                        <div class="warning-box">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Manager Core needs updating.</strong> {{ $mcNote }} Update it and it shows up as a price provider, with nothing else to change.
                        </div>
                    @else
                        <div class="info-box">
                            <i class="fas fa-cube"></i>
                            <strong>Manager Core is not installed.</strong> You are running in standalone mode. Install it later and it shows up as a price provider, with nothing else to change.
                        </div>
                    @endif

                    <h4>Pricing through Manager Core</h4>
                    <ul>
                        <li><strong>Any market added in Manager Core</strong>, citadels included. Manager Core keeps its own prices, so the cache TTL on the setting does not apply to it. When the installed Manager Core reports that its provider for a market prices it somewhere else (Fuzzwork or Janice price a citadel at Jita 4-4), the Settings form says so under the market.</li>
                        <li><strong>A provider of the corporation's own at that market.</strong> <em>Provider at that market</em> picks what Manager Core prices the corporation's market through: the market's own provider, or MCPraisal, Fuzzwork, Janice or Goonpraisal where Manager Core can price that market with it. Manager Core keeps those prices for Buyback Manager apart from the market's own, so two corporations on the same market can price through different providers. The list says why a provider cannot be used there (Janice has no key in Manager Core, or cannot price a citadel).</li>
                        <li><strong>The items your rules name are priced from the start.</strong> Saving a rule for an item or a group asks Manager Core to keep those items priced, so their first quote is its price. A category rule is left to the quotes, since one can hold thousands of items.</li>
                        <li><strong>A quote waits for items it has not priced yet.</strong> Before the quote, Buyback Manager asks Manager Core to fetch them at once and the page waits for up to 20 seconds while it does, so the corporation's own provider prices them. Whatever has not arrived by then is priced by the fallback provider for that quote, the quote says Manager Core is fetching it, and the next quote has its price. An item that never gets a price there has no orders at the market, and later quotes do not wait for it.</li>
                        <li><strong>Old prices go to the fallback.</strong> A Manager Core price over 8 hours old means its scheduled refresh has stopped, so the fallback prices that item and the quote says why. When the fallback has no price either, the old one is kept. Manager Core fetches at once only what it has no price for at all, so the quote does not wait for these.</li>
                        <li><strong>Subscriptions look after themselves.</strong> Every item Buyback Manager has asked for is sent to Manager Core again once a day, so one it lost comes back on its own. When no corporation prices at a market, or through a provider there, any more, those items are dropped, and what another corporation still uses keeps them.</li>
                        <li><strong>A central override.</strong> Once a corporation prices through Manager Core, Buyback Manager registers in its Pricing Preferences. A market an admin sets there for Buyback Manager, with its provider override if it has one, wins over every corporation's own market and provider, and the Settings page says so.</li>
                    </ul>

                    <h4>What Buyback Manager publishes to Manager Core</h4>
                    <p>Every contract lifecycle transition is published to Manager Core's event feed, an integration surface other plugins can subscribe to, separate from and in addition to the Discord categories above. Standalone installs simply get the Discord notifications.</p>
                    <div class="purple-box">
                        <i class="fas fa-broadcast-tower"></i>
                        <div><strong>Events published:</strong> <code>buyback.contract.matched</code>, <code>.flagged</code>, <code>.unmatched</code>, <code>.completed</code>, <code>.cancelled</code>, <code>.expired</code> and <code>.nudge</code>. HR Manager listens for <code>.completed</code> to credit the seller. Appraisals are not published: they are generated far too often (every public estimate) to be useful bus traffic. Pricing problems go to Discord only.</div>
                    </div>
                </div>
            </div>

            {{-- PERMISSIONS --}}
            <div id="permissions" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-user-shield"></i> Permissions</h3>
                    <p>Buyback Manager uses three permissions, assigned through SeAT's role system, and a list on each programme saying who it is for. The permission says what someone may do; the list says on which programme. Having a character in a corporation grants nothing, so a member of your corporation can run a buyback under their alt corporation and never reach yours.</p>

                    <table class="plugin-info-table">
                        <tr><td><code>view</code></td><td>Lets someone named a <strong>viewer</strong> of a programme see its contracts, Statistics and Analytics, and read this Help page.</td></tr>
                        <tr><td><code>settings</code></td><td>Lets someone named a <strong>manager</strong> of a programme change its settings, pricing rules, locations, public page and Discord webhooks, and see its contracts like a viewer. A global webhook shows in the list, since it posts for that programme too, but only an admin can change it.</td></tr>
                        <tr><td><code>admin</code></td><td>Everything above for every programme, with or without being named, plus adding and deleting programmes, naming who manages and views each one, global webhooks and the Diagnostic page. SeAT's own superusers have it already.</td></tr>
                    </table>

                    <h4>Naming who runs a programme</h4>
                    <p>In <strong>Settings &gt; Programme Access</strong>, an admin names each programme's managers and viewers. A name is a SeAT account, found by any of its characters, or a whole SeAT role, so everyone holding the role counts. A few examples:</p>
                    <ul>
                        <li><strong>A corporation buyback run by a team:</strong> name a "Buyback team" role as managers and your directors' role as viewers.</li>
                        <li><strong>A member's own buyback under an alt corporation:</strong> add that corporation's programme and name the member's account as its manager. They reach nothing else.</li>
                        <li><strong>A small buyback one person runs:</strong> name that one account.</li>
                    </ul>
                    <p>A name only works with the permission behind it: a manager needs <code>settings</code>, a viewer <code>view</code>. Programme Access marks a name that lacks it or has left SeAT, and so does Diagnostic &gt; Settings Health. A programme with nobody named is run and seen by admins alone.</p>

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
                    <p>In <strong>Settings</strong>, click the <i class="fas fa-map-marker-alt"></i> location button on a corporation, then add one or more allowed locations. Search by name and pick a <strong>region, constellation, system, station, or citadel</strong>. Mix granularities freely: a region entry covers every station and structure inside it, so "The Forge" accepts all of it while "Jita IV-4" accepts only that station.</p>

                    <div class="info-box">
                        <i class="fas fa-ban"></i>
                        With one or more locations set, a contract created anywhere else is still tracked but <strong>flagged</strong> with "Created outside the accepted buyback locations", announced through the Needs review category so a director can review and decline it in game. An empty list means no restriction.
                    </div>

                    <p>The accepted locations are listed on the public page and on every appraisal page, so a seller knows where to contract before hauling anywhere.</p>

                    <h4>Haul fees</h4>
                    <p>A location can take a <strong>haul fee</strong> off the quote, in ISK per m3 of what is sold, for the cost of moving it to market. Set it when you add the location, or change it on its row. Every quote shows how many m3 it takes up and, under it, the contract price at each location with a fee. The seller contracts for the price where they are, and the contract is checked against that price. A location inside several entries pays the fee of the narrowest one, so a station's own fee beats its system's and its region's, and a station with no fee of its own inside a region with one pays nothing. The fee never comes to more than the quote.</p>
                    <div class="info-box">
                        <i class="fas fa-box"></i>
                        <strong>Ships, capital modules and containers count packaged.</strong> SeAT's SDE tables have only the assembled volume, and a ship is ten or more times its packaged size, a capital module four times and a cargo container about ten. When SeAT updates its SDE it keeps CCP's full static data on disk, packaged volumes included, and Buyback Manager reads them from there, once for each build SeAT downloads. Everything else hauls at the volume SeAT already has. ESI is asked only for an item newer than SeAT's copy, once per item type in the background and never while a quote waits. Until then that item's quote counts it assembled, says the fees are too high for it, and asks the seller to appraise again in a few minutes. Without SeAT's copy, only ships are looked up this way, through what Janice sends with its prices or ESI.
                    </div>
                    <p>With a haul fee set, Discord's posts for a contract show the fee taken off, and so does the contract's own page.</p>

                    <h4>Volumes for whoever hauls</h4>
                    <p>Every quote shows each line's m3 and the total, on the plugin's appraisal page and the public one. Each contract keeps the m3 it carries, measured from its own items, or from the quote while SeAT has not read them yet. The Contracts list, the contract's page and the CSV export show it, and Statistics and Analytics add up the m3 bought in the period.</p>
                </div>
            </div>

            {{-- ANALYTICS --}}
            <div id="analytics" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-chart-pie"></i> Analytics</h3>
                    <p>The <strong>Analytics</strong> page answers two questions the contract list cannot: what the programme actually buys, and how much of what it quotes turns into a sale. Filter by corporation and period; every figure respects the same corporation visibility as the Contracts list.</p>

                    <h4>What each panel tells you</h4>
                    <table class="plugin-info-table">
                        <tr><td><strong>Headline</strong></td><td>ISK paid, average buyback, distinct sellers, and items and m3 bought for the period.</td></tr>
                        <tr><td><strong>Quote to payout</strong></td><td>Appraisals issued, keys actually used in a contract, and buybacks completed. The gap between the first two is the share of people who were quoted and walked away.</td></tr>
                        <tr><td><strong>Most bought</strong></td><td>Items, groups and categories ranked by the ISK you paid for them, the practical guide to which pricing rules matter.</td></tr>
                        <tr><td><strong>Top sellers</strong></td><td>Who supplies the programme, by ISK paid.</td></tr>
                        <tr><td><strong>Why contracts were flagged</strong></td><td>Which review reason fires most, so you fix the cause rather than the symptom.</td></tr>
                        <tr><td><strong>Quoted but never sold</strong></td><td>Items people were priced for and did not contract, ranked by the ISK that walked away.</td></tr>
                    </table>

                    <div class="info-box">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Reading the conversion rate.</strong> A falling rate usually means your rates have drifted behind the market: people still ask for a price, but fewer of them accept it. Cross-check the items at the top of <em>Quoted but never sold</em>: those are where you are losing the sale.
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
                    <p>Each corporation can publish an optional public, no-login landing page that advertises the buyback programme and funnels sellers in. It is safe to share outside SeAT, and its link is named after whoever the contracts go to, since that is who sellers deal with:</p>
                    <ul>
                        <li><strong>Your corporation:</strong> its ticker, for example <code>/buyback/MINC</code>.</li>
                        <li><strong>Another corporation:</strong> that corporation's ticker, or the name you typed for it, for example <code>/buyback/hauling-co</code>.</li>
                        <li><strong>A player:</strong> the character's name, for example <code>/buyback/kira-vex</code>.</li>
                    </ul>
                    <p>Change where contracts go and the link follows. A link with your own ticker still leads to the page, so links sellers saved before keep working.</p>

                    <h4>Turning it on</h4>
                    <p>Open <strong>Settings</strong>, find the corporation in the Configured Corps table, and click the <i class="fas fa-globe"></i> globe button in its Actions column. Tick <strong>Enable the public page</strong> and save. The editor shows the exact public URL. If a closed corporation still holds your ticker, the URL finds yours, since only corporations with a programme are looked at. If another programme's link has the same name, for example two corporations sending contracts to the same player, the editor says so: the link opens a published page first, then the corporation SeAT refreshed last.</p>

                    <h4>While the page is off</h4>
                    <p>With the page switched off, or the programme itself, the URL shows a closed notice instead of your rates and the calculator, so a seller following an old link knows it is not broken. Every appraisal link shows the same notice, and no quote can be made from the page. Under <strong>While the page is off, visitors see</strong>, pick one of:</p>
                    <ul>
                        <li><strong>Temporarily closed</strong> (the default): the shutter is down, the BACK SOON card swings and a hamster naps on the loot crate.</li>
                        <li><strong>Permanently closed</strong>: the stall is boarded up and the hamster walks off with its bindle.</li>
                        <li><strong>Nothing</strong>: the URL returns 404, so nobody can tell the corporation has a programme.</li>
                    </ul>
                    <p>Each closed notice carries your logo, headline and footer, and a message under the hamsters. Leave the message blank for the default ("We aren't buying right now. Check back soon." or a thank-you to everyone who sold to you), or write your own, up to 1,000 characters with line breaks kept. You can set it up while the page is still on.</p>
                    <div class="info-box">
                        <i class="fas fa-sign-in-alt"></i>
                        The whole selling flow works with no login, and the page never asks for one: a visitor appraises on the public page, gets a key and a shareable appraisal URL, and contracts in game. Membership is <strong>detected, not declared</strong>: when the contract is picked up, its issuing character is resolved back to a SeAT account, so a member who appraised without signing in still gets the appraisal filed under My Appraisals. Someone with no SeAT account simply shows as a guest.
                    </div>

                    <div class="success-box">
                        <i class="fas fa-user-shield"></i>
                        <strong>No account and no ESI scopes required.</strong> Buyback Manager never starts its own EVE SSO request and never asks for scopes, so it cannot change or reduce what anyone has already granted SeAT. Appraising touches no character data at all, and contract detection reads the corporation's (or the designated operator's) own contract feed, never the seller's. Signing in is optional and only adds appraisal history.
                    </div>

                    <h4>Branding &amp; layout</h4>
                    <ul>
                        <li><strong>Headline, blurb and footer</strong>: your own copy for the hero and a small footer line.</li>
                        <li><strong>Accent colour</strong>: recolours the buttons and highlights.</li>
                        <li><strong>Background image</strong>: uploaded and shown behind the hero, with a <strong>dim overlay</strong> slider so the text stays readable over any image.</li>
                        <li><strong>Logo</strong>: defaults to the EVE corporation logo; upload your own. Choose its background: a dark box (default), no box (logo straight on the image, best for a transparent logo), or a white square.</li>
                        <li><strong>Page layout</strong>: stacked (rates above instructions) or side by side (rates left, instructions right); it stacks on mobile.</li>
                    </ul>

                    <h4>Rates &amp; estimate</h4>
                    <ul>
                        <li><strong>Show the rates section</strong>: the base rate and price-lock cards.</li>
                        <li><strong>Most wanted</strong>: flag any pricing rule as featured on the Pricing Rules page to spotlight it with a star.</li>
                        <li><strong>List all pricing rules</strong>: show every rule, not just the featured ones (excluded items are never shown).</li>
                        <li><strong>Provider, market and price side</strong>: name the provider and market your prices come from, on the rates section, the estimate and every appraisal page, plus each rule's side (buy / sell / split). Off, none of them is named anywhere public.</li>
                        <li><strong>No-login appraisal</strong>: the calculator that lets anyone paste items, see what you'd pay, and get a contract key. This is the primary seller flow, so it is on by default; turn it off for an internal-only programme. Rate limited, and it does expose your rates to anyone with the link.</li>
                    </ul>

                    <h4>The appraisal page</h4>
                    <p>Every quote gets its own page at <code>/buyback/{name}/a/{key}</code>, which the seller keeps open while making the contract. The key finds the quote, so an older link with another name in front still opens it:</p>
                    <ul>
                        <li><strong>Copy buttons</strong> for the key, the contract price in whole ISK, and who to contract to.</li>
                        <li><strong>When the quote holds.</strong> It shows when the quote was made and until when it holds, from your stale-quote window. After that it warns the seller that a contract made with it is flagged for you to check against today's prices. Nothing is refused.</li>
                        <li><strong>What became of the contract.</strong> Once a contract uses the key, the page says so: received and waiting for you, paid, expired, cancelled, or declined, with the date.</li>
                    </ul>

                    <div class="purple-box">
                        <i class="fas fa-shield-alt"></i>
                        <strong>What is never shown.</strong> The landing page only displays your rates and the copy you write. An appraisal page shows its own quote and the state of the one contract made with its key, never who issued or accepted it. Member names, traded volumes, and contract history are never exposed.
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
                    <p>Every Buyback Manager page is wrapped in a stable set of CSS hook classes, so you can restyle any part of the plugin from SeAT's own custom stylesheet or your theme, without editing the plugin's files, which are overwritten on every update.</p>

                    <h4>The global hook</h4>
                    <p>Every plugin page (Appraisal, My Appraisals, Contracts, Statistics, Settings, Help) renders inside a single wrapper:</p>
                    <ul>
                        <li><code>.buyback-manager-wrapper</code>: present on every Buyback Manager page. Style it to affect the whole plugin at once, or use it as a prefix to scope an override so it only applies inside Buyback Manager.</li>
                    </ul>

                    <h4>Component classes</h4>
                    <p>Most of the plugin's chrome is built from a few shared classes. Target these to restyle a widget everywhere it appears:</p>
                    <ul>
                        <li><code>.card-dark</code>: the dark card chrome (header + body) used on every page.</li>
                        <li><code>.card-title</code> / <code>.card-tools</code>: the heading and the button/filter cluster in a card header.</li>
                        <li><code>.btn-bb-primary</code> / <code>.btn-bb-secondary</code>: the primary and secondary buttons.</li>
                        <li><code>.table-bb-styled</code>: the data tables (Contracts, Statistics, and so on).</li>
                        <li><code>.contract-status</code>: the status pills on the Contracts list.</li>
                        <li><code>.pricing-rule</code>: a rule row on the Pricing Rules page.</li>
                        <li><code>.info-box</code> / <code>.success-box</code> / <code>.warning-box</code> / <code>.purple-box</code>: the coloured callouts (as seen on this page).</li>
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
                        <strong>Where to add your CSS:</strong> SeAT auto-loads a stylesheet named <code>custom-layout.css</code> when it exists. On a bare-metal install, drop it into SeAT's <code>public/</code> directory (for example <code>/var/www/seat/public/custom-layout.css</code>). On SeAT Docker, place it in your mounted custom directory and map it to <code>/var/www/seat/public/</code>, then bring the stack back up. It is detected automatically; there is no setting to toggle. See the <a href="https://eveseat.github.io/docs/styling/" target="_blank" rel="noopener">SeAT styling docs</a>.
                    </div>

                    <div class="purple-box">
                        <i class="fas fa-globe"></i>
                        <strong>The public landing page is separate.</strong> It renders outside the SeAT layout, so it does not use these classes and SeAT's <code>custom-layout.css</code> does not reach it. Theme it instead from its own built-in controls (accent colour, background image, logo and dim overlay) under <strong>Settings &rarr; Public Page</strong>.
                    </div>

                    <div class="info-box">
                        <i class="fas fa-lightbulb"></i>
                        Keep your tweaks in SeAT's custom stylesheet, never in the plugin's own files: the plugin's CSS is replaced on every update, but your <code>custom-layout.css</code> survives.
                    </div>
                </div>
            </div>

            {{-- COMMANDS & CONFIG --}}
            <div id="commands" class="help-section">
                <div class="help-card">
                    <h3><i class="fas fa-terminal"></i> Commands &amp; configuration</h3>

                    <h4>Scheduled commands</h4>
                    <p>Buyback Manager schedules one Artisan command automatically, and you normally never run it by hand. The Diagnostic page's Health Checks tab confirms it is present. If you change its cron in SeAT's schedule settings, updates keep your change, and the check shows it as information.</p>
                    <table class="plugin-info-table">
                        <tr><td><code>buyback-manager:sync-contracts</code></td><td>Every 15 minutes. Reads SeAT's synced contracts, resolves each appraisal key, compares the contract against its quote and raises review flags, fires the lifecycle events and Discord notifications, sends idle-contract nudges, prunes old appraisals and notification logs, and reads packaged volumes from SeAT's static data whenever SeAT has a new build.</td></tr>
                    </table>
                    <div class="info-box">
                        <i class="fas fa-play"></i>
                        To force a detection pass on demand without waiting for the schedule, use the <strong>Sync Now</strong> button on the Diagnostic page. From the command line, <code>docker exec -it seat-docker-front-1 php artisan buyback-manager:sync-contracts --sync</code> runs the pass straight away instead of queueing it.
                    </div>

                    <h4>Moving off a development build</h4>
                    <p>Builds before 1.0.0 kept their data in tables named <code>buyback_*</code>, which stay next to 1.0.0's until you clear them. Diagnostics warns while they are there. Point <code>SEAT_PLUGINS</code> at <code>mattfalahe/buyback-manager</code>, with no branch after it, restart SeAT, then run this once. It asks whether to carry your programme over or start fresh, then removes what the old build left. It refuses to run while SeAT is set to install any other branch. The changelog has the full steps.</p>
                    <table class="plugin-info-table">
                        <tr><td><code>docker exec -it seat-docker-front-1 php artisan buyback-manager:upgrade-dev-build</code></td><td>Run by hand, once. <code>--carry-over</code> or <code>--fresh</code> picks without asking, <code>--force</code> skips the confirmation.</td></tr>
                    </table>

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
                    <p>Appraisals are generated freely and with no login, so they are pruned automatically inside the sync cycle, so there is no extra cron to add. Retention is two tier and set per corporation on the Settings page:</p>
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
                    <p>The admin only <strong>Diagnostic</strong> page is the first stop for anything unexpected. It is not in the sidebar; reach it at <code>/buyback-manager/diagnostic</code> (requires the admin permission). Each tab opens with a short explainer.</p>

                    <table class="plugin-info-table">
                        <tr><td>Prices missing, or items not accepted</td><td>The appraisal's note says where its prices came from. <em>Test Connection</em> on the Settings form asks the provider directly, Diagnostic &gt; Health Checks shows how each corporation's provider last answered, and Settings Health lists any provider or fallback that cannot run.</td></tr>
                        <tr><td>Hamsters out of coffee</td><td>No provider answered, so nothing on the list could be priced. Diagnostic &gt; Health Checks shows how the provider and its fallback last answered. Setting a fallback provider, or one that can run, covers the provider's next bad day.</td></tr>
                        <tr><td>Contract not picked up</td><td>Confirm the appraisal key is in the Description, then Diagnostic &gt; Contract Trace walks the pipeline.</td></tr>
                        <tr><td>Contract list stale</td><td>Diagnostic &gt; Health Checks shows the last sync time, the last error for each programme, and has a Sync Now button. A webhook on the Contract feed category hears when SeAT stops reading the contracts.</td></tr>
                        <tr><td>Haul fee too high on a ship or module</td><td>It was counted assembled because its packaged volume was not known yet, and the quote says so. Appraise again a few minutes later. Diagnostic &gt; Health Checks (Item Volumes) shows which build of SeAT's static data Buyback Manager has read. If SeAT has none, capital modules and containers count assembled.</td></tr>
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
                        <div class="faq-answer">No. Buyback Manager works standalone on Fuzzwork (free) or Janice. Manager Core only adds itself as a price provider, with its markets and citadels, and a cross plugin event feed. It has to be 1.2.0 or later, and you can install it later with no other changes.</div>
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
                        <div class="faq-answer">A Fuzzwork or Janice answer is reused for the cache TTL set on each corporation setting (in minutes, and 0 asks every time). Lower it for fresher prices, raise it to call the provider less. Manager Core keeps its own prices and is read on every quote. While a provider is not answering, a quote can fall back on the last cached price however old it is, and its note says so.</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question"><span>Why is an item not accepted with "no market price"?</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer">Nothing had a price for it on the side its rule pays on: not the provider, not the fallback provider, and not the cache. Usually nobody trades it at that market. When you price at a hub other than Jita, ticking <em>Use Jita's price for a side with no orders at the market</em> covers most of these. Otherwise exclude the item, or quote it by hand.</div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question"><span>What happens if someone contracts at the wrong price?</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer">The contract is still tracked, but flagged with the asked price, the quoted value and the percentage difference, and announced through the Needs review category. Nothing is paid automatically, so a director reviews it and either honours it or declines the contract in game. Set the tolerance per corporation on the Settings page.</div>
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

            // Picking a section ends a search.
            clearTimeout(searchTimeout);
            $('#helpSearch').val('');
            $('.help-content').removeClass('help-searching');
            $('.help-card, .whats-new-box').show();
        });

        // A link inside a section that opens another one.
        $('.help-content').on('click', 'a[data-goto]', function (e) {
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

        // A missing plugin pulses a few times as it comes into view, then
        // stays marked. A section is hidden until it is picked, so each box
        // waits until it can be seen.
        if ('IntersectionObserver' in window) {
            var pulse = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('eco-pulsing');
                        pulse.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.5 });
            document.querySelectorAll('.eco-pulse').forEach(function (el) {
                pulse.observe(el);
            });
        }

        // Every section is searched, not only the open one.
        var searchTimeout;
        $('#helpSearch').on('input', function () {
            clearTimeout(searchTimeout);
            var query = $(this).val().toLowerCase();

            searchTimeout = setTimeout(function () {
                if (query.length < 2) {
                    $('.help-content').removeClass('help-searching');
                    $('.help-card, .whats-new-box').show();
                    return;
                }

                $('.help-content').addClass('help-searching');
                $('.help-card, .whats-new-box').each(function () {
                    $(this).toggle($(this).text().toLowerCase().indexOf(query) !== -1);
                });
            }, 250);
        });
    });
</script>
@endpush
