<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->name }} — Album</title>
    @include('partials.theme')
    <style>
        .topbar { flex-wrap: wrap; }
        .topbar .code { font-size: var(--text-xs); color: var(--text-muted); }

        .album-head {
            max-width: var(--measure); margin: 0 auto;
            padding: var(--space-2xl) var(--page-gutter) var(--space-lg);
            display: flex; flex-wrap: wrap; gap: var(--space-xl);
            align-items: flex-end; justify-content: space-between;
        }
        .album-head h1 { font-size: var(--display-2xl); margin: var(--space-sm) 0 0; }
        .album-head .stats { gap: var(--space-xl); }

        .tabs {
            max-width: var(--measure); margin: 0 auto;
            padding: 0 var(--page-gutter) var(--space-lg);
            border-bottom: 1px solid var(--line);
        }
        .feed { max-width: var(--measure); margin: 0 auto; padding: var(--space-xl) var(--page-gutter) var(--space-3xl); }

        /* A wall of strips. They sit square here — the tilt is for the one strip
           a guest just shot, not for a grid of them. */
        .strips { display: grid; gap: var(--space-xl) var(--space-lg);
            grid-template-columns: repeat(auto-fill, minmax(min(140px, 100%), 1fr)); }
        /* In a narrow card the timestamp keeps its line and Delete drops below it. */
        .strips .card-foot { display: flex; flex-wrap: wrap; justify-content: space-between;
            align-items: baseline; gap: var(--space-xs); margin-top: var(--space-sm); }
        .strips .card-foot p { margin: 0; white-space: nowrap; }

        .photos { display: grid; gap: var(--space-sm);
            grid-template-columns: repeat(auto-fill, minmax(min(140px, 100%), 1fr)); }
        @media (min-width: 720px) {
            .strips { grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); }
            .photos { grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); }
        }
        .photos img { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover;
            border-radius: var(--r-sm); background: var(--surface-sunk); }

        .empty { text-align: center; color: var(--text-muted); font-family: var(--font-display);
            font-size: var(--display-sm); padding: var(--space-3xl) 0; }
    </style>
</head>
<body class="ctx-light">
    <header class="topbar">
        <a class="wordmark" href="/e/{{ $event->code }}">Photobooth</a>
        <div class="topbar-right share">
            <span class="code">{{ $event->code }}</span>
            <button type="button" class="btn--small share-btn" data-share-url="{{ url('/e/'.$event->code) }}" data-share-title="{{ $event->name }}">Share the album</button>
            <button type="button" class="btn--ghost btn--small share-copy" data-copy="{{ url('/e/'.$event->code) }}">Copy link</button>
            <span class="link-chip">{{ url('/e/'.$event->code) }}</span>
        </div>
    </header>

    <div class="album-head">
        <div>
            <p class="eyebrow">Event album</p>
            <h1>{{ $event->name }}</h1>
        </div>
        <div class="stats">
            <x-stat :figure="$stripCount" :label="Str::plural('strip', $stripCount)" />
            <x-stat :figure="$photoCount" :label="Str::plural('photo', $photoCount)" />
            <x-stat
                :figure="$event->isClosed() ? 'shut' : 'live'"
                :label="$event->isClosed() ? 'booth closed' : 'booth open'"
                :say="$event->isClosed() ? 'The booth is closed' : 'The booth is open'" />
        </div>
    </div>

    @if ($sessions->isNotEmpty())
        <div class="tabs chips">
            <button type="button" id="tab-strips" class="chip selected">Strips</button>
            <button type="button" id="tab-photos" class="chip">All photos</button>
            <button type="button" id="tab-order" class="chip">Latest first ⇅</button>
        </div>
    @endif

    <main class="feed">
        @if ($sessions->isEmpty())
            <p class="empty">No photos yet — be the first.</p>
        @else
            {{-- Strips: one card per session, newest first. --}}
            <div class="strips" id="panel-strips">
                @foreach ($sessions as $session)
                    @php($originals = $session->where('kind', 'original'))
                    <article data-group="{{ $session->first()->group_uuid }}">
                        @foreach ($session->where('kind', 'strip') as $photo)
                            <div class="strip-mat">
                                <img src="/e/{{ $event->code }}/photos/{{ $photo->id }}" alt="Photo strip" loading="lazy">
                            </div>
                        @endforeach
                        <div class="card-foot">
                            <p class="mono mono--plain">{{ $session->first()->created_at->format('H:i') }} · {{ $originals->count() }} {{ Str::plural('photo', $originals->count()) }}</p>
                            @if ($event->managedBy(auth()->user()))
                                <form class="delete" method="POST" action="/e/{{ $event->code }}/groups/{{ $session->first()->group_uuid }}"
                                      onsubmit="return confirm('Delete this session and its photos?')">
                                    @csrf
                                    @method('DELETE')
                                    <button>Delete</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            {{-- All photos: every original, sessions newest first. --}}
            <div class="photos" id="panel-photos" hidden>
                @foreach ($sessions->flatMap->where('kind', 'original') as $photo)
                    <img src="/e/{{ $event->code }}/photos/{{ $photo->id }}" data-group="{{ $photo->group_uuid }}" alt="Event photo" loading="lazy">
                @endforeach
            </div>
        @endif
    </main>

    @if ($sessions->isNotEmpty())
    <script>
        // Two views of the same album, and one ordering toggle over both grids.
        (function () {
            const panels = { strips: document.querySelector('#panel-strips'), photos: document.querySelector('#panel-photos') };
            const tabs = { strips: document.querySelector('#tab-strips'), photos: document.querySelector('#tab-photos') };

            for (const [name, tab] of Object.entries(tabs)) {
                tab.addEventListener('click', () => {
                    for (const [other, panel] of Object.entries(panels)) {
                        panel.hidden = other !== name;
                        tabs[other].classList.toggle('selected', other === name);
                    }
                });
            }

            // Reorder whole sessions, never the shots inside one — a strip's three
            // frames always read in the order they were taken.
            const flipSessions = (panel) => {
                const groups = [];
                for (const child of panel.children) {
                    const last = groups.at(-1);
                    if (last?.group === child.dataset.group) last.items.push(child);
                    else groups.push({ group: child.dataset.group, items: [child] });
                }
                panel.append(...groups.reverse().flatMap((g) => g.items));
            };

            let oldestFirst = false;
            const order = document.querySelector('#tab-order');
            order.addEventListener('click', () => {
                oldestFirst = !oldestFirst;
                order.textContent = (oldestFirst ? 'Oldest first' : 'Latest first') + ' ⇅';
                for (const panel of Object.values(panels)) flipSessions(panel);
            });
        })();
    </script>
    @endif
    @include('partials.share-script')
</body>
</html>
