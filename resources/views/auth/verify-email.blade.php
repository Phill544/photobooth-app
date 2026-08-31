<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm your address — Photobooth</title>
    @include('partials.theme')
    <style>
        .room { display: flex; min-height: 100dvh; }
        main { flex: 1; min-width: 0; max-width: 420px; margin: 0 auto;
            padding: var(--space-2xl) var(--space-lg); align-self: center; }
        h1 { margin: var(--space-md) 0 0; }
        .lede { margin: var(--space-md) 0 0; color: var(--text-muted); font-size: var(--text-sm); }
        .addr { font-family: var(--font-mono); color: var(--text); overflow-wrap: anywhere; }
        form { margin-top: var(--space-xl); }
        form button { width: 100%; }
        .status { margin: var(--space-lg) 0 0; color: var(--ok); font-size: var(--text-sm); }
        .alt { margin-top: var(--space-xl); font-size: var(--text-sm); color: var(--text-muted); }
    </style>
</head>
<body class="ctx-dark">
    <div class="room">
        <div class="perf-edge"></div>
        <main>
            <p class="eyebrow">Host account</p>
            <h1>One tap to go.</h1>
            <p class="lede">We sent a link to <span class="addr">{{ $email }}</span>. Open it and you
                can build your first booth. Everything else — your events, their albums, the whole
                dashboard — is already yours; this only guards opening a new one.</p>

            <form method="POST" action="/email/resend">
                @csrf
                <button class="btn--hero">Send it again</button>
            </form>

            @if (session('status'))
                <p class="status" role="status">{{ session('status') }}</p>
            @endif

            <p class="alt">Wrong address? <a href="/dashboard">Back to your events</a> — or ask an
                admin to change it.</p>
        </main>
        <div class="perf-edge"></div>
    </div>
</body>
</html>
