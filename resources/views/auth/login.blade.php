<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in — Photobooth</title>
    @include('partials.theme')
    <style>
        .room { display: flex; min-height: 100dvh; }
        main { flex: 1; min-width: 0; max-width: 420px; margin: 0 auto;
            padding: var(--space-2xl) var(--space-lg); align-self: center; }
        h1 { margin: var(--space-md) 0 0; }
        form { display: flex; flex-direction: column; gap: var(--space-lg); margin-top: var(--space-xl); }
        .remember { flex-direction: row; align-items: center; gap: var(--space-xs); }
        .remember label { text-transform: none; letter-spacing: 0;
            font-family: var(--font-sans); font-size: var(--text-sm); color: var(--text-muted); }
        button { width: 100%; }
        .alt { margin-top: var(--space-xl); font-size: var(--text-sm); color: var(--text-muted); }
    </style>
</head>
<body class="ctx-dark">
    <div class="room">
        <div class="perf-edge"></div>
        <main>
            <p class="eyebrow">Host account</p>
            <h1>Log in</h1>
            <form method="POST" action="/login">
                @csrf
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" required>
                </div>
                <div class="field remember">
                    <input id="remember" name="remember" type="checkbox" value="1">
                    <label for="remember">Keep me logged in</label>
                </div>
                <button class="btn--hero">Log in</button>
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </form>
            <p class="alt"><a href="/register">Need an account? Create one</a></p>
        </main>
        <div class="perf-edge"></div>
    </div>
</body>
</html>
