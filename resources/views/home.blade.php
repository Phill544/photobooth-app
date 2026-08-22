<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Photobooth</title>
    @include('partials.theme')
    <style>
        body.ctx-dark { display: grid; place-items: center; text-align: center; padding: var(--space-lg); }
        main { width: min(100%, 420px); }
        form { margin-top: var(--space-lg); }
        .host-link { margin-top: var(--space-2xl); font-size: var(--text-sm); }
    </style>
</head>
<body class="ctx-dark">
    <main>
        <p class="eyebrow">Photobooth</p>
        <h1>Join the booth</h1>
        <form id="join">
            <input id="code" name="code" maxlength="6" autocapitalize="characters" autocomplete="off" placeholder="CODE" required>
            <br>
            <button class="btn--hero">Join</button>
        </form>
        <p class="host-link"><a href="/dashboard">Hosting an event? Sign in →</a></p>
    </main>
    <script>
        document.querySelector('#join').addEventListener('submit', (event) => {
            event.preventDefault();
            const code = document.querySelector('#code').value.trim();
            location.href = `/e/${encodeURIComponent(code)}`;
        });
    </script>
</body>
</html>
