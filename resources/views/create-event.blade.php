<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Event — Photobooth</title>
    @include('partials.theme')
    <style>
        body.ctx-dark { display: grid; place-items: center; text-align: center; padding: var(--space-lg); }
        main { width: min(100%, 460px); min-width: 0; max-width: 100%; }
        form { margin-top: var(--space-lg); }
        input[name="name"] { width: min(100%, 320px); }
        .field { margin-top: var(--space-md); }
        .field label { display: block; font-size: var(--text-sm); color: var(--text-muted); margin-bottom: var(--space-2xs); }
        select {
            font-family: var(--font-sans); font-size: var(--text-lg); padding: .6rem 1rem;
            color: var(--text); background: var(--bg-elev); border: 1px solid var(--line-strong);
            border-radius: var(--r-md); width: min(100%, 320px);
        }
        .error { color: var(--danger); margin-top: var(--space-sm); }
    </style>
</head>
<body class="ctx-dark">
    <main>
        <p class="eyebrow">New event</p>
        <h1>Name your booth</h1>
        <form method="POST" action="/events">
            @csrf
            <input name="name" maxlength="100" placeholder="Sarah's 30th" value="{{ old('name') }}" required autofocus>
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
            @error('name')
                <p class="error">{{ $message }}</p>
            @enderror
            @error('template')
                <p class="error">{{ $message }}</p>
            @enderror
        </form>
    </main>
</body>
</html>
