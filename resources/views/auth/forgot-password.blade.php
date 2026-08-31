<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your password — Photobooth</title>
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
        /* The one confirmation this page can give, and it deliberately says the
           same thing whether or not the address has an account. */
        .status { margin: var(--space-lg) 0 0; color: var(--ok); font-size: var(--text-sm); }
    </style>
</head>
<body class="ctx-dark">
    <div class="room">
        <div class="perf-edge"></div>
        <main>
            <p class="eyebrow">Host account</p>
            @if ($mailerIsFake)
                {{-- No form at all: a field here would take an address and
                     promise it an email that this deployment cannot post. --}}
                <h1>Password reset isn't set up.</h1>
                <p class="lede">This deployment has no mail service attached, so there is no way to
                    send you a link. Ask whoever runs it to set one — until then a password can only
                    be changed from the server.</p>
            @else
                <h1>Forgotten it?</h1>
                <p class="lede">Give us the address you signed up with and we'll send a link to set
                    a new password. The link lasts an hour and works once.</p>

                <form method="POST" action="/forgot-password">
                    @csrf
                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
                    </div>
                    <button class="btn--hero">Send me a link</button>
                    @error('email') <p class="error">{{ $message }}</p> @enderror
                </form>

                @if (session('status'))
                    <p class="status" role="status">{{ session('status') }}</p>
                @endif
            @endif

            <p class="alt"><a href="/login">Back to log in</a></p>
        </main>
        <div class="perf-edge"></div>
    </div>
</body>
</html>
