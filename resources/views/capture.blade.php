<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $event->name }} — Photobooth</title>
    @include('partials.theme')
    @unless ($event->isClosed())
        @vite('resources/js/capture.ts')
    @endunless
    <style>
        body.ctx-dark { display: grid; place-items: center; text-align: center; padding: var(--space-lg); }
        main { width: min(100%, 480px); min-width: 0; max-width: 100%; }
        .eyebrow { margin-bottom: var(--space-2xs); }

        .camera-frame { position: relative; margin-top: var(--space-md); }
        /* Mirror the live preview so guests frame like a mirror; the saved frame
           is grabbed un-mirrored (see captureShot) so text reads the right way. */
        #preview { width: 100%; border-radius: var(--r-md); object-fit: cover; background: #000; transform: scaleX(-1); }
        #countdown-number { position: absolute; inset: 0; display: grid; place-items: center;
            font-family: var(--font-display); font-size: 8rem; font-weight: 600; color: #fff;
            text-shadow: 0 2px 12px rgba(0, 0, 0, .6); }
        #flash-overlay { position: absolute; inset: 0; background: #fff; border-radius: var(--r-md); opacity: 0; pointer-events: none; }
        #flash-overlay.flashing { opacity: 1; }

        #strip-preview { max-width: 62%; max-height: 55dvh; border-radius: var(--r-sm);
            box-shadow: var(--shadow-lg); rotate: var(--strip-tilt); }

        .chips { display: flex; gap: var(--space-xs); overflow-x: auto; padding: var(--space-sm) var(--space-2xs);
            justify-content: safe center; -webkit-overflow-scrolling: touch; }
        .chip {
            flex: 0 0 auto; min-height: 40px; margin: 0; padding: .4rem 1rem;
            font-size: var(--text-sm); border-radius: var(--r-pill);
            background: transparent; color: var(--btn-ghost-text); border: 1px solid var(--btn-ghost-border);
        }
        .chip.selected { background: var(--accent); color: var(--accent-ink); border-color: var(--accent); }
        .settings-steps { text-align: left; color: var(--text-muted); font-size: var(--text-sm);
            margin: var(--space-md) auto; max-width: 320px; }
        .settings-steps li { margin-bottom: var(--space-2xs); }

        /* Rotate overlay: covers everything while the phone is sideways. */
        #rotate-overlay { position: fixed; inset: 0; z-index: 50; background: var(--bg);
            display: grid; place-items: center; padding: var(--space-lg); }
        #rotate-overlay .rot { font-size: 3rem; }
    </style>
