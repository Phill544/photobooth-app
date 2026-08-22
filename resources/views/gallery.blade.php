<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->name }} — Album</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 1rem; background: #f5f5f5; }
        .session {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            background: #fff;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
        }
        .session img { border-radius: 4px; }
        .session img.strip { max-height: 420px; }
        .originals {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 0.5rem;
            flex: 1;
        }
        .originals img { width: 100%; }
        .delete button {
            border: none;
            background: none;
            color: #b44;
            cursor: pointer;
            padding: 0;
        }
    </style>
</head>
<body>
    <h1>{{ $event->name }}</h1>

    @if ($sessions->isEmpty())
        <p>No photos yet — be the first!</p>
    @endif

    @foreach ($sessions as $session)
        <div class="session">
            @foreach ($session->where('kind', 'strip') as $photo)
                <img class="strip" src="/e/{{ $event->code }}/photos/{{ $photo->id }}" alt="Photo strip" loading="lazy">
            @endforeach
            <div class="originals">
                @foreach ($session->where('kind', 'original') as $photo)
                    <img src="/e/{{ $event->code }}/photos/{{ $photo->id }}" alt="Event photo" loading="lazy">
                @endforeach
                <form class="delete" method="POST" action="/e/{{ $event->code }}/groups/{{ $session->first()->group_uuid }}"
                      onsubmit="return confirm('Delete this session and its photos?')">
                    @csrf
                    @method('DELETE')
                    <button>Delete session</button>
                </form>
            </div>
        </div>
    @endforeach
</body>
</html>
