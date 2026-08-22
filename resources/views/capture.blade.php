<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $event->name }} — Photobooth</title>
    @unless ($event->isClosed())
        @vite('resources/js/capture.ts')
    @endunless
    <style>
        body {
            font-family: system-ui, sans-serif;
            margin: 0;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            background: #111;
            color: #fff;
            text-align: center;
        }
        main { width: min(100%, 480px); padding: 1rem; }
        button {
            font-size: 1.25rem;
            padding: 0.75rem 2rem;
            border-radius: 999px;
            border: none;
            background: #fff;
            color: #111;
            margin-top: 1rem;
        }
        button.secondary { background: #333; color: #fff; }
        a { color: #9cf; }

        .camera-frame { position: relative; }
        video {
            width: 100%;
            border-radius: 8px;
            object-fit: cover;
            transform: scaleX(-1); /* selfie preview reads like a mirror */
        }
        #countdown-number {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            font-size: 8rem;
            font-weight: 700;
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.6);
        }
        #flash-overlay {
            position: absolute;
            inset: 0;
            background: #fff;
            border-radius: 8px;
            opacity: 0;
            pointer-events: none;
        }
        #flash-overlay.flashing { opacity: 1; }

        #strip-preview {
            max-width: 60%;
            max-height: 55dvh;
            border-radius: 4px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.5);
        }
        .consent-note { color: #aaa; font-size: 0.9rem; }
    </style>
</head>
<body data-event-code="{{ $event->code }}" data-event-name="{{ $event->name }}">
    @if ($event->isClosed())
    <main>
        <h1>{{ $event->name }}</h1>
        <p>This event's photobooth has closed. 📷✨</p>
        <p><a href="/e/{{ $event->code }}/gallery">See the album</a></p>
    </main>
    @else
    <main>
        <section id="start-screen">
            <h1>{{ $event->name }}</h1>
            <button id="start">📸 Start the booth</button>
        </section>

        <section id="camera-screen" hidden>
            <p id="shot-label"></p>
            <div class="camera-frame">
                <video id="preview" playsinline autoplay muted></video>
                <div id="countdown-number"></div>
                <div id="flash-overlay"></div>
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
            <p>Shared to the event album! 🎉</p>
            <p><a href="/e/{{ $event->code }}/gallery">See the album</a></p>
            <button id="again">Take another</button>
        </section>

        <section id="camera-lost-screen" hidden>
            <p>The camera stopped 😢</p>
            <button id="camera-retry">Turn it back on</button>
        </section>

        <section id="error-screen" hidden>
            <p>Something broke:</p>
            <p id="error-message"></p>
            <button onclick="location.reload()" class="secondary">Reload</button>
        </section>
    </main>
    @endif
</body>
</html>
