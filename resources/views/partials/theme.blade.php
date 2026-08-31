<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Instrument+Sans:wght@400;500;600&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
    /* Quikbooth theme — single source of truth. Scale, brand hues and components
       live on :root; the two rooms (ctx-dark = the booth, ctx-light = the album)
       only reassign colour. Near-black rooms, one electric blue, ivory type, and
       film perforations down every edge. */
    :root {
        --font-display: "Instrument Serif", Georgia, "Times New Roman", serif;
        --font-sans: "Instrument Sans", system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        --font-mono: "DM Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;

        /* Brand hues — identical in both rooms. */
        --blue: #3A86FF; --purple: #8338EC; --pink: #FF006E; --orange: #FB5607; --yellow: #FFBE0B;
        --ink: #0B0B10; --ivory: #F4F2ED;

        --text-2xs: .6875rem; --text-xs: .75rem; --text-sm: .9375rem; --text-base: 1rem;
        --text-md: 1.0625rem; --text-lg: 1.375rem;
        --display-sm: 2.125rem;
        --display-md: clamp(2.25rem, 6vw, 3rem);
        --display-lg: clamp(2.75rem, 12vw, 3.75rem);
        --display-xl: clamp(3.25rem, 14vw, 4.25rem);
        --display-2xl: clamp(3rem, 9vw, 5.5rem);
        --leading-tight: .95; --leading-snug: 1.15; --leading-normal: 1.55;
        --tracking-tight: -.02em; --tracking-mono: .16em; --tracking-label: .2em;

        --space-2xs: 4px; --space-xs: 8px; --space-sm: 12px; --space-md: 16px;
        --space-lg: 24px; --space-xl: 40px; --space-2xl: 64px; --space-3xl: 96px;

        --r-sm: 8px; --r-md: 12px; --r-lg: 20px; --r-xl: 26px; --r-pill: 999px;
        --measure: 1180px; --page-gutter: clamp(20px, 5vw, 56px);
        --ease: cubic-bezier(.2, .6, .2, 1); --dur: 160ms;

        --strip-tilt: -2deg;

        --shadow-sm: 0 1px 2px rgba(18,18,26,.05), 0 2px 6px rgba(18,18,26,.06);
        --shadow-md: 0 2px 6px rgba(18,18,26,.06), 0 18px 40px rgba(18,18,26,.10);
        --shadow-lg: 0 4px 12px rgba(18,18,26,.08), 0 30px 70px rgba(18,18,26,.16);
    }

    /* The booth: a near-black room. */
    .ctx-dark {
        --bg: #0B0B10; --bg-elev: #15151D; --surface: #15151D; --surface-sunk: #101017;
        --text: #F4F2ED; --text-muted: #9C9B93; --text-faint: #84837A;
        --line: #1E1E28; --line-strong: #2E2E3A;
        --danger: #FF006E;
        --ok: var(--blue);

        --btn-bg: var(--blue); --btn-text: #FFFFFF;
        --btn-glow: 0 14px 40px rgba(58,134,255,.42);

        /* Sprocket holes: page edges, and the mat a strip sits in. */
        --perf-bg: #0B0B10; --perf-hole: #22222C;
        --mat: #12121A; --mat-hole: #2A2A34;

        --shadow-md: 0 2px 8px rgba(0,0,0,.40), 0 14px 34px rgba(0,0,0,.50);
        --shadow-lg: 0 6px 18px rgba(0,0,0,.50), 0 30px 70px rgba(0,0,0,.60);
        color-scheme: dark;
    }

    /* The album: ivory paper. */
    .ctx-light {
        --bg: #F4F2ED; --bg-elev: #FFFFFF; --surface: #FFFFFF; --surface-sunk: #EFECE5;
        /* On paper there is no room for a third step above the 4.5:1 floor, so
           --text-faint sits just one notch off --text-muted. The hierarchy here
           comes from size, weight and case (11px mono caps vs 15px sans). */
        --text: #12121A; --text-muted: #6B6A63; --text-faint: #6E6D65;
        --line: #DAD7CF; --line-strong: #C9C5BB;
        --danger: #C1123F;
        --ok: var(--blue);

        --btn-bg: #12121A; --btn-text: var(--ivory);
        --btn-glow: none;

        --perf-bg: #FFFFFF; --perf-hole: #E4E1D9;
        --mat: #FFFFFF; --mat-hole: #E4E1D9;
        color-scheme: light;
    }

    * { box-sizing: border-box; }
    [hidden] { display: none !important; } /* capture.ts toggles screens via .hidden — never let a display rule win */
    /* A context can be nested (a dark island on a light page). Inherited colour
       is already computed, so the island has to re-read --text for itself. */
    .ctx-dark, .ctx-light { color: var(--text); }
    body {
        margin: 0; min-height: 100dvh;
        background: var(--bg); color: var(--text);
        font-family: var(--font-sans); font-size: var(--text-md); line-height: var(--leading-normal);
        -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility;
    }

    h1, h2, h3 {
        font-family: var(--font-display); font-weight: 400;
        line-height: var(--leading-tight); letter-spacing: var(--tracking-tight);
        margin: 0 0 var(--space-md); text-wrap: balance;
    }
    h1 { font-size: var(--display-lg); }
    h2 { font-size: var(--display-sm); }
    p { text-wrap: pretty; }

    /* Mono is for machine-ish things: codes, counts, labels. */
    .eyebrow, .mono {
        font-family: var(--font-mono); font-size: var(--text-2xs); font-weight: 400;
        letter-spacing: var(--tracking-label); text-transform: uppercase; color: var(--text-faint);
        margin: 0;
    }
    .mono--plain { text-transform: none; letter-spacing: .06em; font-size: var(--text-xs); }
    .muted, .consent-note { color: var(--text-muted); font-size: var(--text-sm); }
    .sr-only {
        position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
        overflow: hidden; clip-path: inset(50%); white-space: nowrap; border: 0;
    }
    ::selection { background: var(--blue); color: #fff; }

    /* --- Buttons: pills. Primary is blue in the booth, ink on paper. --- */
    button, .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: .55em;
        font-family: var(--font-sans); font-size: var(--text-md); font-weight: 600; line-height: 1;
        min-height: 56px; padding: .9rem 1.75rem; margin: 0;
        border: 1px solid transparent; border-radius: var(--r-pill);
        background: var(--btn-bg); color: var(--btn-text); box-shadow: var(--btn-glow);
        cursor: pointer; text-decoration: none; -webkit-tap-highlight-color: transparent;
        transition: transform var(--dur) var(--ease), box-shadow var(--dur) var(--ease),
            background var(--dur) var(--ease), border-color var(--dur) var(--ease), opacity var(--dur) var(--ease);
    }
    button:hover, .btn:hover { transform: translateY(-1px); }
    button:active, .btn:active { transform: translateY(0); }

    /* The marquee call to action — blue and glowing even on paper. */
    .btn--accent { background: var(--blue); color: #FFFFFF; box-shadow: 0 14px 40px rgba(58,134,255,.42); }
    /* For use on a saturated (purple) screen, where blue would disappear. */
    .btn--light { background: #FFFFFF; color: #4B0F91; box-shadow: none; }

    button.secondary, .btn--ghost {
        background: transparent; color: var(--text); border-color: var(--line-strong); box-shadow: none;
        font-weight: 500; font-size: var(--text-base); min-height: 52px;
    }
    button.secondary:hover, .btn--ghost:hover { background: color-mix(in srgb, var(--text) 8%, transparent); box-shadow: none; }

    .btn--hero { font-size: var(--text-lg); min-height: 68px; padding: 1rem 2.25rem; }
    .btn--small { min-height: 40px; padding: .4rem 1.1rem; font-size: var(--text-sm); font-weight: 500; }

    /* A PAIR of equal-width secondary actions under a primary one. Tighter
       padding than a standalone pill so two-word labels stay on one line, and no
       nowrap — a label that still doesn't fit wraps inside its pill rather than
       spilling over the border. Three children will not fit on a phone. */
    .btn-row { display: flex; gap: var(--space-sm); }
    .btn-row > * { flex: 1 1 0; min-width: 0; padding-inline: var(--space-sm); line-height: 1.15; }

    .delete button, .btn--danger {
        display: inline; min-height: auto; padding: .35rem 0; gap: 0;
        border: none; border-bottom: 1px solid transparent; border-radius: 0;
        background: none; box-shadow: none; transform: none;
        font-size: var(--text-sm); font-weight: 500; color: var(--text-faint);
    }
    .delete button:hover, .btn--danger:hover {
        color: var(--danger); border-bottom-color: currentColor;
        transform: none; box-shadow: none; background: none;
    }
    button:disabled, .btn:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }

    a { color: var(--blue); text-decoration-line: underline; text-decoration-thickness: 1px;
        text-underline-offset: 3px; text-decoration-color: color-mix(in srgb, var(--blue) 45%, transparent);
        transition: text-decoration-color var(--dur) var(--ease), color var(--dur) var(--ease); }
    a:hover { text-decoration-color: currentColor; }

    /* --- Fields: a ruled line, not a box. --- */
    input, select {
        font-family: var(--font-sans); font-size: var(--text-lg); font-weight: 500;
        width: 100%; padding: .55rem 0; color: var(--text);
        background: transparent; border: 0; border-bottom: 2px solid var(--line-strong);
        border-radius: 0; transition: border-color var(--dur) var(--ease);
    }
    input:hover, select:hover { border-bottom-color: var(--text-muted); }
    input:focus, select:focus { border-bottom-color: var(--blue); }
    input::placeholder { color: var(--text-faint); font-weight: 400; }
    input[type="file"] { font-size: var(--text-sm); font-weight: 400; padding: .5rem 0; }
    input[type="file"]::file-selector-button {
        font: inherit; font-weight: 500; margin-right: var(--space-sm);
        padding: .3rem .9rem; color: var(--text); background: transparent;
        border: 1px solid var(--line-strong); border-radius: var(--r-pill); cursor: pointer;
    }
    input[type="file"]::file-selector-button:hover { background: color-mix(in srgb, var(--text) 8%, transparent); }
    input[type="checkbox"] { width: auto; }
    select {
        -webkit-appearance: none; appearance: none; cursor: pointer;
        background-image: linear-gradient(45deg, transparent 50%, currentColor 50%), linear-gradient(135deg, currentColor 50%, transparent 50%);
        background-position: right 6px top 58%, right 1px top 58%;
        background-size: 5px 5px; background-repeat: no-repeat;
    }

    fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
    legend { padding: 0; }
    .field { display: flex; flex-direction: column; gap: var(--space-2xs); }
    .field > label, .field-label {
        font-family: var(--font-mono); font-size: var(--text-2xs);
        letter-spacing: var(--tracking-mono); text-transform: uppercase; color: var(--text-muted);
    }
    .hint { margin: 0; font-size: var(--text-xs); color: var(--text-faint); }
    .error { color: var(--danger); font-size: var(--text-sm); margin: 0; }

    /* --- Code entry: six tiles standing in for the field. The real input sits
       on top, invisible, so the phone keyboard and autofill still work; without
       JS the field renders as a plain ruled input instead. --- */
    .join-form button { width: 100%; margin-top: var(--space-lg); }
    .join-form .error { margin-top: var(--space-sm); }
    .code-entry { position: relative; }
    .code-entry input { text-align: center; letter-spacing: .3ch; text-transform: uppercase; }
    .code-entry .tiles { display: none; }
    .code-entry.tiled .tiles { display: flex; gap: var(--space-xs); }
    .code-entry.tiled input {
        position: absolute; inset: 0; z-index: 1;
        height: 100%; padding: 0; border: 0; opacity: 0; font-size: 1rem;
    }
    .code-entry.tiled:focus-within { outline: 2px solid var(--blue); outline-offset: 6px; border-radius: var(--r-md); }
    .tile {
        flex: 1; min-width: 0; aspect-ratio: 3 / 4; border-radius: 10px;
        background: var(--bg-elev); border: 1px solid var(--line-strong);
        display: grid; place-items: center;
        font-family: var(--font-mono); font-weight: 500; font-size: clamp(1.25rem, 6.5vw, 1.875rem);
    }
    .tile.empty { background: var(--surface-sunk); border-color: var(--line); }
    .tile.caret { border-color: var(--blue); color: var(--blue); }
    .tile.caret::after { content: "|"; animation: caret-pulse 1.2s var(--ease) infinite; }
    @keyframes caret-pulse { 0%, 100% { opacity: 1 } 50% { opacity: .3 } }

    /* --- Swatch pickers: the layout and the strip colour are chosen by eye.
       strip-preview.ts paints the cells and the hues from the same modules the
       canvas uses, so there is one source of truth for both. --- */
    .swatches { display: flex; flex-wrap: wrap; gap: 10px; margin-top: var(--space-2xs); }
    .swatches label { display: block; cursor: pointer; }
    .layout-swatch {
        display: flex; flex-direction: column; gap: 3px;
        width: 56px; height: 72px; padding: 5px;
        background: var(--bg-elev); border: 1px solid var(--line-strong); border-radius: var(--r-sm);
    }
    .layout-swatch.is-grid { display: grid; grid-template-columns: 1fr 1fr; }
    .layout-swatch i { display: block; flex: 1; min-height: 0; background: var(--line); }
    .colour-swatch { display: block; width: 36px; height: 36px; border-radius: 50%; background: var(--line); }
    .swatches input:checked + .layout-swatch { border-color: var(--blue); box-shadow: inset 0 0 0 1px var(--blue); }
    .swatches input:checked + .colour-swatch { box-shadow: 0 0 0 2px var(--bg), 0 0 0 4px var(--blue); }
    .swatches input:focus-visible + * { outline: 2px solid var(--blue); outline-offset: 3px; }

    :where(button, .btn, a, input, select, summary):focus-visible {
        outline: 2px solid var(--blue); outline-offset: 3px; border-radius: var(--r-sm);
    }

    /* --- Surfaces --- */
    .card {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--r-lg); box-shadow: var(--shadow-sm); padding: var(--space-xl);
    }
    .code {
        font-family: var(--font-mono); font-weight: 500;
        letter-spacing: var(--tracking-label); margin: 0;
    }

    /* Film sprocket holes. .perf-edge runs down a page or panel edge;
       .strip-mat is the perforated sleeve a composed strip sits in. */
    .perf-edge {
        flex: 0 0 16px; align-self: stretch;
        background: repeating-linear-gradient(var(--perf-hole) 0 12px, var(--perf-bg) 12px 30px);
    }
    .strip-mat {
        display: flex; background: var(--mat); border-radius: var(--r-sm);
        box-shadow: var(--shadow-lg); overflow: hidden;
    }
    .strip-mat::before, .strip-mat::after {
        content: ""; flex: 0 0 11px;
        background: repeating-linear-gradient(var(--mat-hole) 0 7px, var(--mat) 7px 18px);
    }
    .strip-mat > img { flex: 1; min-width: 0; display: block; width: 100%; height: auto; margin: 9px 0; }
    .strip-mat--tilt { rotate: var(--strip-tilt); }

    /* --- Chrome: the thin mono bar at the top of host and album pages. --- */
    .topbar {
        display: flex; justify-content: space-between; align-items: center; gap: var(--space-md);
        padding: var(--space-md) var(--page-gutter); border-bottom: 1px solid var(--line);
    }
    .topbar .wordmark {
        font-family: var(--font-mono); font-size: var(--text-xs);
        letter-spacing: var(--tracking-label); text-transform: uppercase; color: var(--text);
        text-decoration: none;
    }
    .topbar-right { display: flex; align-items: center; gap: var(--space-sm); }

    /* Big serif number over a mono caption. */
    .stats { display: flex; flex-wrap: wrap; gap: var(--space-xl); }
    .stat p { margin: 0; }
    .stat .figure { font-family: var(--font-display); font-size: var(--display-sm); line-height: 1; }
    .stat .label {
        font-family: var(--font-mono); font-size: var(--text-2xs);
        letter-spacing: var(--tracking-mono); text-transform: uppercase; color: var(--text-muted);
    }

    /* Pill filters / segmented controls. */
    .chips { display: flex; gap: var(--space-xs); flex-wrap: wrap; }
    .chip {
        min-height: 40px; padding: .35rem 1.1rem; font-size: var(--text-sm); font-weight: 500;
        border-radius: var(--r-pill); background: transparent; color: var(--text);
        border: 1px solid var(--line-strong); box-shadow: none;
    }
    .chip.selected { background: var(--btn-bg); color: var(--btn-text); border-color: transparent; }

    /* Invite / share affordance. */
    .share { display: flex; flex-wrap: wrap; gap: var(--space-sm); align-items: center; }
    .share .link-chip {
        font-family: var(--font-mono); font-size: var(--text-xs); color: var(--text-muted);
        background: color-mix(in srgb, var(--text) 6%, transparent);
        border: 1px solid var(--line); border-radius: var(--r-pill);
        padding: .45rem 1rem; user-select: all;
        min-width: 0; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    /* --ok, not --blue: on the purple done screen blue is unreadable, so that
       screen remaps it (this is the only signal that the copy worked). */
    .share .share-copy.copied { color: var(--ok); border-color: var(--ok); }

    .qr { display: inline-block; background: var(--ivory); padding: var(--space-md); border-radius: var(--r-md); }
    .qr svg { display: block; width: min(240px, 62vw); height: auto; }

    @media (prefers-reduced-motion: reduce) {
        button, .btn, a, input, select { transition: none; }
        button:hover, .btn:hover { transform: none; }
        .tile.caret::after { animation: none; }
        .strip-mat--tilt { rotate: none; }
    }
</style>
