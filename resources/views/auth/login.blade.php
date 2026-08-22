<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in — Photobooth</title>
    @include('partials.theme')
    <style>
        body.ctx-dark { display: grid; place-items: center; padding: var(--space-lg); }
        main { width: min(100%, 380px); min-width: 0; text-align: center; }
        form { display: flex; flex-direction: column; gap: var(--space-md); margin-top: var(--space-lg); text-align: left; }
        .field { display: flex; flex-direction: column; gap: var(--space-2xs); }
        .field label { font-size: var(--text-sm); color: var(--text-muted); }
        input { width: 100%; }
        .remember { flex-direction: row; align-items: center; gap: var(--space-xs); }
        .remember input { width: auto; }
        .error { color: var(--danger); font-size: var(--text-sm); }
        .alt { margin-top: var(--space-lg); font-size: var(--text-sm); }
    </style>
</head>
<body class="ctx-dark">
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
</body>
</html>
