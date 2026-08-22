<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Event — Photobooth</title>
    @include('partials.theme')
    <style>
        body.ctx-dark { display: grid; place-items: center; text-align: center; padding: var(--space-lg); }
        main { width: min(100%, 460px); }
        form { margin-top: var(--space-lg); }
        input[name="name"] { width: min(100%, 320px); }
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
            <br>
            <button class="btn--hero">Create the booth</button>
            @error('name')
                <p class="error">{{ $message }}</p>
            @enderror
        </form>
    </main>
</body>
</html>
