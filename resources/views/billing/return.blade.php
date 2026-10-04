<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Returning to PLNR</title>
</head>
<body>
    <p>Returning to PLNR…</p>
    <p><a href="{{ $target }}">Open the app</a></p>
    <script>
        window.location.replace(@json($target));
    </script>
</body>
</html>
