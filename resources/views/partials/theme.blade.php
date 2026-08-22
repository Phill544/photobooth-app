<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&display=swap" rel="stylesheet">
<style>
    /* Photobooth theme — single source of truth. Scale/celebration on :root;
       the two contexts (ctx-dark = booth, ctx-light = album) only reassign colour. */
    :root {
        --font-display: "Fraunces", "Playfair Display", Georgia, serif;
        --font-sans: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;

        --text-xs: .8125rem; --text-sm: .9375rem; --text-base: 1rem; --text-lg: 1.1875rem;
        --text-xl: 1.5rem; --text-2xl: 1.9375rem; --text-3xl: 2.4375rem; --text-4xl: 3.0625rem;
        --text-hero: clamp(2.75rem, 8vw, 3.75rem);
        --leading-tight: 1.1; --leading-normal: 1.55;
        --tracking-tight: -.02em; --tracking-wide: .14em;

        --space-2xs: 4px; --space-xs: 8px; --space-sm: 12px; --space-md: 16px;
        --space-lg: 24px; --space-xl: 40px; --space-2xl: 64px; --space-3xl: 96px;

        --r-sm: 6px; --r-md: 10px; --r-lg: 16px; --r-pill: 999px;
        --measure: 1120px; --page-gutter: clamp(16px, 5vw, 64px);
        --ease: cubic-bezier(.2, .6, .2, 1); --dur: 160ms;

        --grad-celebrate: linear-gradient(120deg, #F3C06B 0%, #E7B15E 45%, #E8846B 100%);
        --strip-tilt: -1.5deg;

        --shadow-sm: 0 1px 2px rgba(23,21,15,.04), 0 2px 6px rgba(23,21,15,.06);
        --shadow-md: 0 2px 6px rgba(23,21,15,.05), 0 10px 28px rgba(23,21,15,.07);
        --shadow-lg: 0 4px 12px rgba(23,21,15,.08), 0 24px 56px rgba(23,21,15,.12);
    }

    .ctx-dark {
        --bg: #0F0F12; --bg-elev: #17171B; --surface: #1C1C21;
        --text: #F6F5F2; --text-muted: #A6A29B; --line: #2C2C33; --line-strong: #3A3A42;
        --accent: #E7B15E; --accent-ink: #171205; --danger: #E07A6B;
        --btn-bg: #FFFFFF; --btn-text: #14140F;
        --btn-ghost-text: #F6F5F2; --btn-ghost-border: #3A3A42;
        --shadow-md: 0 2px 8px rgba(0,0,0,.40), 0 12px 32px rgba(0,0,0,.50);
        --shadow-lg: 0 6px 18px rgba(0,0,0,.50), 0 28px 64px rgba(0,0,0,.60);
        color-scheme: dark;
    }

    .ctx-light {
        --bg: #FAF8F4; --bg-elev: #FFFFFF; --surface: #FFFFFF;
        --text: #17150F; --text-muted: #6E6A61; --line: #E8E3DA; --line-strong: #D8D2C7;
        --accent: #9E571E; --accent-ink: #FFFFFF; --danger: #A23A2E;
        --btn-bg: #17150F; --btn-text: #FAF8F4;
        --btn-ghost-text: #17150F; --btn-ghost-border: #D8D2C7;
        color-scheme: light;
    }

    * { box-sizing: border-box; }
    [hidden] { display: none !important; } /* capture.ts toggles screens via .hidden — never let a display rule win */
    body {
        margin: 0; min-height: 100dvh;
        background: var(--bg); color: var(--text);
        font-family: var(--font-sans); font-size: var(--text-base); line-height: var(--leading-normal);
        -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility;
    }
    h1, h2, h3 {
        font-family: var(--font-display); font-weight: 500; line-height: var(--leading-tight);
        letter-spacing: var(--tracking-tight); margin: 0 0 var(--space-md);
    }
    h1 { font-size: var(--text-3xl); }
    .eyebrow { font-size: var(--text-xs); letter-spacing: var(--tracking-wide); text-transform: uppercase; color: var(--text-muted); }
    .muted, .consent-note { color: var(--text-muted); font-size: var(--text-sm); }
    ::selection { background: var(--accent); color: var(--accent-ink); }

    button, .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: .5em;
        font-family: var(--font-sans); font-size: var(--text-lg); font-weight: 500; line-height: 1;
        min-height: 52px; padding: .85rem 1.75rem; margin-top: var(--space-md);
        border: 1px solid transparent; border-radius: var(--r-pill);
        background: var(--btn-bg); color: var(--btn-text); cursor: pointer;
        -webkit-tap-highlight-color: transparent; text-decoration: none;
        transition: transform var(--dur) var(--ease), box-shadow var(--dur) var(--ease),
            background var(--dur) var(--ease), border-color var(--dur) var(--ease), opacity var(--dur) var(--ease);
    }
    button:hover, .btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); }
    button:active, .btn:active { transform: translateY(0); box-shadow: var(--shadow-sm); }

    button.secondary, .btn--ghost {
        background: transparent; color: var(--btn-ghost-text); border-color: var(--btn-ghost-border); box-shadow: none;
    }
    button.secondary:hover, .btn--ghost:hover { background: color-mix(in srgb, var(--text) 8%, transparent); box-shadow: none; }

    .btn--hero { font-size: var(--text-xl); min-height: 60px; padding: 1rem 2.5rem; box-shadow: var(--shadow-md); }

    .delete button, .btn--danger {
        display: inline; min-height: auto; margin: 0; padding: .35rem 0; gap: 0;
        border: none; border-bottom: 1px solid transparent; border-radius: 0;
        background: none; box-shadow: none; transform: none;
        font-size: var(--text-sm); font-weight: 500; color: var(--text-muted);
    }
    .delete button:hover, .btn--danger:hover { color: var(--danger); border-bottom-color: currentColor; transform: none; box-shadow: none; background: none; }
    button:disabled, .btn:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }

    a { color: var(--accent); text-decoration-line: underline; text-decoration-thickness: 1px;
        text-underline-offset: 3px; text-decoration-color: color-mix(in srgb, var(--accent) 45%, transparent);
        transition: text-decoration-color var(--dur) var(--ease), color var(--dur) var(--ease); }
    a:hover { text-decoration-color: currentColor; }

    input {
        font-family: var(--font-sans); font-size: var(--text-xl); padding: .7rem 1rem; text-align: center;
        color: var(--text); background: var(--bg-elev); border: 1px solid var(--line-strong);
        border-radius: var(--r-md); transition: border-color var(--dur) var(--ease), box-shadow var(--dur) var(--ease);
    }
    input::placeholder { color: var(--text-muted); }
    input#code { font-family: var(--font-display); font-size: var(--text-2xl); letter-spacing: .35ch; text-transform: uppercase; width: 7ch; }

    :where(button, .btn, a, input):focus-visible {
        outline: none; box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 50%, transparent); border-radius: var(--r-md);
    }

    .card {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg);
        box-shadow: var(--shadow-sm); padding: var(--space-xl);
    }
    .card .code { font-family: var(--font-display); font-size: var(--text-hero); font-weight: 600; letter-spacing: .12em; margin: var(--space-sm) 0; }
    .qr { display: inline-block; background: #fff; padding: var(--space-md); border-radius: var(--r-md); }
    .qr svg { display: block; width: min(280px, 72vw); height: auto; }

    /* Invite / share affordance */
    .share { display: flex; flex-wrap: wrap; gap: var(--space-sm); align-items: center; justify-content: center; margin-top: var(--space-lg); }
    .share .link-chip {
        font-family: var(--font-sans); font-size: var(--text-sm); color: var(--text-muted);
        background: color-mix(in srgb, var(--text) 6%, transparent);
        border: 1px solid var(--line); border-radius: var(--r-pill);
        padding: .4rem .9rem; user-select: all;
        min-width: 0; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .share .share-copy.copied { color: var(--accent); border-color: var(--accent); }

    .celebrate-title { background: var(--grad-celebrate); -webkit-background-clip: text; background-clip: text; color: transparent; }

    @media (prefers-reduced-motion: reduce) {
        button, .btn, a, input { transition: none; }
        button:hover, .btn:hover { transform: none; }
    }
</style>
