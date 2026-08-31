{{-- Every 404 lands here. A wrong event code gets named (`$code`, set in
     bootstrap/app.php when an Event binding fails); anything else just gets
     the way back in. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($code) ? 'No booth with that code' : 'Page not found' }} — Quikbooth</title>
    @include('partials.theme')
    <style>
        .room { display: flex; min-height: 100dvh; }
        main { flex: 1; min-width: 0; display: flex; flex-direction: column;
            padding: var(--space-xl) var(--space-lg) var(--space-lg);
            max-width: 460px; margin: 0 auto; }
        h1 { font-size: var(--display-lg); margin: var(--space-lg) 0 0; }
        .tried { margin: var(--space-md) 0 0; color: var(--text-muted); font-size: var(--text-sm); }
        .tried span {
            font-family: var(--font-mono); font-weight: 500; color: var(--text);
            letter-spacing: var(--tracking-label); overflow-wrap: anywhere;
            text-decoration: line-through; text-decoration-color: var(--danger);
        }
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
            <p class="eyebrow">Not found</p>
            @isset ($code)
                <h1>No booth with that code.</h1>
                <p class="tried">You tried <span>{{ $code }}</span></p>
                <p class="lede">Codes never use O, 0, 1 or I — the usual culprits when one is read
                    off a sign. Have another go:</p>
            @else
                <h1>That page isn’t here.</h1>
                <p class="lede">Got an event code? Six characters gets you into the booth.</p>
            @endisset

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
