<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Photobooth</title>
    @include('partials.theme')
    <style>
        .room { display: flex; min-height: 100dvh; }
        main { flex: 1; min-width: 0; display: flex; flex-direction: column;
            padding: var(--space-xl) var(--space-lg) var(--space-lg);
            max-width: 460px; margin: 0 auto; }
        h1 { font-size: var(--display-lg); margin: var(--space-lg) 0 0; }
        .lede { margin: var(--space-sm) 0 0; color: var(--text-muted); font-size: var(--text-base); }
        form { margin-top: var(--space-xl); }

        /* Six tiles standing in for the code field. The real input sits on top,
           invisible, so the phone keyboard and autofill still work; without JS
           the field renders as a plain ruled input instead. */
        .code-entry { position: relative; }
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

        #code { text-align: center; letter-spacing: .3ch; text-transform: uppercase; }
        form button { width: 100%; margin-top: var(--space-lg); }

        .host {
            margin-top: auto; padding-top: var(--space-xl); border-top: 1px solid var(--line);
            display: flex; flex-wrap: wrap; gap: var(--space-sm);
            justify-content: space-between; align-items: center;
        }
        .host p { margin: 0; color: var(--text-faint); font-size: var(--text-base); }
        .host a { color: var(--text); font-weight: 500; text-decoration: none; }
        .host a:hover { text-decoration-line: underline; }

        @media (prefers-reduced-motion: reduce) { .tile.caret::after { animation: none; } }
    </style>
</head>
<body class="ctx-dark">
    <div class="room">
        <div class="perf-edge"></div>
        <main>
            <p class="eyebrow">Photobooth</p>
            <h1>Got a code?</h1>
            <p class="lede">Six characters on the sign, the table card, or the QR.</p>

            <form id="join">
                <label class="sr-only" for="code">Event code</label>
                <div class="code-entry">
                    <input id="code" name="code" maxlength="6" autocapitalize="characters"
                           autocomplete="off" spellcheck="false" placeholder="CODE" required>
                    <div class="tiles" aria-hidden="true"></div>
                </div>
                <button class="btn--hero">Enter the booth</button>
            </form>

            <div class="host">
                <p>Running an event?</p>
                <a href="/dashboard">Host sign in →</a>
            </div>
        </main>
        <div class="perf-edge"></div>
    </div>

    <script>
        const input = document.querySelector('#code');
        const entry = document.querySelector('.code-entry');
        const tiles = document.querySelector('.tiles');

        // Swap the plain input for the tile display; only runs when JS does.
        for (let i = 0; i < input.maxLength; i++) tiles.appendChild(document.createElement('span'));
        entry.classList.add('tiled');

        const paint = () => {
            const code = input.value.toUpperCase();
            [...tiles.children].forEach((tile, index) => {
                tile.textContent = code[index] ?? '';
                tile.className = 'tile' + (code[index] ? '' : index === code.length ? ' caret' : ' empty');
            });
        };
        input.addEventListener('input', paint);
        paint();
        input.focus();

        document.querySelector('#join').addEventListener('submit', (event) => {
            event.preventDefault();
            location.href = `/e/${encodeURIComponent(input.value.trim())}`;
        });
    </script>
</body>
</html>
