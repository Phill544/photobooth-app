<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->name }} — Event</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            margin: 0;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            background: #fff;
            color: #111;
            text-align: center;
        }
        .code { font-size: 3rem; font-weight: 700; letter-spacing: 0.3ch; margin: 0.5rem 0; }
        svg { max-width: 280px; }
        a { color: #06c; }
        .no-print a { margin: 0 0.5rem; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <main>
        <h1>{{ $event->name }}</h1>
        <p>Scan to join the photobooth</p>
        {!! $qrSvg !!}
        <p class="code">{{ $event->code }}</p>
        <p>or enter the code at <strong>{{ url('/') }}</strong></p>

        <div class="no-print">
            <p>{{ $photoCount }} {{ Str::plural('photo', $photoCount) }} so far</p>
            <p>
                <a href="/e/{{ $event->code }}">Open the booth</a> ·
                <a href="/e/{{ $event->code }}/gallery">View the album</a> ·
                <a href="javascript:print()">Print this page</a>
            </p>
            <form method="POST" action="/events/{{ $event->code }}/toggle-closed">
                @csrf
                @if ($event->isClosed())
                    <p>The booth is closed — guests can view the album but not add photos.</p>
                    <button>Reopen the booth</button>
                @else
                    <button>Close the booth</button>
                @endif
            </form>
        </div>
    </main>
</body>
</html>
