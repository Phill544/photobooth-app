<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your events — Photobooth</title>
    @include('partials.theme')
    <style>
        body.ctx-light { padding: var(--space-xl) var(--page-gutter) var(--space-3xl); }
        .wrap { max-width: 640px; margin: 0 auto; }
        .top { display: flex; justify-content: space-between; align-items: baseline; gap: var(--space-md); margin-bottom: var(--space-lg); }
        .logout { border: none; background: none; color: var(--text-muted); font-size: var(--text-sm); cursor: pointer; padding: 0; margin: 0; }
        .logout:hover { color: var(--danger); box-shadow: none; transform: none; }
        .events { list-style: none; padding: 0; margin: var(--space-lg) 0 0; display: flex; flex-direction: column; gap: var(--space-sm); }
        .events li a { display: flex; justify-content: space-between; gap: var(--space-md); text-decoration: none; color: var(--text);
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md); padding: var(--space-md) var(--space-lg); }
        .events li a:hover { border-color: var(--line-strong); }
        .events .meta { color: var(--text-muted); font-size: var(--text-sm); }
        .empty { color: var(--text-muted); margin-top: var(--space-xl); }
    </style>
</head>
<body class="ctx-light">
    <div class="wrap">
        <div class="top">
            <div>
                <p class="eyebrow">{{ $isAdmin ? 'All events (admin)' : 'Your events' }}</p>
                <h1>Hi, {{ auth()->user()->name }}</h1>
            </div>
            <form method="POST" action="/logout">
                @csrf
                <button class="logout">Log out</button>
            </form>
        </div>

        <a href="/new" class="btn--hero">＋ New event</a>

        @if ($events->isEmpty())
            <p class="empty">No events yet — create your first booth.</p>
        @else
            <ul class="events">
                @foreach ($events as $event)
                    <li>
                        <a href="/events/{{ $event->code }}">
                            <span>
                                <strong>{{ $event->name }}</strong>
                                @if ($isAdmin && $event->owner) <span class="meta">· {{ $event->owner->name }}</span> @endif
                                @if ($event->isClosed()) <span class="meta">· closed</span> @endif
                            </span>
                            <span class="meta">{{ $event->code }} · {{ $event->photos_count }} {{ Str::plural('photo', $event->photos_count) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</body>
</html>
