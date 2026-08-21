<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $event->name }} — Photobooth</title>
    @vite('resources/js/capture.ts')
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
        video {
            width: 100%;
            border-radius: 8px;
            transform: scaleX(-1); /* selfie preview reads like a mirror */
        }
        button {
            font-size: 1.25rem;
            padding: 0.75rem 2rem;
            border-radius: 999px;
            border: none;
            background: #fff;
            color: #111;
            margin-top: 1rem;
        }
        a { color: #9cf; }
    </style>
</head>
<body data-event-code="{{ $event->code }}">
    <main>
        <h1>{{ $event->name }}</h1>

        <section id="start-screen">
            <button id="start">📸 Start the booth</button>
        </section>

        <section id="camera-screen" hidden>
            <video id="preview" playsinline autoplay muted></video>
            <br>
            <button id="shutter">Take photo</button>
        </section>

        <section id="uploading-screen" hidden>
            <p>Uploading…</p>
        </section>

        <section id="done-screen" hidden>
            <p>Shared to the event album! 🎉</p>
            <p><a href="/e/{{ $event->code }}/gallery">See the album</a></p>
            <button id="again">Take another</button>
        </section>
    </main>
</body>
</html>