</head>
<body class="ctx-dark" data-event-code="{{ $event->code }}" data-event-name="{{ $event->name }}" data-template="{{ $event->template }}" data-theme="{{ $event->theme }}" data-caption="{{ $event->caption }}" data-logo="{{ $event->logo_path ? url('/e/'.$event->code.'/logo') : '' }}">
    @if ($event->isClosed())
    <main>
        <h1>{{ $event->name }}</h1>
        <p class="muted">This event's photobooth has closed. 📷✨</p>
        <p><a href="/e/{{ $event->code }}/gallery">See the album</a></p>
    </main>
    @else
    <main>
        <section id="start-screen">
            <p class="eyebrow">Photobooth</p>
            <h1>{{ $event->name }}</h1>
            <button id="start" class="btn--hero">📸 Quick shoot</button>
            <br>
            <button id="add-filter" class="btn--ghost">🎨 Add a filter</button>
            <p><a href="/e/{{ $event->code }}/gallery">View the album</a></p>
            <div class="share">
                <button type="button" class="btn--ghost share-btn" data-share-url="{{ url('/e/'.$event->code) }}" data-share-title="{{ $event->name }}">Invite others</button>
                <button type="button" class="btn--ghost share-copy" data-copy="{{ url('/e/'.$event->code) }}">Copy link</button>
                <span class="link-chip">{{ url('/e/'.$event->code) }}</span>
            </div>
        </section>

        <section id="camera-screen" hidden>
            <p id="shot-label" class="eyebrow"></p>
            <div class="camera-frame">
                <video id="preview" playsinline autoplay muted></video>
                <div id="countdown-number"></div>
                <div id="flash-overlay"></div>
            </div>
            <div id="filter-controls" hidden>
                <div id="filter-rail" class="chips"></div>
                <button id="customise-start" class="btn--hero">Start with this look</button>
            </div>
        </section>

        <section id="review-screen" hidden>
            <img id="strip-preview" alt="Your photo strip">
            <p class="consent-note">Sharing adds your strip and photos to the event album,<br>visible to everyone with the event link.</p>
            <button id="share">Share to the album</button>
            <br>
            <button id="retake" class="secondary">Retake</button>
        </section>

        <section id="uploading-screen" hidden>
            <p id="upload-progress">Uploading…</p>
        </section>

        <section id="done-screen" hidden>
            <h1 class="celebrate-title">Shared! 🎉</h1>
            <p class="muted">Your strip is in the event album.</p>
            <button id="save-strip" hidden>Save / share my strip</button>
            <div id="save-fallback" hidden>
                <p class="muted">Long-press the photo to save it</p>
                <img id="save-image" alt="Your photo strip" style="max-width:60%;border-radius:var(--r-sm)">
                <br>
                <a id="save-download" class="btn--ghost">Download strip</a>
            </div>
            <p><a href="/e/{{ $event->code }}/gallery">See the album</a></p>
            <div class="share">
                <button type="button" class="btn--ghost share-btn" data-share-url="{{ url('/e/'.$event->code) }}" data-share-title="{{ $event->name }}">Invite others</button>
                <button type="button" class="btn--ghost share-copy" data-copy="{{ url('/e/'.$event->code) }}">Copy link</button>
                <span class="link-chip">{{ url('/e/'.$event->code) }}</span>
            </div>
            <br>
            <button id="again" class="secondary">Take another</button>
        </section>

        <section id="camera-lost-screen" hidden>
            <h1>The camera stopped 😢</h1>
            <button id="camera-retry">Turn it back on</button>
        </section>

        <section id="upload-failed-screen" hidden>
            <h1>Upload didn't finish</h1>
            <p class="muted">Some photos didn't make it up — check your signal and try again.</p>
            <button id="upload-retry">Retry upload</button>
        </section>

        <section id="denied-screen" hidden>
            <h1>Camera access is off</h1>
            <div class="settings-steps">
                <p id="denied-ios" hidden>Tap the <strong>aA</strong> (or ••• ) button by the address bar → <strong>Website Settings</strong> → set <strong>Camera</strong> to Allow, then tap below.</p>
                <p id="denied-android" hidden>Tap the icon left of the address bar → <strong>Site settings</strong> → <strong>Camera</strong> → Allow, then tap below.</p>
            </div>
            <button id="denied-retry">I've enabled it — try again</button>
        </section>

        <section id="in-app-screen" hidden>
            <h1>Open in your browser</h1>
            <p class="muted">The camera doesn't work inside this app's browser.</p>
            <a id="open-chrome" class="btn" hidden>Open in Chrome</a>
            <p id="open-safari" class="muted" hidden>Tap the ••• or share menu, then <strong>Open in Safari</strong> — or copy the link:</p>
            <div class="share">
                <button type="button" class="btn--ghost share-copy" data-copy="{{ url('/e/'.$event->code) }}">Copy link</button>
                <span class="link-chip">{{ url('/e/'.$event->code) }}</span>
            </div>
            <p><a id="continue-anyway" href="#">Continue anyway</a></p>
        </section>

        <section id="error-screen" hidden>
            <h1>Something broke</h1>
            <p id="error-message" class="muted"></p>
            <button onclick="location.reload()" class="secondary">Reload</button>
        </section>
    </main>

    <div id="rotate-overlay" hidden>
        <div>
            <div class="rot">📱</div>
            <p>Turn your phone upright</p>
        </div>
    </div>
    @include('partials.share-script')
    @endif
</body>
</html>
