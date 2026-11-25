{{-- Shared look for every Buyback Manager settings page.

     Kept INLINE rather than in the published buyback-manager.css so a
     `view:clear` is enough to pick it up, with no asset republish needed. Every
     rule is scoped to .buyback-manager-wrapper so nothing leaks into the rest
     of SeAT. --}}
<style>
    /* Toggle switches. Pure CSS on the native checkbox, so name / value /
       checked are untouched and every existing form keeps working; the input
       just stops looking like a checkbox. Covers all three markup styles used
       across these pages (.checkbox > label > input, .custom-control-input and
       bare inputs). The knob is a background-image rather than ::before
       because Chrome does not render pseudo-elements on <input>. Bootstrap
       hides .custom-control-input (opacity 0, behind the label) so its own
       drawn box can show instead; that box is switched off below, so the
       input has to be brought back or the switch is invisible. */
    .buyback-manager-wrapper input[type="checkbox"] {
        -webkit-appearance: none;
        appearance: none;
        position: relative;
        left: auto;
        z-index: auto;
        opacity: 1;
        display: inline-block;
        box-sizing: border-box;
        flex-shrink: 0;
        width: 42px;
        height: 22px;
        margin: 0 8px 0 0;
        padding: 0;
        border-radius: 22px;
        background-color: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.28);
        background-image: radial-gradient(circle 8px at center, #fff 96%, transparent 100%);
        background-repeat: no-repeat;
        background-size: 22px 22px;
        background-position: 0 center;
        vertical-align: middle;
        cursor: pointer;
        transition: background-color 0.2s ease, background-position 0.2s ease, border-color 0.2s ease;
        float: none;
        transform: none !important;
        box-shadow: none !important;
    }
    .buyback-manager-wrapper input[type="checkbox"]:checked {
        background-color: var(--bb-primary-start, #667eea);
        border-color: var(--bb-primary-start, #667eea);
        background-position: 20px center;
    }
    .buyback-manager-wrapper input[type="checkbox"]:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.35) !important;
    }
    .buyback-manager-wrapper input[type="checkbox"]:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Bootstrap indents these for a native checkbox; the toggle supplies its
       own spacing, so drop the reserved gutter. */
    .buyback-manager-wrapper .checkbox label,
    .buyback-manager-wrapper .custom-control.custom-checkbox {
        padding-left: 0;
    }
    .buyback-manager-wrapper .checkbox label {
        display: flex;
        align-items: center;
        cursor: pointer;
        margin-bottom: 0;
    }
    /* Bootstrap 4 gives .checkbox no margin, so two toggles in a row touch
       and read as one switch. */
    .buyback-manager-wrapper .checkbox + .checkbox {
        margin-top: 10px;
    }
    .buyback-manager-wrapper .custom-control-label {
        cursor: pointer;
        vertical-align: middle;
    }
    .buyback-manager-wrapper .custom-control-label::before,
    .buyback-manager-wrapper .custom-control-label::after {
        display: none !important;
    }

    /* Sliders. The browser's own track is near invisible on the dark card. */
    .buyback-manager-wrapper input[type="range"] {
        -webkit-appearance: none;
        appearance: none;
        width: 100%;
        max-width: 340px;
        height: 22px;
        background: transparent;
        cursor: pointer;
    }
    .buyback-manager-wrapper input[type="range"]::-webkit-slider-runnable-track {
        height: 6px;
        border-radius: 3px;
        background: rgba(255, 255, 255, 0.16);
    }
    .buyback-manager-wrapper input[type="range"]::-moz-range-track {
        height: 6px;
        border-radius: 3px;
        background: rgba(255, 255, 255, 0.16);
    }
    .buyback-manager-wrapper input[type="range"]::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 18px;
        height: 18px;
        margin-top: -6px;
        border-radius: 50%;
        background: var(--bb-primary-start, #667eea);
        border: 2px solid #fff;
    }
    .buyback-manager-wrapper input[type="range"]::-moz-range-thumb {
        width: 14px;
        height: 14px;
        border-radius: 50%;
        background: var(--bb-primary-start, #667eea);
        border: 2px solid #fff;
    }
    .buyback-manager-wrapper input[type="range"]:focus {
        outline: none;
    }
    .buyback-manager-wrapper input[type="range"]:focus::-webkit-slider-thumb {
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.35);
    }

    /* Settings that only matter while something else is off stay readable
       but step back; the label above them keeps full strength. */
    .buyback-manager-wrapper .bb-dimmed {
        opacity: 0.55;
    }

    .buyback-manager-wrapper .bb-format-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        margin-bottom: 6px;
    }
    .buyback-manager-wrapper .bb-format-bar .btn {
        min-width: 34px;
    }

    /* Category badges. The colour matches the Discord embed for the same
       category, so the two surfaces read as one system. */
    .buyback-manager-wrapper .bb-cat-badge {
        display: inline-block;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.6;
        padding: 1px 9px;
        border-radius: 11px;
        margin: 0 4px 4px 0;
        white-space: nowrap;
        border: 1px solid transparent;
    }
</style>
