<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account — Photobooth</title>
    @include('partials.theme')
    <style>
        body.ctx-dark { display: grid; place-items: center; padding: var(--space-lg); }
        main { width: min(100%, 380px); min-width: 0; text-align: center; }
        form { display: flex; flex-direction: column; gap: var(--space-md); margin-top: var(--space-lg); text-align: left; }
        .field { display: flex; flex-direction: column; gap: var(--space-2xs); }
        .field label { font-size: var(--text-sm); color: var(--text-muted); }
        input { width: 100%; }
        .error { color: var(--danger); font-size: var(--text-sm); }
        .alt { margin-top: var(--space-lg); font-size: var(--text-sm); }
    </style>
</head>
<body class="ctx-dark">
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
                <label for="password">Password <span class="muted">(8+ characters)</span></label>
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
    </main>
</body>
</html>
