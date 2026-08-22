<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->name }} — Event</title>
    @include('partials.theme')
    <style>
        body.ctx-light { display: grid; place-items: center; text-align: center; padding: var(--space-xl) var(--page-gutter); }
        main { width: min(100%, 480px); }
        .card { display: flex; flex-direction: column; align-items: center; gap: var(--space-sm); }
        .host-links { margin-top: var(--space-xl); font-size: var(--text-sm); display: flex; flex-wrap: wrap; gap: var(--space-md); justify-content: center; }
        .close-form { margin-top: var(--space-lg); }
        @media print {
            body { background: #fff; color: #000; padding: 0; }
            .no-print, .share { display: none; }
            .card { box-shadow: none; border: none; }
            .qr { padding: 0; }
            .card .code { color: #000; }
        }
    </style>
</head>
<body class="ctx-light">
    <main>
        <div class="card">
            <p class="eyebrow">Scan to join</p>
            <h1>{{ $event->name }}</h1>
            <div class="qr">{!! $qrSvg !!}</div>
            <p class="code">{{ $event->code }}</p>
            <p class="muted">or enter the code at <strong>{{ url('/') }}</strong></p>
        </div>

        <div class="share no-print">
            <button type="button" class="btn share-btn" data-share-url="{{ url('/e/'.$event->code) }}" data-share-title="{{ $event->name }}">Invite guests</button>
            <button type="button" class="btn--ghost share-copy" data-copy="{{ url('/e/'.$event->code) }}">Copy link</button>
            <span class="link-chip">{{ url('/e/'.$event->code) }}</span>
        </div>

        <div class="no-print">
            <p class="muted">{{ $photoCount }} {{ Str::plural('photo', $photoCount) }} so far</p>
            <div class="host-links">
                <a href="/e/{{ $event->code }}">Open the booth</a>
                <a href="/e/{{ $event->code }}/gallery">View the album</a>
                <a href="javascript:print()">Print this page</a>
            </div>
            <form class="close-form" method="POST" action="/events/{{ $event->code }}/toggle-closed">
                @csrf
                @if ($event->isClosed())
                    <p class="muted">The booth is closed — guests can view the album but not add photos.</p>
                    <button>Reopen the booth</button>
                @else
                    <button class="secondary">Close the booth</button>
                @endif
            </form>
        </div>
    </main>
    @include('partials.share-script')
</body>
</html>
