<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->name }} — Album</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 1rem; }
        .photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.5rem; }
        .photos img { width: 100%; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>{{ $event->name }}</h1>

    @if ($photos->isEmpty())
        <p>No photos yet — be the first!</p>
    @endif

    <div class="photos">
        @foreach ($photos as $photo)
            <img src="/e/{{ $event->code }}/photos/{{ $photo->id }}" alt="Event photo" loading="lazy">
        @endforeach
    </div>
</body>
</html>
