<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The token is in this URL, so it must never be indexed or sent onward. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Set a new password — Quikbooth</title>
    @include('partials.theme')
    <style>
        .room { display: flex; min-height: 100dvh; }
        main { flex: 1; min-width: 0; max-width: 420px; margin: 0 auto;
            padding: var(--space-2xl) var(--space-lg); align-self: center; }
        h1 { margin: var(--space-md) 0 0; }
        .lede { margin: var(--space-md) 0 0; color: var(--text-muted); font-size: var(--text-sm); }
        form { display: flex; flex-direction: column; gap: var(--space-lg); margin-top: var(--space-xl); }
        button { width: 100%; }
        .alt { margin-top: var(--space-xl); font-size: var(--text-sm); color: var(--text-muted); }
        .way-out { margin: var(--space-lg) 0 0; font-size: var(--text-sm); }
        .way-out a { color: var(--text-muted); text-decoration: none; }
        .way-out a:hover { color: var(--text); text-decoration-line: underline; }
    </style>
</head>
<body class="ctx-dark">
    <div class="room">
        <div class="perf-edge"></div>
        <main>
            <p class="eyebrow">Host account</p>
            <h1>Set a new one</h1>
            <p class="lede">Eight characters or more. This link works once, so finish it here.</p>

            <form method="POST" action="/reset-password">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="field">
                    <label for="email">Email</label>
                    {{-- Prefilled from the link, but still editable: the address
                         is half of what the token is checked against, and a host
                         whose mail forwards may not arrive here as themselves. --}}
                    <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required
                           @unless ($email) autofocus @endunless>
                </div>
                <div class="field">
                    <label for="password">New password</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password"
                           @if ($email) autofocus @endif>
                </div>
                <div class="field">
                    <label for="password_confirmation">Again</label>
                    <input id="password_confirmation" name="password_confirmation" type="password"
                           required autocomplete="new-password">
                </div>
                <button class="btn--hero">Change my password</button>
                @error('email') <p class="error">{{ $message }}</p> @enderror
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </form>

            <p class="alt"><a href="/login">Back to log in</a></p>
            <p class="way-out"><a href="/">Got an event code? →</a></p>
        </main>
        <div class="perf-edge"></div>
    </div>
</body>
</html>
