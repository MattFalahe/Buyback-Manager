{{-- Shared look for every Buyback Manager settings page.

     Kept INLINE rather than in the published buyback-manager.css so a
     `view:clear` is enough to pick it up — no asset republish needed. Every
     rule is scoped to .buyback-manager-wrapper so nothing leaks into the rest
     of SeAT. --}}
<style>
    /* Toggle switches. Pure CSS on the native checkbox, so name / value /
       checked are untouched and every existing form keeps working — the input
       just stops looking like a checkbox. Covers all three markup styles used
       across these pages (.checkbox > label > input, .custom-control-input and
       bare inputs). The knob is a background-image rather than ::before
       because Chrome does not render pseudo-elements on <input>. */
    .buyback-manager-wrapper input[type="checkbox"] {
        -webkit-appearance: none;
        appearance: none;
        position: relative;
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
    }
    .buyback-manager-wrapper .custom-control-label {
        cursor: pointer;
        vertical-align: middle;
    }
    .buyback-manager-wrapper .custom-control-label::before,
    .buyback-manager-wrapper .custom-control-label::after {
        display: none !important;
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
