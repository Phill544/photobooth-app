<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $event->name }} — Photobooth</title>
</head>
<body>
    <h1>{{ $event->name }}</h1>
</body>
</html>
