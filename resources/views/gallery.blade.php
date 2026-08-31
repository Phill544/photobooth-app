<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The event code is the only credential here, so an indexed link would
         publish the whole event. robots.txt says the same thing for crawlers
         that never fetch the page. --}}
    <meta name="robots" content="noindex, nofollow">
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
        /* The order flip is a link now (it reorders the album, not the page),
           so it has to be talked back into looking like the chips beside it. */
        a.chip { display: inline-flex; align-items: center; text-decoration: none; }
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

        /* Every grid image is a link to the full-size file: without JS it just
           opens, with JS the overlay below intercepts it. */
        a.tile { display: block; min-width: 0; cursor: zoom-in; }

        #lightbox {
            position: fixed; inset: 0; z-index: 60; background: rgba(11, 11, 16, .93);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: var(--space-lg);
            padding: calc(var(--space-2xl) + env(safe-area-inset-top)) var(--space-lg)
                     calc(var(--space-lg) + env(safe-area-inset-bottom));
        }
        #lightbox[hidden] { display: none; }
        #lightbox-image { max-width: min(100%, 520px); max-height: 70dvh; object-fit: contain;
            border-radius: var(--r-sm); box-shadow: var(--shadow-lg); }
        #lightbox-close {
            position: absolute; top: calc(var(--space-md) + env(safe-area-inset-top)); right: var(--space-md);
            min-height: 0; padding: .4rem .8rem; background: rgba(244, 242, 237, .14);
            color: var(--ivory); box-shadow: none; font-size: var(--text-lg); line-height: 1;
        }
        #lightbox-close:hover { transform: none; }

        .expiry-notice {
            max-width: var(--measure); margin: 0 auto; padding: 0 var(--page-gutter) var(--space-md);
            color: var(--danger); font-size: var(--text-sm);
        }
        .expiry-notice--calm { color: var(--text-muted); }

        .empty { text-align: center; color: var(--text-muted); font-family: var(--font-display);
            font-size: var(--display-sm); padding: var(--space-3xl) 0; }

        /* The foot of the album: a real link to the next page of sessions, which
           the script below follows on approach instead of waiting for a tap. */
        .more { display: flex; justify-content: center; padding-top: var(--space-2xl); }
        .more p { margin: 0; color: var(--text-muted); }
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
            @php($status = $event->status())
            <x-stat
                :figure="['live' => 'live', 'closed' => 'shut', 'finished' => 'done'][$status]"
                :label="['live' => 'booth open', 'closed' => 'booth closed', 'finished' => 'event over'][$status]"
                :say="['live' => 'The booth is open', 'closed' => 'The booth is closed', 'finished' => 'The event is over'][$status]" />
        </div>
    </div>

    {{-- Only the host reaches an expired album — for guests it is the gate. The
         grace period is theirs to use, so the page says how much of it is left
         and what happens at the end of it. Once the sweep has been through there
         is no more time to offer: saying so is the whole point of recording when
         it ran. --}}
    @if ($event->photosWerePurged())
        <p class="expiry-notice">This album's photos were deleted on {{ $event->photos_purged_at->format('j M Y') }},
            at the end of the window it was kept for. Nothing here can bring them back.</p>
    @elseif ($event->hasExpired())
        <p class="expiry-notice">This album expired on {{ $event->photos_expire_at->format('j M Y') }}.
            Its photos are deleted on {{ $event->photos_expire_at->copy()->addDays($graceDays)->format('j M Y') }}
            unless you <a href="/events/{{ $event->code }}#retention">give it more time</a>.</p>
    @elseif ($event->photos_expire_at)
        <p class="expiry-notice expiry-notice--calm">Photos in this album are kept until {{ $event->photos_expire_at->format('j M Y') }}.</p>
    @endif

    {{-- Whether the album has anything in it, which is not the same question as
         whether this page of it does: a cursor can outlive the sessions behind
         it, and "No photos yet" would be a lie on an album of a thousand. --}}
    @php($hasPhotos = $stripCount + $photoCount > 0)

    @if ($hasPhotos)
        <div class="tabs chips">
            <button type="button" id="tab-strips" class="chip selected">Strips</button>
            <button type="button" id="tab-photos" class="chip">All photos</button>
            {{-- A link, not a toggle: only one page of sessions is here, so
                 flipping the DOM would reorder a slice and call it the album. --}}
            <a id="tab-order" class="chip" href="{{ $flipUrl }}">{{ $oldestFirst ? 'Oldest first' : 'Latest first' }} ⇅</a>
        </div>
    @endif

    <main class="feed">
        @if (! $hasPhotos)
            {{-- ...and a swept album is not an album nobody has shot into yet.
                 The notice above has already said what happened to it. --}}
            @unless ($event->photosWerePurged())
                <p class="empty">No photos yet — be the first.</p>
            @endunless
        @else
            {{-- Strips: one card per session, newest first. --}}
            <div class="strips" id="panel-strips">
                @foreach ($sessions as $session)
                    @php($originals = $session->where('kind', 'original'))
                    <article data-group="{{ $session->first()->group_uuid }}">
                        @foreach ($session->where('kind', 'strip') as $photo)
                            <a class="tile" href="{{ $photo->url($event->code) }}" data-name="Photo strip">
                                <div class="strip-mat">
                                    <img src="{{ $photo->gridUrl($event->code) }}" alt="Photo strip" loading="lazy">
                                </div>
                            </a>
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
                    <a class="tile" href="{{ $photo->url($event->code) }}" data-group="{{ $photo->group_uuid }}"
                       data-name="Event photo">
                        <img src="{{ $photo->gridUrl($event->code) }}" alt="Event photo" loading="lazy">
                    </a>
                @endforeach
            </div>

            @if ($nextPage)
                <div class="more">
                    <a id="more" class="btn btn--ghost" href="{{ $nextPage }}" rel="next">Load more</a>
                </div>
            @elseif ($sessions->isEmpty())
                {{-- Nothing left behind this cursor — the sessions it named were
                     deleted after the page that linked here was rendered. --}}
                <div class="more"><p class="mono mono--plain">That's the whole album.</p></div>
            @endif
        @endif
    </main>

    {{-- Tap-to-enlarge. The grids show derivatives; this is where the full-size
         file gets loaded, and where a guest saves the one photo they want. --}}
    @if ($hasPhotos)
    <div id="lightbox" hidden>
        <button type="button" id="lightbox-close" aria-label="Close">&times;</button>
        <img id="lightbox-image" alt="">
        {{-- Bare `download`: the filename comes from the server's
             Content-Disposition (Photo::downloadName), which a browser prefers
             over anything stated here anyway. --}}
        <a id="lightbox-save" class="btn btn--light" download>Save this photo</a>
    </div>
    @endif

    @if ($hasPhotos)
    <script>
        // Two views of the same album. (Ordering is the server's job now — one
        // page of sessions is here, and flipping it would reorder a slice.)
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
        })();
    </script>

    <script>
        // The album arrives a page of sessions at a time; this fetches the next
        // one as the guest nears the bottom and pours both panels into the ones
        // already on screen. The link it follows is the same one it replaces, so
        // a browser with no JS — or a fetch that fails — still has a tap that
        // works, and a page that stalls is one the guest can nudge by hand.
        (function () {
            const more = document.querySelector('#more');
            if (!more) return;

            const panels = ['strips', 'photos'];
            let loading = false;

            const observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) load();
            }, { rootMargin: '600px' }); // start fetching before the foot is reached

            const finish = () => {
                observer.disconnect();
                more.closest('.more').innerHTML = '<p class="mono mono--plain">That\'s the whole album.</p>';
            };

            async function load() {
                if (loading) return;
                loading = true;
                more.textContent = 'Loading…';

                try {
                    const response = await fetch(more.getAttribute('href'));
                    if (!response.ok) throw new Error(response.status);
                    const page = new DOMParser().parseFromString(await response.text(), 'text/html');

                    // A page with no panels at all is an album that emptied out
                    // under us — a host can delete the last session while this
                    // one is still holding a link to it. Nothing to append, and
                    // no #more below, so it ends the scroll rather than throwing.
                    for (const name of panels) {
                        const arrived = page.querySelector('#panel-' + name);
                        if (arrived) document.querySelector('#panel-' + name).append(...arrived.children);
                    }

                    // The page just appended names the one after it, or the
                    // album ends here.
                    const next = page.querySelector('#more');
                    if (next) more.setAttribute('href', next.getAttribute('href'));
                    else finish();
                } catch {
                    // Nothing was appended, so the link still points at the page
                    // that failed: leave it be, and a tap tries again.
                }

                if (more.isConnected) more.textContent = 'Load more';
                loading = false;
            }

            // A tap has to append too, not follow the link: the guest who
            // reaches the foot before the observer does would otherwise be
            // navigated off the album they just scrolled through.
            more.addEventListener('click', (event) => {
                event.preventDefault();
                load();
            });

            observer.observe(more);
        })();
    </script>

    <script>
        // Enlarging a photo: the grid links to the full-size file, and this
        // intercepts the tap. Without JS the link still opens the image.
        (function () {
            const box = document.querySelector('#lightbox');
            const image = document.querySelector('#lightbox-image');
            const save = document.querySelector('#lightbox-save');
            const closeButton = document.querySelector('#lightbox-close');
            let opener = null;

            const open = (tile) => {
                opener = tile;
                image.src = tile.href;
                image.alt = tile.dataset.name;
                save.href = tile.href;
                box.hidden = false;
                document.body.style.overflow = 'hidden'; // the album must not scroll behind it
                closeButton.focus();
            };

            const close = () => {
                box.hidden = true;
                image.removeAttribute('src'); // stop a big file downloading into a closed overlay
                document.body.style.overflow = '';
                opener?.focus();
            };

            document.addEventListener('click', (event) => {
                const tile = event.target.closest('a.tile');
                if (tile) {
                    event.preventDefault();
                    open(tile);
                    return;
                }
                if (event.target === box) close(); // the backdrop, not the image or the buttons
            });

            closeButton.addEventListener('click', close);
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !box.hidden) close();
            });
        })();
    </script>
    @endif
    @include('partials.share-script')
</body>
</html>
