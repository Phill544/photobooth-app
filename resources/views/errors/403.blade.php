{{-- Someone else's event. Almost always a host signed in as the wrong
     account, or a bookmarked owner page for an event that was handed on —
     so this says whose it is rather than accusing anyone of anything. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Not your event — Quikbooth</title>
    @include('partials.theme')
    <style>
        .room { display: flex; min-height: 100dvh; }
        main { flex: 1; min-width: 0; display: flex; flex-direction: column;
            padding: var(--space-xl) var(--space-lg) var(--space-lg);
            max-width: 460px; margin: 0 auto; }
        h1 { font-size: var(--display-lg); margin: var(--space-lg) 0 0; }
        .lede { margin: var(--space-sm) 0 0; color: var(--text-muted); font-size: var(--text-base); }
        form { margin-top: var(--space-xl); }

        .host {
            margin-top: auto; padding-top: var(--space-xl); border-top: 1px solid var(--line);
            display: flex; flex-wrap: wrap; gap: var(--space-sm);
            justify-content: space-between; align-items: center;
        }
        .host p { margin: 0; color: var(--text-faint); font-size: var(--text-base); }
        .host a { color: var(--text); font-weight: 500; text-decoration: none; }
        .host a:hover { text-decoration-line: underline; }
    </style>
</head>
<body class="ctx-dark">
    <div class="room">
        <div class="perf-edge"></div>
        <main>
            <p class="eyebrow">Locked</p>
            <h1>This isn’t your event.</h1>
            <p class="lede">Whoever created it manages it. If you are the host, you may be signed in with a
                different account. Guests only ever need the six-character code:</p>

            @include('partials.code-entry')

            <div class="host">
                <p>Running an event?</p>
                <a href="/dashboard">Host sign in →</a>
            </div>
        </main>
        <div class="perf-edge"></div>
    </div>
</body>
</html>
