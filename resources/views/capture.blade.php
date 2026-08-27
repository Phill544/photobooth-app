<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- The event code is the only credential here, so an indexed link would
         publish the whole event. robots.txt says the same thing for crawlers
         that never fetch the page. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $event->name }} — Photobooth</title>
    @include('partials.theme')
    @unless ($event->isClosed())
        @vite('resources/js/capture.ts')
    @endunless
    <style>
        /* One screen at a time, each one the whole viewport — the booth is a
           kiosk, not a document. capture.ts toggles [hidden] on these sections. */
        .screen {
            position: fixed; inset: 0; overflow-y: auto; overscroll-behavior: contain;
            display: flex; flex-direction: column;
            padding: calc(var(--space-xl) + env(safe-area-inset-top)) var(--space-lg)
                     calc(var(--space-lg) + env(safe-area-inset-bottom));
        }
        /* `safe` matters: these screens scroll, and plain `center` pushes half of
           any overflow above the block-start edge where scrollTop can't reach it
           (a long event name on a short landscape phone). */
        .screen--center { justify-content: safe center; text-align: center; }
        .inner { width: min(100%, 440px); margin: 0 auto; display: flex; flex-direction: column; }
        .screen--center .inner { align-items: center; }
        .bottom { margin-top: auto; }
        h1 { font-size: var(--display-lg); margin: 0; }

        /* --- 02 · the booth --- */
        #start-screen {
            background:
                radial-gradient(120% 70% at 50% -10%, rgba(58,134,255,.22), transparent 60%),
                radial-gradient(90% 55% at 85% 12%, rgba(131,56,236,.20), transparent 65%),
                var(--ink);
        }
        #start-screen .inner { flex: 1; }
        .event-logo { max-width: 120px; max-height: 56px; object-fit: contain; margin-bottom: var(--space-md); }
        .promise { margin: var(--space-sm) 0 0; color: var(--text-muted); font-size: var(--text-base); }
        .cta { margin-top: var(--space-lg); display: flex; flex-direction: column; gap: var(--space-sm); }
        .cta .btn--hero, .cta button.btn--hero { width: 100%; }
        .rec-dot { width: 12px; height: 12px; border-radius: 50%; background: #fff; }
        .tally { margin: var(--space-lg) 0 0; text-align: center; }
        .invite { margin-top: var(--space-md); justify-content: center; }

        /* --- 03 · shooting --- */
        #camera-screen { padding: 0; background: var(--ink); display: grid; place-items: center; }
        /* The frame keeps the template's cell aspect, so what the guest sees
           framed is exactly what grabFrame crops into the strip. capture.ts
           sets --cell-aspect from the event's template. */
        .camera-frame { position: relative; width: min(100%, calc(100dvh * var(--cell-aspect, 1.3333))); }
        /* Mirror the live preview so guests frame like a mirror; the saved frame
           is grabbed un-mirrored (see captureShot) so text reads the right way. */
        #preview { display: block; width: 100%; height: auto; aspect-ratio: var(--cell-aspect, 1.3333);
            object-fit: cover; background: #000; transform: scaleX(-1); }
        #countdown-number {
            position: fixed; inset: 0; display: grid; place-items: center; pointer-events: none;
            font-family: var(--font-display); font-size: clamp(9rem, 52vw, 16rem); line-height: 1;
            color: #fff; text-shadow: 0 6px 60px rgba(0, 0, 0, .55);
        }
        #flash-overlay { position: absolute; inset: 0; background: #fff; opacity: 0; pointer-events: none; }
        #flash-overlay.flashing { opacity: 1; }

        .hud { position: fixed; left: 0; right: 0; display: flex; gap: var(--space-xs);
            padding: 0 var(--space-lg); pointer-events: none; }
        .hud--top { top: calc(var(--space-lg) + env(safe-area-inset-top)); justify-content: space-between; }
        .hud-chip {
            font-family: var(--font-mono); font-size: var(--text-2xs);
            letter-spacing: .18em; text-transform: uppercase; color: var(--ivory);
            background: rgba(11, 11, 16, .55); padding: .5rem .75rem; border-radius: var(--r-pill);
        }
        .hud-chip--filter { color: var(--yellow); }
        #shot-dots { position: fixed; left: 0; right: 0;
            bottom: calc(var(--space-xl) + env(safe-area-inset-bottom));
            display: flex; justify-content: center; gap: 10px; pointer-events: none; }
        #shot-dots span { width: 44px; height: 5px; border-radius: var(--r-pill); background: rgba(244, 242, 237, .28); }
        #shot-dots span.lit { background: var(--pink); }

        /* --- 04 · pick a look --- */
        #filter-controls {
            position: fixed; inset: auto 0 0 0; padding: 80px 0 calc(var(--space-lg) + env(safe-area-inset-bottom));
            background: linear-gradient(to top, var(--ink) 34%, rgba(11, 11, 16, 0));
        }
        #filter-rail {
            display: flex; gap: 10px; flex-wrap: nowrap;
            padding: 0 var(--space-lg) var(--space-md);
            overflow-x: auto; -webkit-overflow-scrolling: touch; justify-content: safe center;
            scrollbar-width: none; /* the peeking last look is the scroll cue */
        }
        #filter-rail::-webkit-scrollbar { display: none; }
        .look {
            flex: 0 0 auto; display: flex; flex-direction: column; align-items: center; gap: 6px;
            min-height: 0; padding: 0; margin: 0; background: none; border: 0; box-shadow: none;
            font-family: var(--font-mono); font-size: var(--text-2xs); letter-spacing: .04em;
            color: var(--text-muted); cursor: pointer;
        }
        .look:hover { transform: none; }
        .look .swatch {
            width: 64px; height: 80px; border-radius: 10px; border: 2px solid transparent;
            overflow: hidden; background: #1C1C24;
        }
        /* The filter lives on the inner shot so the selected border keeps its colour. */
        .look .shot { display: block; width: 100%; height: 100%;
            background: #1C1C24 center / cover no-repeat; }
        .look.selected { color: var(--yellow); }
        .look.selected .swatch { border-color: var(--yellow); }
        #customise-start { margin: 0 var(--space-lg); width: calc(100% - 2 * var(--space-lg));
            background: var(--yellow); color: #20180A; box-shadow: 0 14px 40px rgba(255, 190, 11, .35); }

        /* --- 05 · your strip --- */
        #review-screen .inner { flex: 1; align-items: center; }
        #review-screen .strip-mat { width: min(58%, 210px); margin-top: var(--space-lg); }
        .consent-note { text-align: center; margin: 0 0 var(--space-sm); }
        #review-screen .bottom { width: 100%; display: flex; flex-direction: column; gap: var(--space-sm); }
        #share { width: 100%; }
        /* The strip is still encoding: capture.ts clears this once there's a blob. */
        [aria-disabled="true"] { opacity: .45; pointer-events: none; }

        /* --- 06 · shared --- */
        #done-screen {
            background: var(--purple); color: #fff;
            --text: #FFFFFF; --text-muted: rgba(255, 255, 255, .72); --text-faint: rgba(255, 255, 255, .72);
            --line: rgba(255, 255, 255, .28); --line-strong: rgba(255, 255, 255, .45);
            --mat: #F4F2ED; --mat-hole: #DAD7CF;
            --btn-bg: #FFFFFF; --btn-text: #4B0F91; --btn-glow: none;
            --ok: #FFFFFF; /* the accent blue is 1.6:1 on this purple */
        }
        #done-screen h1 { font-size: var(--display-xl); margin: var(--space-md) 0 0; }
        #done-screen h1 em { font-style: italic; }
        /* Tilted the other way from the review screen — one rule owns every tilt. */
        #done-screen .strip-mat { width: min(42%, 156px); margin: var(--space-xl) auto 0; --strip-tilt: 3deg; }
        #done-screen .bottom { display: flex; flex-direction: column; gap: var(--space-sm); }
        #save-strip { width: 100%; }
        #save-fallback img { max-width: 60%; border-radius: var(--r-sm); }
        #done-screen .invite { justify-content: center; }
        #done-screen .link-chip { background: rgba(255, 255, 255, .14); border-color: transparent; color: rgba(255, 255, 255, .82); }

        /* --- uploading / recovery screens --- */
        #uploading-screen .inner, #upload-failed-screen .inner,
        #camera-lost-screen .inner, #denied-screen .inner,
        #in-app-screen .inner, #error-screen .inner { gap: var(--space-md); }
        #upload-progress { font-family: var(--font-mono); font-size: var(--text-base);
            letter-spacing: .08em; color: var(--text-muted); margin: 0; }
        .settings-steps { text-align: left; color: var(--text-muted); font-size: var(--text-sm); }

        /* Covers everything while the phone is sideways. */
        #rotate-overlay { position: fixed; inset: 0; z-index: 50; background: var(--bg);
            display: grid; place-items: center; padding: var(--space-lg); text-align: center; }
        #rotate-overlay svg { display: block; margin: 0 auto var(--space-md); color: var(--text-faint); }
    </style>
