<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Event — Photobooth</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            margin: 0;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            background: #111;
            color: #fff;
            text-align: center;
        }
        input {
            font-size: 1.25rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            border: none;
            text-align: center;
        }
        button {
            font-size: 1.25rem;
            padding: 0.75rem 2rem;
            border-radius: 999px;
            border: none;
            background: #fff;
            color: #111;
            margin-top: 1rem;
        }
        .error { color: #f88; }
    </style>
</head>
<body>
    <main>
        <h1>Name your event</h1>
        <form method="POST" action="/events">
            @csrf
            <input name="name" maxlength="100" placeholder="Sarah's 30th" value="{{ old('name') }}" required autofocus>
            <br>
            <button>Create the event</button>
            @error('name')
                <p class="error">{{ $message }}</p>
            @enderror
        </form>
    </main>
</body>
</html>
