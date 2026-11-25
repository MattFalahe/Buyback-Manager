@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Public Page')
@section('page_header', 'Buyback Manager - Public Page')

@push('head')
    @include("buyback-manager::settings._settings_styles")
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=6">
@endpush

@section('content')
<div class="buyback-manager-wrapper">


    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                Public page: {{ optional($setting->corporation)->name ?? ('Corporation #' . $setting->corporation_id) }}
                <span class="text-muted">[{{ $setting->corp_ticker }}]</span>
            </h3>
            <div class="card-tools">
                <a href="{{ route('buyback-manager.settings.index') }}" class="btn btn-sm btn-bb-secondary">
                    <i class="fa fa-arrow-left"></i> Back to settings
                </a>
            </div>
        </div>
        <div class="card-body">

            <div class="alert alert-info">
                <strong>Public URL:</strong>
                <a href="{{ $setting->public_url }}" target="_blank" rel="noopener">
                    {{ $setting->public_url }}
                </a>
                @unless($setting->publicPageOpen())
                    <span class="text-muted">({{ $setting->publicClosedState() === \BuybackManager\Models\BuybackSetting::PUBLIC_CLOSED_HIDDEN ? 'off, so it returns 404' : 'off, so it shows the closed notice' }})</span>
                @endunless
                <br><small>The link is named after whoever the contract goes to: this corporation's ticker, the receiving corporation's ticker or name, or the receiving player's name. It changes when you change where contracts go, and links sellers saved with this corporation's ticker still lead here.</small>
            </div>

            @if($sharingLink->isNotEmpty())
                <div class="alert alert-warning">
                    <strong>Another programme's link has the same name.</strong>
                    The programme of {{ $sharingLink->map(fn ($other) => optional($other->corporation)->name ?? ('Corporation #' . $other->corporation_id))->implode(', ') }}
                    is also at {{ $setting->public_url }}, so the link leads to only one of these pages: a published one first, then the corporation SeAT refreshed last.
                </div>
            @endif

            <form method="POST" action="{{ route('buyback-manager.settings.public.update', $setting->id) }}" enctype="multipart/form-data">
                @csrf

                <div class="form-group">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="public_page_enabled" name="public_page_enabled" value="1" {{ old('public_page_enabled', $setting->public_page_enabled) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="public_page_enabled">Enable the public page</label>
                    </div>
                    <small class="form-text text-muted">When off, the public URL and every appraisal link show the closed notice below instead. The page is off too while the programme itself is switched off in Settings.</small>
                </div>

                @php
                    $bbClosedState = old('public_closed_state', $setting->publicClosedState());
                    $bbClosedDefaults = [
                        'temporary' => \BuybackManager\Models\BuybackSetting::defaultClosedMessage('temporary'),
                        'permanent' => \BuybackManager\Models\BuybackSetting::defaultClosedMessage('permanent'),
                    ];
                @endphp
                <div class="form-group">
                    <label for="public_closed_state">While the page is off, visitors see</label>
                    <div class="bb-closed-body" id="bb-closed-body">
                        <select id="public_closed_state" name="public_closed_state" class="form-control" style="max-width:340px;">
                            <option value="temporary" {{ $bbClosedState === 'temporary' ? 'selected' : '' }}>Temporarily closed (default)</option>
                            <option value="permanent" {{ $bbClosedState === 'permanent' ? 'selected' : '' }}>Permanently closed</option>
                            <option value="hidden" {{ $bbClosedState === 'hidden' ? 'selected' : '' }}>Nothing: the URL returns 404</option>
                        </select>
                        <div class="bb-closed-message" id="bb-closed-message" style="margin-top:10px;">
                            <label for="public_closed_message">Closed message (optional)</label>
                            <textarea class="form-control" id="public_closed_message" name="public_closed_message" rows="2" maxlength="1000"
                                      data-default-temporary="{{ $bbClosedDefaults['temporary'] }}"
                                      data-default-permanent="{{ $bbClosedDefaults['permanent'] }}"
                                      placeholder="{{ $bbClosedDefaults[$bbClosedState] ?? $bbClosedDefaults['temporary'] }}">{{ old('public_closed_message', $setting->public_closed_message) }}</textarea>
                            <small class="form-text text-muted">Shown under the hamsters on the closed page, with your logo and headline above. Leave it blank for the default shown here.</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="public_show_rates" name="public_show_rates" value="1" {{ old('public_show_rates', $setting->public_show_rates) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="public_show_rates">Show the rates section (base rate and price lock)</label>
                    </div>
                    <div class="custom-control custom-checkbox" style="margin-top:8px;">
                        <input type="checkbox" class="custom-control-input" id="public_show_all_rules" name="public_show_all_rules" value="1" {{ old('public_show_all_rules', $setting->public_show_all_rules) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="public_show_all_rules">List all pricing rules, not just "most wanted"</label>
                    </div>
                    <div class="custom-control custom-checkbox" style="margin-top:8px;">
                        <input type="checkbox" class="custom-control-input" id="public_show_pricing_detail" name="public_show_pricing_detail" value="1" {{ old('public_show_pricing_detail', $setting->public_show_pricing_detail) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="public_show_pricing_detail">Show the market and the price side (buy / sell / split)</label>
                    </div>
                    <div class="custom-control custom-checkbox" style="margin-top:8px;">
                        <input type="checkbox" class="custom-control-input" id="public_appraisal_enabled" name="public_appraisal_enabled" value="1" {{ old('public_appraisal_enabled', $setting->public_appraisal_enabled) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="public_appraisal_enabled">Enable the no-login estimate calculator (visitors paste items and see what you'd pay)</label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="public_layout">Page layout</label>
                    <select id="public_layout" name="public_layout" class="form-control" style="max-width:340px;">
                        <option value="stacked" {{ old('public_layout', $setting->public_layout ?: 'stacked') === 'stacked' ? 'selected' : '' }}>Stacked: rates above instructions (default)</option>
                        <option value="split" {{ old('public_layout', $setting->public_layout) === 'split' ? 'selected' : '' }}>Side by side: rates left, instructions right</option>
                    </select>
                    <small class="form-text text-muted">Side by side widens the page into two columns; it stacks automatically on narrow screens.</small>
                </div>

                <hr>

                <div class="form-group">
                    <label for="public_headline">Headline</label>
                    <input type="text" class="form-control" id="public_headline" name="public_headline" maxlength="191"
                           value="{{ old('public_headline', $setting->public_headline) }}"
                           placeholder="{{ (optional($setting->corporation)->name ?? 'Corporation') . ' Buyback' }}">
                    <small class="form-text text-muted">Big title in the hero. Leave blank to use "[Corp] Buyback".</small>
                </div>

                <div class="form-group">
                    <label for="public_blurb">Blurb</label>
                    <div class="bb-format-bar" role="toolbar" aria-label="Blurb formatting" aria-controls="public_blurb">
                        <button type="button" class="btn btn-sm btn-bb-secondary" data-format="bold" title="Bold (Ctrl+B)" aria-label="Bold"><i class="fa fa-bold"></i></button>
                        <button type="button" class="btn btn-sm btn-bb-secondary" data-format="italic" title="Italic (Ctrl+I)" aria-label="Italic"><i class="fa fa-italic"></i></button>
                        <button type="button" class="btn btn-sm btn-bb-secondary" data-format="heading" title="Heading" aria-label="Heading"><i class="fa fa-heading"></i></button>
                        <button type="button" class="btn btn-sm btn-bb-secondary" data-format="bullet" title="Bulleted list" aria-label="Bulleted list"><i class="fa fa-list-ul"></i></button>
                        <button type="button" class="btn btn-sm btn-bb-secondary" data-format="numbered" title="Numbered list" aria-label="Numbered list"><i class="fa fa-list-ol"></i></button>
                        <button type="button" class="btn btn-sm btn-bb-secondary" data-format="link" title="Link" aria-label="Link"><i class="fa fa-link"></i></button>
                    </div>
                    <textarea class="form-control" id="public_blurb" name="public_blurb" rows="10" maxlength="2000"
                              placeholder="A short pitch shown under the headline.">{{ old('public_blurb', $setting->public_blurb) }}</textarea>
                    <small class="form-text text-muted">Line breaks show on the page as you type them, and a blank line starts a new paragraph. Select text and use the buttons, or type the marks yourself: **bold**, *italic*, [text](https://...) for a link, and ## , - or 1. at the start of a line for a heading, a bullet or a numbered item.</small>
                </div>

                <div class="form-group">
                    <label for="public_footer_text">Footer line (optional)</label>
                    <input type="text" class="form-control" id="public_footer_text" name="public_footer_text" maxlength="500"
                           value="{{ old('public_footer_text', $setting->public_footer_text) }}"
                           placeholder="Your own contact line, e.g. 'Questions? Mail our buyback officer.'">
                </div>

                <hr>

                <div class="form-group">
                    <label for="public_accent_color">Accent colour</label>
                    <input type="color" class="form-control" id="public_accent_color" name="public_accent_color"
                           style="max-width:80px; height:38px; padding:3px;"
                           value="{{ old('public_accent_color', $setting->public_accent_color ?: '#1d9e75') }}">
                </div>

                <div class="form-group">
                    <label for="public_overlay_opacity">Background dim ({{ $setting->public_overlay_opacity ?? 55 }}%)</label>
                    <input type="range" class="form-control-range" id="public_overlay_opacity" name="public_overlay_opacity"
                           min="0" max="100" step="5" value="{{ old('public_overlay_opacity', $setting->public_overlay_opacity ?? 55) }}"
                           oninput="document.getElementById('overlayOut').textContent = this.value + '%';">
                    <small class="form-text text-muted">Darkens the background image so hero text stays readable. Current: <span id="overlayOut">{{ $setting->public_overlay_opacity ?? 55 }}%</span></small>
                </div>

                <hr>

                <div class="form-group">
                    <label for="public_background">Background image</label>
                    @if($setting->public_background_path)
                        <div style="margin-bottom:8px;">
                            <img src="{{ route('buyback-manager.public.image', ['ticker' => $setting->public_slug, 'kind' => 'background'], false) }}?v={{ optional($setting->updated_at)->timestamp }}"
                                 alt="Current background" style="max-height:120px; border-radius:6px; border:1px solid #454d55;">
                        </div>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" id="remove_background" name="remove_background" value="1">
                            <label class="custom-control-label" for="remove_background">Remove current background</label>
                        </div>
                    @endif
                    <input type="file" class="form-control-file" id="public_background" name="public_background" accept="image/jpeg,image/png,image/webp">
                    <small class="form-text text-muted">JPEG, PNG or WebP, up to 5 MB. Served from this server (no external host, CSP-safe).</small>
                </div>

                <div class="form-group">
                    <label for="public_logo">Logo (optional)</label>
                    <div style="margin-bottom:8px;">
                        <img src="{{ $setting->public_logo_path ? route('buyback-manager.public.image', ['ticker' => $setting->public_slug, 'kind' => 'logo'], false) . '?v=' . optional($setting->updated_at)->timestamp : ('https://images.evetech.net/corporations/' . $setting->corporation_id . '/logo?size=64') }}"
                             alt="Current logo" style="height:64px; width:64px; border-radius:8px; border:1px solid #454d55;">
                    </div>
                    @if($setting->public_logo_path)
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" id="remove_logo" name="remove_logo" value="1">
                            <label class="custom-control-label" for="remove_logo">Remove custom logo (revert to EVE corp logo)</label>
                        </div>
                    @endif
                    <input type="file" class="form-control-file" id="public_logo" name="public_logo" accept="image/jpeg,image/png,image/webp">
                    <small class="form-text text-muted">Defaults to the EVE corporation logo when none is uploaded.</small>
                    <div style="margin-top:10px;">
                        <label for="public_logo_style">Logo background</label>
                        <select id="public_logo_style" name="public_logo_style" class="form-control" style="max-width:340px;">
                            <option value="dark" {{ old('public_logo_style', $setting->public_logo_style ?: 'dark') === 'dark' ? 'selected' : '' }}>Dark box (default)</option>
                            <option value="none" {{ old('public_logo_style', $setting->public_logo_style) === 'none' ? 'selected' : '' }}>No box: logo directly on the image</option>
                            <option value="light" {{ old('public_logo_style', $setting->public_logo_style) === 'light' ? 'selected' : '' }}>White square</option>
                        </select>
                        <small class="form-text text-muted">"No box" suits a transparent logo; the dark box suits the square EVE corp logo.</small>
                    </div>
                </div>

                <hr>

                <button type="submit" class="btn btn-bb-primary">
                    <i class="fa fa-save"></i> Save public page
                </button>
            </form>

        </div>
    </div>
</div>
@endsection

@push('javascript')
<script>
    (function () {
        var toggle = document.getElementById('public_page_enabled');
        var body = document.getElementById('bb-closed-body');
        var state = document.getElementById('public_closed_state');
        var messageBox = document.getElementById('bb-closed-message');
        var message = document.getElementById('public_closed_message');
        if (!toggle || !body || !state || !messageBox || !message) {
            return;
        }

        function sync() {
            // Still editable while the page is on, so the notice can be
            // ready before the page is switched off.
            body.classList.toggle('bb-dimmed', toggle.checked);
            messageBox.style.display = state.value === 'hidden' ? 'none' : '';
            message.placeholder = state.value === 'permanent'
                ? message.getAttribute('data-default-permanent')
                : message.getAttribute('data-default-temporary');
        }

        toggle.addEventListener('change', sync);
        state.addEventListener('change', sync);
        sync();
    })();

    (function () {
        var area = document.getElementById('public_blurb');
        var bar = document.querySelector('.bb-format-bar[aria-controls="public_blurb"]');
        if (!area || !bar) {
            return;
        }

        var LINE_MARKS = {
            heading: /^\s*#{1,3}\s+/,
            bullet: /^\s*[-*•]\s+/,
            numbered: /^\s*\d{1,3}[.)]\s+/
        };
        var ANY_LINE_MARK = /^\s*(#{1,3}|[-*•]|\d{1,3}[.)])\s+/;

        // Replaces start..end with text and selects text's own from..to.
        function put(start, end, text, from, to) {
            area.focus();
            area.setSelectionRange(start, end);
            // insertText keeps the change in the browser's undo history.
            var done = false;
            try {
                done = document.execCommand('insertText', false, text);
            } catch (e) {
                done = false;
            }
            if (!done) {
                area.setRangeText(text, start, end, 'end');
                area.dispatchEvent(new Event('input', { bubbles: true }));
            }
            area.setSelectionRange(start + from, start + to);
        }

        // The selection without the spaces around it: a double-click takes the
        // space after a word, and a mark against a space does not count.
        function selection() {
            var start = area.selectionStart;
            var end = area.selectionEnd;
            var v = area.value;
            while (start < end && /\s/.test(v.charAt(start))) {
                start++;
            }
            while (end > start && /\s/.test(v.charAt(end - 1))) {
                end--;
            }
            return { start: start, end: end, text: v.slice(start, end) };
        }

        // How many stars run from position at, stepping by step.
        function stars(v, at, step) {
            var n = 0;
            while (at + n * step >= 0 && v.charAt(at + n * step) === '*') {
                n++;
            }
            return n;
        }

        function wrap(mark, placeholder) {
            var s = selection();
            var v = area.value;
            var left = stars(v, s.start - 1, -1);
            var right = stars(v, s.end, 1);
            // Clicked again on text it already wraps: take the marks off. The
            // stars are counted because two of them are bold and three are bold
            // and italic, so a star next to bold text is not an italic mark.
            var wrapped = s.text !== '' && (mark === '**' ? left >= 2 && right >= 2 : left % 2 === 1 && right % 2 === 1);
            if (wrapped) {
                put(s.start - mark.length, s.end + mark.length, s.text, 0, s.text.length);
                return;
            }
            var text = s.text || placeholder;
            put(s.start, s.end, mark + text + mark, mark.length, mark.length + text.length);
        }

        function lines(kind) {
            var v = area.value;
            var start = area.selectionStart;
            var end = area.selectionEnd;
            if (end > start && v.charAt(end - 1) === '\n') {
                end--;
            }
            var from = v.lastIndexOf('\n', start - 1) + 1;
            var to = v.indexOf('\n', end);
            if (to === -1) {
                to = v.length;
            }
            var block = v.slice(from, to).split('\n');
            var filled = block.filter(function (line) { return line.trim() !== ''; });
            // Every line already marked this way: the button takes it off.
            var remove = filled.length > 0 && filled.every(function (line) { return LINE_MARKS[kind].test(line); });
            var n = 0;
            var out = block.map(function (line) {
                if (line.trim() === '' && filled.length > 0) {
                    return line;
                }
                var bare = line.replace(ANY_LINE_MARK, '').replace(/^\s+/, '');
                if (remove) {
                    return bare;
                }
                n++;
                return (kind === 'heading' ? '## ' : kind === 'bullet' ? '- ' : n + '. ') + bare;
            }).join('\n');
            var single = block.length === 1;
            put(from, to, out, single ? out.length : 0, out.length);
        }

        function link() {
            var s = selection();
            var label = s.text || 'link text';
            var text = '[' + label + '](https://)';
            // The address part is selected, so pasting a link replaces it.
            put(s.start, s.end, text, label.length + 3, text.length - 1);
        }

        function apply(format) {
            if (format === 'bold') {
                wrap('**', 'bold text');
            } else if (format === 'italic') {
                wrap('*', 'italic text');
            } else if (format === 'link') {
                link();
            } else if (LINE_MARKS[format]) {
                lines(format);
            }
        }

        // Keeps the text selected while a button is pressed.
        bar.addEventListener('mousedown', function (event) {
            if (event.target.closest('[data-format]')) {
                event.preventDefault();
            }
        });
        bar.addEventListener('click', function (event) {
            var button = event.target.closest('[data-format]');
            if (button) {
                event.preventDefault();
                apply(button.getAttribute('data-format'));
            }
        });
        area.addEventListener('keydown', function (event) {
            if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) {
                return;
            }
            var key = event.key.toLowerCase();
            if (key === 'b' || key === 'i') {
                event.preventDefault();
                apply(key === 'b' ? 'bold' : 'italic');
            }
        });
    })();
</script>
@endpush
