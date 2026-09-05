<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your events — Quikbooth</title>
    @include('partials.theme')
    <style>
        .topbar-right { font-size: var(--text-sm); color: var(--text-muted); }
        .topbar-right form { display: contents; }

        .head {
            max-width: var(--measure); margin: 0 auto;
            padding: var(--space-2xl) var(--page-gutter) 0;
            display: flex; flex-wrap: wrap; gap: var(--space-lg);
            align-items: flex-end; justify-content: space-between;
        }
        .head h1 { font-size: var(--display-md); margin: var(--space-sm) 0 0; }
        .head .btn { white-space: nowrap; }

        .events { list-style: none; max-width: var(--measure); margin: 0 auto;
            padding: var(--space-xl) var(--page-gutter) var(--space-3xl);
            display: flex; flex-direction: column; gap: var(--space-sm); }
        .events a {
            display: grid; align-items: center; gap: var(--space-xs) var(--space-lg);
            grid-template-columns: 8px 1fr auto;
            padding: var(--space-md) var(--space-lg); text-decoration: none; color: var(--text);
            background: var(--surface-sunk); border: 1px solid var(--line); border-radius: 14px;
        }
        .events a:hover { border-color: var(--line-strong); }
        .events .live a { background: var(--surface); border-color: var(--pink); }

        .dot { width: 8px; height: 8px; border-radius: 50%; background: #3A3A46; }
        .live .dot { background: var(--pink); box-shadow: 0 0 0 5px rgba(255, 0, 110, .22); }
        .name { font-size: var(--text-lg); font-weight: 500; color: var(--text-muted); min-width: 0; }
        .live .name { color: var(--text); }
        .meta { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--space-lg);
            grid-column: 2 / -1; }
        .meta p { margin: 0; }
        .meta .status { color: var(--text-faint); }
        .live .meta .status { color: var(--pink); }

        @media (min-width: 720px) {
            .events a { grid-template-columns: 8px minmax(0, 1fr) auto; }
            .meta { grid-column: auto; display: grid; align-items: baseline; justify-items: end;
                grid-template-columns: 7ch 11ch 7ch; gap: var(--space-lg); }
        }

        .empty { max-width: var(--measure); margin: 0 auto;
            padding: 0 var(--page-gutter); color: var(--text-muted); }

        /* The one thing an unverified host cannot do is the button beside this,
           so the notice sits with it rather than at the top of the page. */
        .verify {
            max-width: var(--measure); margin: var(--space-lg) auto 0;
            padding: var(--space-md) var(--page-gutter); display: flex; flex-wrap: wrap;
            gap: var(--space-sm); align-items: baseline; justify-content: space-between;
        }
        .verify p { margin: 0; font-size: var(--text-sm); color: var(--text-muted); }
        .verify strong { color: var(--text); font-weight: 500; }
        .verify form { margin: 0; }
    </style>
</head>
<body class="ctx-dark">
    <header class="topbar">
        <a class="wordmark" href="/">Quikbooth</a>
        <div class="topbar-right">
            <span>{{ auth()->user()->name }}</span>
            <span aria-hidden="true">·</span>
            <form method="POST" action="/logout">
                @csrf
                <button class="btn--danger">Log out</button>
            </form>
        </div>
    </header>

    <div class="head">
        <div>
            <p class="eyebrow">{{ $isAdmin ? 'All events (admin)' : 'Your booths' }}</p>
            <h1>
                @if ($events->isEmpty())
                    No booths yet
                @else
                    {{ $events->count() }} {{ Str::plural('event', $events->count()) }}, {{ $liveCount }} live
                @endif
            </h1>
        </div>
        <a href="/new" class="btn btn--accent btn--hero">New event</a>
    </div>

    @unless ($emailIsVerified)
        <div class="verify">
            <p><strong>Confirm your email</strong> to open a new booth. We sent a link to
                {{ auth()->user()->email }} — the events you already have are unaffected.</p>
            <form method="POST" action="/email/resend">
                @csrf
                <button class="btn--ghost btn--small">Send it again</button>
            </form>
        </div>
    @endunless

    @if ($events->isEmpty())
        <p class="empty">Create your first booth and you'll get a code and a QR poster to print.</p>
    @else
        <ul class="events">
            @foreach ($events as $event)
                <li @class(['live' => $event->status() === 'live'])>
                    <a href="/events/{{ $event->code }}">
                        <span class="dot"></span>
                        <span class="name">
                            {{ $event->name }}
                            {{-- Non-breaking space: the separator must not be orphaned on the title's last line. --}}
                            @if ($isAdmin && $event->owner) <span class="mono mono--plain">·&nbsp;{{ $event->owner->name }}</span> @endif
                        </span>
                        <span class="meta">
                            <p class="mono mono--plain">{{ $event->code }}</p>
                            <p class="muted">{{ $event->photos_count > 0 ? $event->photos_count.' '.Str::plural('photo', $event->photos_count) : 'Not started' }}</p>
                            <p class="mono status">{{ ['live' => 'Live', 'closed' => 'Closed', 'finished' => 'Finished'][$event->status()] }}</p>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</body>
</html>
