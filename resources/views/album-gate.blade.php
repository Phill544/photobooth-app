{{-- The album's front door. Three states of one screen: a PIN the guest can
     type, a wall the host put up, or a night that has now passed. All three are
     the booth's dark room rather than the album's paper, because the guest is
     not in the album yet. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $event->name }} — Album</title>
    @include('partials.theme')
    <style>
        .room { display: flex; min-height: 100dvh; }
        main { flex: 1; min-width: 0; display: flex; flex-direction: column;
            padding: var(--space-xl) var(--space-lg) var(--space-lg);
            max-width: 460px; margin: 0 auto; }
        h1 { font-size: var(--display-md); margin: var(--space-lg) 0 0; }
        .lede { margin: var(--space-md) 0 0; color: var(--text-muted); font-size: var(--text-base); }
        form { margin-top: var(--space-xl); display: flex; flex-direction: column; gap: var(--space-sm); }
        form button { width: 100%; margin-top: var(--space-md); }
        #pin { font-family: var(--font-mono); letter-spacing: .12em; }

        .back {
            margin-top: auto; padding-top: var(--space-xl); border-top: 1px solid var(--line);
            display: flex; flex-wrap: wrap; gap: var(--space-sm);
            justify-content: space-between; align-items: center;
        }
        .back p { margin: 0; color: var(--text-faint); font-size: var(--text-base); }
        .back a { color: var(--text); font-weight: 500; text-decoration: none; }
        .back a:hover { text-decoration-line: underline; }
    </style>
</head>
<body class="ctx-dark">
    <div class="room">
        <div class="perf-edge"></div>
        <main>
            <p class="eyebrow">{{ $event->name }}</p>
            @if ($state === 'expired')
                <h1>This album is no longer available.</h1>
                <p class="lede">The night is over and the host's window for keeping these photos
                    has passed. Anything you saved to your phone is still yours.</p>
            @elseif ($state === 'hidden')
                <h1>This album is private.</h1>
                <p class="lede">The host is keeping this one to themselves. You can still take
                    photos in the booth — and every strip you shoot is yours to save.</p>
            @else
                <h1>This album has a PIN.</h1>
                <p class="lede">The host set a word or number for guests to type. Ask them, or
                    look for it wherever the event code is.</p>

                <form method="POST" action="{{ $unlockUrl }}">
                    @csrf
                    <div class="field">
                        <label for="pin">Album PIN</label>
                        {{-- maxlength truncates typing AND paste, so this has to be
                         the same number the host's field and the validator use,
                         or it is a PIN the host can set and no guest can enter. --}}
                    <input id="pin" name="pin" maxlength="{{ $pinMaxLength }}" required autofocus
                               autocomplete="off" autocapitalize="off" spellcheck="false"
                               aria-describedby="pin-error">
                    </div>
                    @error('pin') <p class="error" id="pin-error" role="alert">{{ $message }}</p> @enderror
                    <button class="btn--hero">Open the album</button>
                </form>
            @endif

            @unless ($state === 'expired')
                <div class="back">
                    <p>Still shooting?</p>
                    <a href="/e/{{ $event->code }}">Back to the booth →</a>
                </div>
            @endunless
        </main>
        <div class="perf-edge"></div>
    </div>
</body>
</html>
