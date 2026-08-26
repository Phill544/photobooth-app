<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Event — Photobooth</title>
    @include('partials.theme')
    @vite('resources/js/strip-preview.ts')
    <style>
        /* The dark preview rail should reach the bottom of the window, whatever
           the topbar measures — so the page is a column and .setup takes the rest.
           Grid, not flex-wrap: the rail is either a 320px column or the whole
           row, with no in-between width where it strands an empty gutter. */
        body { display: flex; flex-direction: column; }
        .setup { flex: 1; display: grid; grid-template-columns: 1fr; align-items: stretch; }
        @media (min-width: 820px) { .setup { grid-template-columns: minmax(0, 1fr) 320px; } }
        .pane { min-width: 0; display: flex; flex-direction: column;
            padding: var(--space-2xl) var(--page-gutter) var(--space-xl); }
        .pane h1 { font-size: var(--display-md); margin: var(--space-sm) 0 var(--space-xl); }
        form { display: flex; flex-direction: column; gap: var(--space-lg); max-width: 440px; }
        #name { font-family: var(--font-display); font-size: 1.625rem; font-weight: 400; }
        .pair { display: flex; flex-wrap: wrap; gap: var(--space-lg); }
        .pair > .field { flex: 1 1 180px; }
        .pair input { border-bottom-width: 1px; font-size: var(--text-base); }
        .submit { margin-top: var(--space-xl); display: flex; flex-wrap: wrap;
            align-items: center; gap: var(--space-lg); }
        .submit p { margin: 0; color: var(--text-muted); font-size: var(--text-sm); }

        /* The dark island: a live strip, exactly as the booth will draw it. */
        .preview { min-width: 0; background: var(--ink);
            display: flex; flex-direction: column; align-items: center; gap: var(--space-lg);
            padding: var(--space-2xl) var(--space-xl); }
        .preview .strip-mat { width: 176px; }
        #strip-summary { text-align: center; }
    </style>
</head>
<body class="ctx-light">
    <header class="topbar">
        <a class="wordmark" href="/dashboard">Photobooth</a>
        <a class="btn--ghost btn--small btn" href="/dashboard">Your events</a>
    </header>

    <div class="setup">
        <div class="pane">
            <p class="eyebrow">New event</p>
            <h1>Build the booth</h1>

            <form id="create-form" method="POST" action="/events" enctype="multipart/form-data" data-strip-form>
                @csrf
                <div class="field">
                    <label for="name">Event name</label>
                    <input id="name" name="name" maxlength="100" placeholder="Sarah's 30th" value="{{ old('name') }}" required autofocus>
                </div>

                <fieldset class="field">
                    <legend class="field-label">Layout</legend>
                    <div class="swatches">
                        @foreach ($templates as $key => $label)
                            <label>
                                <input type="radio" class="sr-only" name="template" value="{{ $key }}"
                                       @checked(old('template', array_key_first($templates)) === $key)>
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
                                       @checked(old('theme', array_key_first($themes)) === $key)>
                                <span class="colour-swatch" data-theme="{{ $key }}"></span>
                                <span class="sr-only">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="pair">
                    <div class="field">
                        <label for="caption">Caption</label>
                        <input id="caption" name="caption" maxlength="60" placeholder="defaults to the event name" value="{{ old('caption') }}">
                    </div>
                    <div class="field">
                        <label for="logo">Logo</label>
                        <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
                        <p class="hint">Replaces the caption on the strip.</p>
                    </div>
                </div>

                <div class="submit">
                    <button class="btn--accent btn--hero">Open the booth</button>
                    <p>You'll get a QR poster to print.</p>
                </div>

                @error('name') <p class="error">{{ $message }}</p> @enderror
                @error('template') <p class="error">{{ $message }}</p> @enderror
                @error('theme') <p class="error">{{ $message }}</p> @enderror
                @error('caption') <p class="error">{{ $message }}</p> @enderror
                @error('logo') <p class="error">{{ $message }}</p> @enderror
            </form>
        </div>

        <aside class="preview ctx-dark">
            <p class="eyebrow">Live preview</p>
            <div class="strip-mat strip-mat--tilt">
                <img data-strip-preview alt="A preview of your photo strip">
            </div>
            <p class="mono mono--plain" id="strip-summary" data-strip-summary></p>
        </aside>
    </div>
</body>
</html>
