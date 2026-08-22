<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->name }} — Album</title>
    @include('partials.theme')
    <style>
        body.ctx-light { padding: var(--space-xl) var(--page-gutter) var(--space-3xl); }

        .album-head { max-width: var(--measure); margin: 0 auto var(--space-2xl);
            padding-bottom: var(--space-lg); border-bottom: 1px solid var(--line); text-align: center; }
        .album-head h1 { font-size: var(--text-4xl); }
        .album-head .count { margin-top: var(--space-xs); }

        .feed { max-width: var(--measure); margin: 0 auto; display: flex; flex-direction: column; gap: var(--space-2xl); }

        .session { display: grid; grid-template-columns: 1fr; gap: var(--space-lg); justify-items: center; }
        .session > .strip { width: min(64%, 220px); height: auto; border-radius: var(--r-sm);
            box-shadow: var(--shadow-lg); background: #111; rotate: var(--strip-tilt); }
        .session > .originals { width: 100%; display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--space-xs); }
        .session > .originals img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: var(--r-sm); background: var(--bg); }
        .session > .delete { justify-self: end; }

        @media (min-width: 720px) {
            .session { grid-template-columns: minmax(190px, 230px) 1fr;
                grid-template-areas: "strip originals" "strip delete";
                align-items: start; justify-items: stretch; column-gap: var(--space-xl); row-gap: var(--space-md); }
            .session > .strip { grid-area: strip; width: 100%; max-width: 230px; align-self: start; }
            .session > .originals { grid-area: originals; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: var(--space-sm); align-content: start; }
            .session > .delete { grid-area: delete; justify-self: end; }
        }
        @media (min-width: 1024px) { .session { column-gap: var(--space-2xl); } }

        .feed .empty { text-align: center; color: var(--text-muted); font-family: var(--font-display);
            font-size: var(--text-xl); padding: var(--space-3xl) 0; }
        .back { display: block; max-width: var(--measure); margin: 0 auto var(--space-lg);
            font-size: var(--text-sm); }
    </style>
</head>
<body class="ctx-light">
    <a class="back" href="/e/{{ $event->code }}">← Back to the booth</a>
    <header class="album-head">
        <p class="eyebrow">Event album</p>
        <h1>{{ $event->name }}</h1>
        <p class="count muted">{{ $sessions->count() }} {{ Str::plural('session', $sessions->count()) }}</p>
        <div class="share">
            <button type="button" class="btn share-btn" data-share-url="{{ url('/e/'.$event->code) }}" data-share-title="{{ $event->name }}">Invite others</button>
            <button type="button" class="btn--ghost share-copy" data-copy="{{ url('/e/'.$event->code) }}">Copy link</button>
            <span class="link-chip">{{ url('/e/'.$event->code) }}</span>
        </div>
    </header>

    <main class="feed">
        @forelse ($sessions as $session)
            <section class="session card">
                @foreach ($session->where('kind', 'strip') as $photo)
                    <img class="strip" src="/e/{{ $event->code }}/photos/{{ $photo->id }}" alt="Photo strip" loading="lazy">
                @endforeach
                <div class="originals">
                    @foreach ($session->where('kind', 'original') as $photo)
                        <img src="/e/{{ $event->code }}/photos/{{ $photo->id }}" alt="Event photo" loading="lazy">
                    @endforeach
                </div>
                <form class="delete" method="POST" action="/e/{{ $event->code }}/groups/{{ $session->first()->group_uuid }}"
                      onsubmit="return confirm('Delete this session and its photos?')">
                    @csrf
                    @method('DELETE')
                    <button>Delete session</button>
                </form>
            </section>
        @empty
            <p class="empty">No photos yet — be the first!</p>
        @endforelse
    </main>
    @include('partials.share-script')
</body>
</html>