</head>
<body class="ctx-dark" data-event-code="{{ $event->code }}" data-event-name="{{ $event->name }}" data-template="{{ $event->template }}" data-theme="{{ $event->theme }}" data-caption="{{ $event->caption }}" data-logo="{{ $event->logo_path ? url($event->logoUrl()) : '' }}">
    @if ($event->isClosed())
    <main class="screen screen--center">
        <div class="inner">
            <p class="eyebrow">The booth</p>
            <h1>{{ $event->name }}</h1>
            <p class="muted">This booth is closed — the album is still open.</p>
            <a class="btn btn--ghost" href="/e/{{ $event->code }}/gallery">See the album</a>
        </div>
    </main>
    @else
    <main>
        <section id="start-screen" class="screen">
            <div class="inner">
                <div class="bottom">
                    @if ($event->logo_path)
                        <img class="event-logo" src="{{ $event->logoUrl() }}" alt="">
                    @endif
                    <p class="eyebrow">Tonight</p>
                    <h1>{{ $event->name }}</h1>
                    {{-- capture.ts fills this in: the shot count comes from the event's template. --}}
                    <p class="promise" id="promise"></p>

                    <div class="cta">
                        <button id="start" class="btn--hero"><span class="rec-dot"></span>Start shooting</button>
                        <div class="btn-row">
                            <button id="add-filter" class="btn--ghost">Pick a look</button>
                            <a class="btn btn--ghost" href="/e/{{ $event->code }}/gallery">The album</a>
                        </div>
                    </div>

                    {{-- An upload this device had already started; capture.ts
                         fills this in while it finishes off in the background. --}}
                    <p class="mono mono--plain tally" id="resume-notice" hidden></p>

                    {{-- The strip uploads first, so a partial session can have a strip and no shots. --}}
                    @if ($stripCount > 0 || $photoCount > 0)
                        <p class="mono mono--plain tally">{{ $stripCount }} {{ Str::plural('strip', $stripCount) }} shot · {{ $photoCount }} {{ Str::plural('photo', $photoCount) }}</p>
                    @endif

                    <div class="share invite">
                        <button type="button" class="btn--ghost btn--small share-btn" data-share-url="{{ url('/e/'.$event->code) }}" data-share-title="{{ $event->name }}">Invite others</button>
                        <button type="button" class="btn--ghost btn--small share-copy" data-copy="{{ url('/e/'.$event->code) }}">Copy link</button>
                        <span class="link-chip">{{ url('/e/'.$event->code) }}</span>
                    </div>
                </div>
            </div>
        </section>

        <section id="camera-screen" class="screen" hidden>
            <div class="camera-frame">
                <video id="preview" playsinline autoplay muted></video>
                <div id="flash-overlay"></div>
            </div>
            <div id="countdown-number"></div>
            <div class="hud hud--top">
                <span id="shot-label" class="hud-chip"></span>
                <span id="filter-badge" class="hud-chip hud-chip--filter" hidden></span>
            </div>
            <div id="shot-dots"></div>
            <div id="filter-controls" hidden>
                <div id="filter-rail"></div>
                <button id="customise-start" class="btn--hero">Start shooting</button>
            </div>
        </section>

        <section id="review-screen" class="screen" hidden>
            <div class="inner">
                <p class="eyebrow" style="text-align:center">Fresh out of the booth</p>
                <div class="strip-mat strip-mat--tilt">
                    <img id="strip-preview" alt="Your photo strip">
                </div>
                <div class="bottom">
                    <p class="consent-note">Sharing puts your strip in the event album — anyone with the link can see it.</p>
                    {{-- Shown when the strip can't be encoded for sending (a phone
                         low on memory). Staying on this screen matters: it holds
                         the only copy and the only Save link. --}}
                    <p class="error" id="share-error" hidden>That didn't send — save your strip to your phone, then try again.</p>
                    <button id="share" class="btn--hero">Share to the album</button>
                    <div class="btn-row">
                        <a id="save-review" class="btn btn--ghost" download aria-disabled="true">Save to phone</a>
                        <button id="retake" class="secondary">Retake</button>
                    </div>
                </div>
            </div>
        </section>

        <section id="uploading-screen" class="screen screen--center" hidden>
            <div class="inner">
                <p class="eyebrow">Sending it up</p>
                <p id="upload-progress">Uploading…</p>
                {{-- The queue holds for a signal rather than spending its
                     retries; capture.ts shows this while the phone is offline so
                     a held upload doesn't read as a stuck one. --}}
                <p id="offline-hint" class="muted" hidden>No signal right now — this carries on by itself when it's back.</p>
            </div>
        </section>

        <section id="done-screen" class="screen" hidden>
            <div class="inner" style="flex:1">
                <p class="eyebrow">In the album</p>
                <h1>That's a<br><em>keeper.</em></h1>
                <div class="strip-mat strip-mat--tilt">
                    <img id="save-image" alt="Your photo strip">
                </div>
                <div class="bottom">
                    <button id="save-strip" class="btn--hero" hidden>Save my strip</button>
                    <div id="save-fallback" hidden>
                        <p class="muted">Long-press the strip above to save it, or:</p>
                        <a id="save-download" class="btn btn--light" download>Download my strip</a>
                    </div>
                    <div class="btn-row">
                        <button id="again" class="secondary">Take another</button>
                        <a class="btn btn--ghost" href="/e/{{ $event->code }}/gallery">See the album</a>
                    </div>
                    <div class="share invite">
                        <button type="button" class="btn--ghost btn--small share-btn" data-share-url="{{ url('/e/'.$event->code) }}" data-share-title="{{ $event->name }}">Invite others</button>
                        <button type="button" class="btn--ghost btn--small share-copy" data-copy="{{ url('/e/'.$event->code) }}">Copy link</button>
                        <span class="link-chip">{{ url('/e/'.$event->code) }}</span>
                    </div>
                </div>
            </div>
        </section>

        <section id="camera-lost-screen" class="screen screen--center" hidden>
            <div class="inner">
                <p class="eyebrow">Hold on</p>
                <h1>The camera stopped</h1>
                <button id="camera-retry">Turn it back on</button>
            </div>
        </section>

        {{-- One screen for every way an upload can fail; capture.ts writes the
             copy from the reason the server gave and hides Retry when a retry
             could never work. The strip stays saveable either way — the guest
             took those photos, and a closed booth shouldn't cost them. --}}
        <section id="upload-failed-screen" class="screen screen--center" hidden>
            <div class="inner">
                <p class="eyebrow" id="upload-failed-eyebrow"></p>
                <h1 id="upload-failed-title"></h1>
                <p class="muted" id="upload-failed-detail"></p>
                <button id="upload-retry">Retry upload</button>
                <a id="save-failed" class="btn btn--ghost" download aria-disabled="true">Save to phone</a>
            </div>
        </section>

        <section id="denied-screen" class="screen screen--center" hidden>
            <div class="inner">
                <p class="eyebrow">Permission</p>
                <h1>Camera access is off</h1>
                <div class="settings-steps">
                    <p id="denied-ios" hidden>Tap the <strong>aA</strong> (or ••• ) button by the address bar → <strong>Website Settings</strong> → set <strong>Camera</strong> to Allow, then tap below.</p>
                    <p id="denied-android" hidden>Tap the icon left of the address bar → <strong>Site settings</strong> → <strong>Camera</strong> → Allow, then tap below.</p>
                </div>
                <button id="denied-retry">I've enabled it — try again</button>
            </div>
        </section>

        <section id="in-app-screen" class="screen screen--center" hidden>
            <div class="inner">
                <p class="eyebrow">One step first</p>
                <h1>Open in your browser</h1>
                <p class="muted">The camera doesn't work inside this app's browser.</p>
                <a id="open-chrome" class="btn" hidden>Open in Chrome</a>
                <p id="open-safari" class="muted" hidden>Tap the ••• or share menu, then <strong>Open in Safari</strong> — or copy the link:</p>
                <div class="share invite">
                    <button type="button" class="btn--ghost btn--small share-copy" data-copy="{{ url('/e/'.$event->code) }}">Copy link</button>
                    <span class="link-chip">{{ url('/e/'.$event->code) }}</span>
                </div>
                <p><a id="continue-anyway" href="#">Continue anyway</a></p>
            </div>
        </section>

        <section id="error-screen" class="screen screen--center" hidden>
            <div class="inner">
                <p class="eyebrow">Sorry</p>
                <h1>Something broke</h1>
                <p id="error-message" class="muted"></p>
                <button onclick="location.reload()" class="secondary">Reload</button>
            </div>
        </section>
    </main>

    <div id="rotate-overlay" hidden>
        <div>
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <rect x="7" y="2" width="10" height="20" rx="2.5"></rect>
                <path d="M10.5 19h3"></path>
            </svg>
            <p>Turn your phone upright</p>
        </div>
    </div>
    @include('partials.share-script')
    @endif
</body>
</html>
