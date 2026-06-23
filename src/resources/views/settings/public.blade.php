@extends('web::layouts.grids.12')

@section('title', 'Buyback Manager - Public Page')
@section('page_header', 'Buyback Manager - Public Page')

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/buyback-manager/css/buyback-manager.css') }}?v=1">
@endpush

@section('content')
<div class="buyback-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul style="margin:0; padding-left:18px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                Public page &mdash; {{ optional($setting->corporation)->name ?? ('Corporation #' . $setting->corporation_id) }}
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
                <a href="{{ route('buyback-manager.public.show', ['ticker' => $setting->corp_ticker]) }}" target="_blank" rel="noopener">
                    {{ route('buyback-manager.public.show', ['ticker' => $setting->corp_ticker]) }}
                </a>
                @unless($setting->public_page_enabled)
                    <span class="text-muted">&mdash; enable below to make it live (it 404s while disabled).</span>
                @endunless
            </div>

            <form method="POST" action="{{ route('buyback-manager.settings.public.update', $setting->id) }}" enctype="multipart/form-data">
                @csrf

                <div class="form-group">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="public_page_enabled" name="public_page_enabled" value="1" {{ old('public_page_enabled', $setting->public_page_enabled) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="public_page_enabled">Enable the public page</label>
                    </div>
                    <small class="form-text text-muted">When off, the public URL returns 404.</small>
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
                </div>

                <div class="form-group">
                    <label for="public_layout">Page layout</label>
                    <select id="public_layout" name="public_layout" class="form-control" style="max-width:340px;">
                        <option value="stacked" {{ old('public_layout', $setting->public_layout ?: 'stacked') === 'stacked' ? 'selected' : '' }}>Stacked &mdash; rates above instructions (default)</option>
                        <option value="split" {{ old('public_layout', $setting->public_layout) === 'split' ? 'selected' : '' }}>Side by side &mdash; rates left, instructions right</option>
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
                    <textarea class="form-control" id="public_blurb" name="public_blurb" rows="3" maxlength="2000"
                              placeholder="A short pitch shown under the headline.">{{ old('public_blurb', $setting->public_blurb) }}</textarea>
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
                            <img src="{{ route('buyback-manager.public.image', ['ticker' => $setting->corp_ticker, 'kind' => 'background']) }}?v={{ optional($setting->updated_at)->timestamp }}"
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
                        <img src="{{ $setting->public_logo_path ? route('buyback-manager.public.image', ['ticker' => $setting->corp_ticker, 'kind' => 'logo']) . '?v=' . optional($setting->updated_at)->timestamp : ('https://images.evetech.net/corporations/' . $setting->corporation_id . '/logo?size=64') }}"
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
                    <div class="custom-control custom-checkbox" style="margin-top:10px;">
                        <input type="checkbox" class="custom-control-input" id="public_logo_bg" name="public_logo_bg" value="1" {{ old('public_logo_bg', $setting->public_logo_bg) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="public_logo_bg">Show a solid square background behind the logo</label>
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
