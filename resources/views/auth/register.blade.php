<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account — Quikbooth</title>
    @include('partials.theme')
    <style>
        .room { display: flex; min-height: 100dvh; }
        main { flex: 1; min-width: 0; max-width: 420px; margin: 0 auto;
            padding: var(--space-2xl) var(--space-lg); align-self: center; }
        h1 { margin: var(--space-md) 0 0; font-size: var(--display-md); }
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
            <h1>Create your account</h1>
            <form method="POST" action="/register">
                @csrf
                <div class="field">
                    <label for="name">Your name</label>
                    <input id="name" name="name" maxlength="100" value="{{ old('name') }}" required autofocus>
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required>
                </div>
                <div class="field">
                    <label for="password">Password (8+ characters)</label>
                    <input id="password" name="password" type="password" required>
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required>
                </div>
                <button class="btn--hero">Create account</button>
                @error('name') <p class="error">{{ $message }}</p> @enderror
                @error('email') <p class="error">{{ $message }}</p> @enderror
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </form>
            <p class="alt"><a href="/login">Already have an account? Log in</a></p>
            <p class="way-out"><a href="/">Got an event code? →</a></p>
        </main>
        <div class="perf-edge"></div>
    </div>
</body>
</html>
