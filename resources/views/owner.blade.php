<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->name }} — Event</title>
    @include('partials.theme')
    @vite('resources/js/strip-preview.ts')
    <style>
        /* The poster panel should reach the bottom of the window whatever the
           topbar measures — so the page is a column and .split takes the rest.
           Grid, not flex-wrap: the rail is either a 420px column or the whole
           row, with no in-between width where it strands an empty gutter. */
        body { display: flex; flex-direction: column; }
        .split { flex: 1; display: grid; grid-template-columns: 1fr; align-items: stretch; }
        @media (min-width: 900px) { .split { grid-template-columns: 420px minmax(440px, 1fr); } }

        /* The poster IS the page: this panel is what a host prints and tapes up. */
        .poster { min-width: 0; display: flex; background: var(--ink); }
        .poster-body { flex: 1; min-width: 0; display: flex; flex-direction: column;
            align-items: center; justify-content: center; text-align: center;
            gap: var(--space-md); padding: var(--space-2xl) var(--space-lg); }
        .poster h2 { font-size: 2.75rem; margin: 0; }
        .poster .code { font-size: 2.5rem; }
        .poster .type-at { margin: 0; color: var(--text-muted); font-size: var(--text-sm);
            overflow-wrap: anywhere; }

        .pane { min-width: 0; display: flex; flex-direction: column;
            gap: var(--space-lg); padding: var(--space-xl) var(--page-gutter) var(--space-lg); }
        .pane-head h1 { font-size: var(--display-md); margin: var(--space-sm) 0 0; }
        .pane .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: var(--space-sm); }
        .pane .stat { background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--r-md); padding: var(--space-md); }
        .actions { display: flex; flex-direction: column; gap: var(--space-sm); }
        .actions > .btn, .actions > button { width: 100%; }
        .foot { margin-top: auto; padding-top: var(--space-lg); border-top: 1px solid var(--line);
            display: flex; flex-wrap: wrap; gap: var(--space-sm);
            justify-content: space-between; align-items: center; }
        .foot p { margin: 0; color: var(--text-muted); font-size: var(--text-sm); }
        .foot form { margin: 0; }

        /* Edit the look — a host-only panel, folded away until wanted. */
        /* The panel splits on its own width, not the window's — it lives in a
           column that is only ever about half the page. */
        .edit { text-align: right; container-type: inline-size; }
        .edit > summary { display: inline-flex; list-style: none; }
        .edit > summary::-webkit-details-marker { display: none; }
        .edit-body { text-align: left; margin-top: var(--space-md);
            display: grid; gap: var(--space-lg); padding: var(--space-lg);
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md); }
        @container (min-width: 460px) { .edit-body { grid-template-columns: 1fr minmax(150px, 190px); align-items: start; } }
        .edit form { display: flex; flex-direction: column; gap: var(--space-lg); }
        /* The remove-logo tick is prose, not a mono field label. */
        .edit .field > label.muted { display: flex; align-items: center; gap: var(--space-xs);
            font-family: var(--font-sans); text-transform: none; letter-spacing: 0; }
        .edit-preview { display: flex; flex-direction: column; align-items: center; gap: var(--space-xs); }
        .edit-preview .strip-mat { width: 100%; max-width: 160px; }

        /* The one irreversible control on the page: reached deliberately, and
           given room of its own rather than a slot in the footer row. */
        .danger { margin-top: var(--space-lg); }
        .danger > summary { display: inline-flex; list-style: none; }
        .danger > summary::-webkit-details-marker { display: none; }
        .danger-body { margin-top: var(--space-md); padding: var(--space-lg);
            display: grid; gap: var(--space-lg);
            background: var(--surface); border: 1px solid var(--danger); border-radius: var(--r-md); }
        .danger-body > p { margin: 0; color: var(--text-muted); font-size: var(--text-sm); }
        .danger-body form { display: flex; flex-direction: column;
            gap: var(--space-lg); align-items: flex-start; }
        .danger-body input { text-transform: uppercase; font-family: var(--font-mono);
            letter-spacing: var(--tracking-mono); max-width: 12ch; }

        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .split { display: block; }
            .poster { background: #fff; }
            .poster-body { color: #000; --text-muted: #444; min-height: 100dvh; }
            .poster .perf-edge { display: none; }
            .qr { background: #fff; padding: 0; }
        }
    </style>
</head>
<body class="ctx-light">
    <header class="topbar no-print">
        <a class="wordmark" href="/dashboard">Photobooth</a>
        <a class="btn btn--ghost btn--small" href="/dashboard">Your events</a>
    </header>

    <div class="split">
        <section class="poster ctx-dark">
            <div class="perf-edge"></div>
            <div class="poster-body">
                <p class="eyebrow">Scan to shoot</p>
                <h2>{{ $event->name }}</h2>
                <div class="qr">{!! $qrSvg !!}</div>
                <p class="code">{{ $event->code }}</p>
                <p class="type-at">or type it at {{ url('/') }}</p>
            </div>
            <div class="perf-edge"></div>
        </section>

        <div class="pane no-print">
            <div class="pane-head">
                <p class="eyebrow">{{ $event->isClosed() ? 'Closed' : 'Live now' }}</p>
                <h1>{{ $event->name }}</h1>
            </div>

            <div class="stats">
                <x-stat :figure="$stripCount" :label="Str::plural('strip', $stripCount)" />
                <x-stat :figure="$photoCount" :label="Str::plural('photo', $photoCount)"
                        :say="$photoCount.' '.Str::plural('photo', $photoCount)" />
                <x-stat :figure="$lastStripAt?->format('H:i') ?? '—'" label="last strip"
                        :say="$lastStripAt ? 'Last strip at '.$lastStripAt->format('H:i') : 'No strips yet'" />
            </div>

            <div class="actions">
                <a class="btn" href="javascript:print()">Print the poster</a>
                {{-- .btn-row is a pair; the copy affordance goes with the URL below it. --}}
                <div class="btn-row">
                    <button type="button" class="btn--ghost share-btn" data-share-url="{{ url('/e/'.$event->code) }}" data-share-title="{{ $event->name }}">Share the link</button>
                    <a class="btn btn--ghost" href="/e/{{ $event->code }}/gallery">Open the album</a>
                </div>
                <div class="share">
                    <button type="button" class="btn--ghost btn--small share-copy" data-copy="{{ url('/e/'.$event->code) }}">Copy link</button>
                    <span class="link-chip">{{ url('/e/'.$event->code) }}</span>
                </div>
            </div>

            {{-- Its own fields only: the delete panel below has an error too,
                 and it must not fling this one open behind it. --}}
            <details class="edit" @if ($errors->hasAny(['name', 'template', 'theme', 'caption', 'logo'])) open @endif>
                <summary class="btn btn--ghost btn--small">Edit the look</summary>
                <div class="edit-body">
                    <form method="POST" action="/events/{{ $event->code }}" enctype="multipart/form-data" data-strip-form
                          @if ($event->logo_path) data-logo-url="{{ url($event->logoUrl()) }}" @endif>
                        @csrf
                        @method('PATCH')
                        <div class="field">
                            <label for="name">Event name</label>
                            <input id="name" name="name" maxlength="100" value="{{ old('name', $event->name) }}" required>
                        </div>

                        <fieldset class="field">
                            <legend class="field-label">Layout</legend>
                            <div class="swatches">
                                @foreach ($templates as $key => $label)
                                    <label>
                                        <input type="radio" class="sr-only" name="template" value="{{ $key }}"
                                               @checked(old('template', $event->template) === $key)>
                                        <span class="layout-swatch" data-layout="{{ $key }}"></span>
                                        <span class="sr-only">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <fieldset class="field">
                            <legend class="field-label">Strip colour</legend>
                            <div class="swatches">
                                @foreach ($themes as $key => $label)
                                    <label>
                                        <input type="radio" class="sr-only" name="theme" value="{{ $key }}"
                                               @checked(old('theme', $event->theme) === $key)>
                                        <span class="colour-swatch" data-theme="{{ $key }}"></span>
                                        <span class="sr-only">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <div class="field">
                            <label for="caption">Caption</label>
                            <input id="caption" name="caption" maxlength="60" placeholder="defaults to the event name" value="{{ old('caption', $event->caption) }}">
                        </div>
                        <div class="field">
                            <label for="logo">Logo</label>
                            <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
                            @if ($event->logo_path)
                                <label class="muted"><input type="checkbox" name="remove_logo" value="1"> Remove the current logo</label>
                            @else
                                <p class="hint">Replaces the caption on the strip.</p>
                            @endif
                        </div>

                        <button>Save changes</button>
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                        @error('template') <p class="error">{{ $message }}</p> @enderror
                        @error('theme') <p class="error">{{ $message }}</p> @enderror
                        @error('caption') <p class="error">{{ $message }}</p> @enderror
                        @error('logo') <p class="error">{{ $message }}</p> @enderror
                    </form>

                    <div class="edit-preview">
                        <p class="eyebrow">Preview</p>
                        <div class="strip-mat strip-mat--tilt">
                            <img data-strip-preview alt="A preview of your photo strip">
                        </div>
                        <p class="mono mono--plain" data-strip-summary></p>
                    </div>
                </div>
            </details>

            <div class="foot">
                @if ($event->isClosed())
                    <p><a href="/e/{{ $event->code }}">The booth</a> is closed — guests can still see the album.</p>
                    <form method="POST" action="/events/{{ $event->code }}/toggle-closed">
                        @csrf
                        <button class="btn--small">Reopen the booth</button>
                    </form>
                @else
                    <p><a href="/e/{{ $event->code }}">The booth</a> is open to anyone with the code.</p>
                    <form method="POST" action="/events/{{ $event->code }}/toggle-closed">
                        @csrf
                        <button class="btn--danger">Close the booth</button>
                    </form>
                @endif
            </div>

            {{-- Closing a booth is reversible; this is not, so it sits apart from
                 the controls above, folded away, and asks for the code by hand.
                 The server checks that code — the fold is only manners. --}}
            <details class="danger" id="delete" {{ $errors->has('confirm_code') ? 'open' : '' }}>
                <summary class="btn btn--ghost btn--small">Delete this event</summary>
                <div class="danger-body">
                    <p>Deletes the booth, the album, and every file behind
                        {{ $stripCount }} {{ Str::plural('strip', $stripCount) }} and
                        {{ $photoCount }} {{ Str::plural('photo', $photoCount) }}. Guests keep
                        anything they already saved to their phone. Nothing else can be undone.</p>
                    <form method="POST" action="/events/{{ $event->code }}">
                        @csrf
                        @method('DELETE')
                        <div class="field">
                            <label for="confirm_code">Type {{ $event->code }} to confirm</label>
                            <input id="confirm_code" name="confirm_code" maxlength="6" required
                                   autocomplete="off" autocapitalize="characters" spellcheck="false">
                        </div>
                        <button class="btn--danger">Delete this event forever</button>
                        @error('confirm_code') <p class="error">{{ $message }}</p> @enderror
                    </form>
                </div>
            </details>
        </div>
    </div>
    @include('partials.share-script')
</body>
</html>
