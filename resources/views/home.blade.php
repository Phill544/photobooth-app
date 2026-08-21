<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Photobooth</title>
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
            font-size: 1.5rem;
            width: 8ch;
            padding: 0.5rem;
            border-radius: 8px;
            border: none;
            text-align: center;
            text-transform: uppercase;
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
    </style>
</head>
<body>
    <main>
        <h1>📸 Photobooth</h1>
        <form id="join">
            <input id="code" name="code" maxlength="6" autocapitalize="characters" autocomplete="off" placeholder="CODE" required>
            <br>
            <button>Join the booth</button>
        </form>
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
