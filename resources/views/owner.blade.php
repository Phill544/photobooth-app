<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->name }} — Event</title>
    @include('partials.theme')
    @vite('resources/js/strip-preview.ts')
    <style>
        body.ctx-light { display: grid; place-items: center; text-align: center; padding: var(--space-md) var(--page-gutter); }
        main { width: min(100%, 480px); min-width: 0; max-width: 100%; }
        .card { display: flex; flex-direction: column; align-items: center; gap: var(--space-xs); padding: var(--space-lg); }
        .card p { max-width: 100%; overflow-wrap: anywhere; }
        .card .code { margin: var(--space-2xs) 0; }
        .host-links { margin-top: var(--space-lg); font-size: var(--text-sm); display: flex; flex-wrap: wrap; gap: var(--space-md); justify-content: center; }
        .close-form { margin-top: var(--space-md); }

        .edit { margin-top: var(--space-lg); text-align: left; border: 1px solid var(--line); border-radius: var(--r-md); background: var(--surface); }
        .edit > summary { cursor: pointer; padding: var(--space-md); font-weight: 500; list-style: none; }
        .edit > summary::-webkit-details-marker { display: none; }
        .edit-body { display: grid; gap: var(--space-lg); padding: 0 var(--space-md) var(--space-md); }
        @media (min-width: 620px) { .edit-body { grid-template-columns: 1fr minmax(160px, 200px); align-items: start; } }
        .edit form { display: flex; flex-direction: column; gap: var(--space-md); }
        .edit .field { display: flex; flex-direction: column; gap: var(--space-2xs); }
        .edit label { font-size: var(--text-sm); color: var(--text-muted); }
        .edit input, .edit select { width: 100%; font-size: var(--text-lg); padding: .55rem .8rem;
            color: var(--text); background: var(--bg-elev); border: 1px solid var(--line-strong); border-radius: var(--r-md); }
        .edit-preview { display: flex; flex-direction: column; align-items: center; gap: var(--space-xs); }
        #preview-strip { max-width: 100%; max-height: 40dvh; border-radius: var(--r-sm); box-shadow: var(--shadow-md); rotate: var(--strip-tilt); background: #111; }
        .edit .error { color: var(--danger); font-size: var(--text-sm); }
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

        <details class="edit no-print" @if ($errors->any()) open @endif>
            <summary>✏️ Edit the booth look</summary>
            <div class="edit-body">
                <form method="POST" action="/events/{{ $event->code }}" enctype="multipart/form-data" data-strip-form
                      @if ($event->logo_path) data-logo-url="{{ url('/e/'.$event->code.'/logo') }}" @endif>
                    @csrf
                    @method('PATCH')
                    <div class="field">
                        <label for="name">Event name</label>
                        <input id="name" name="name" maxlength="100" value="{{ old('name', $event->name) }}" required>
                    </div>
                    <div class="field">
                        <label for="template">Strip layout</label>
                        <select id="template" name="template">
                            @foreach ($templates as $key => $label)
                                <option value="{{ $key }}" @selected(old('template', $event->template) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="theme">Strip colour</label>
                        <select id="theme" name="theme">
                            @foreach ($themes as $key => $label)
                                <option value="{{ $key }}" @selected(old('theme', $event->theme) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="caption">Strip caption <span class="muted">(optional)</span></label>
                        <input id="caption" name="caption" maxlength="60" placeholder="defaults to the event name" value="{{ old('caption', $event->caption) }}">
                    </div>
                    <div class="field">
                        <label for="logo">Logo <span class="muted">(optional — replaces the caption)</span></label>
                        <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
                        @if ($event->logo_path)
                            <label class="muted"><input type="checkbox" name="remove_logo" value="1"> Remove the current logo</label>
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
                    <img id="preview-strip" data-strip-preview alt="A preview of your photo strip">
                </div>
            </div>
        </details>
    </main>
    @include('partials.share-script')
</body>
</html>
