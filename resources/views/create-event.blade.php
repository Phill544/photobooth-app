<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Event — Photobooth</title>
    @include('partials.theme')
    @vite('resources/js/strip-preview.ts')
    <style>
        body.ctx-dark { display: grid; place-items: center; padding: var(--space-xl) var(--page-gutter); }
        main { width: min(100%, 820px); min-width: 0; max-width: 100%; text-align: center; }

        .create { display: grid; gap: var(--space-2xl); grid-template-columns: 1fr; margin-top: var(--space-lg); }
        @media (min-width: 760px) { .create { grid-template-columns: 1fr minmax(220px, 300px); align-items: start; text-align: left; } }

        form { display: flex; flex-direction: column; gap: var(--space-md); align-items: center; }
        @media (min-width: 760px) { form { align-items: stretch; } }
        input, select { width: min(100%, 340px); }
        .field { display: flex; flex-direction: column; gap: var(--space-2xs); }
        .field label { font-size: var(--text-sm); color: var(--text-muted); }
        button { align-self: center; }
        @media (min-width: 760px) { button { align-self: start; } }
        .error { color: var(--danger); }

        .preview { display: flex; flex-direction: column; align-items: center; gap: var(--space-sm); }
        .preview p { margin: 0; }
        #preview-strip { max-width: 100%; max-height: 46dvh; border-radius: var(--r-sm);
            box-shadow: var(--shadow-lg); rotate: var(--strip-tilt); background: #111; }
        @media (min-width: 760px) { #preview-strip { max-height: 62dvh; } }
    </style>
</head>
<body class="ctx-dark">
    <main>
        <p class="eyebrow">New event</p>
        <h1>Set up your booth</h1>

        <div class="create">
            <form id="create-form" method="POST" action="/events" data-strip-form>
                @csrf
                <div class="field">
                    <label for="name">Event name</label>
                    <input id="name" name="name" maxlength="100" placeholder="Sarah's 30th" value="{{ old('name') }}" required autofocus>
                </div>
                <div class="field">
                    <label for="template">Strip layout</label>
                    <select id="template" name="template">
                        @foreach ($templates as $key => $label)
                            <option value="{{ $key }}" @selected(old('template') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="theme">Strip colour</label>
                    <select id="theme" name="theme">
                        @foreach ($themes as $key => $label)
                            <option value="{{ $key }}" @selected(old('theme') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="caption">Strip caption <span class="muted">(optional)</span></label>
                    <input id="caption" name="caption" maxlength="60" placeholder="defaults to the event name" value="{{ old('caption') }}">
                </div>
                <button class="btn--hero">Create the booth</button>
                @error('name') <p class="error">{{ $message }}</p> @enderror
                @error('template') <p class="error">{{ $message }}</p> @enderror
                @error('theme') <p class="error">{{ $message }}</p> @enderror
                @error('caption') <p class="error">{{ $message }}</p> @enderror
            </form>

            <aside class="preview">
                <p class="eyebrow">Preview</p>
                <img id="preview-strip" data-strip-preview alt="A preview of your photo strip">
            </aside>
        </div>
    </main>
</body>
</html>
