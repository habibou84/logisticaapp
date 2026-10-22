<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $message }}</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #0f172a; display: grid; place-items: center; min-height: 100vh; }
        main { max-width: 28rem; padding: 1.25rem; text-align: center; }
        p { color: #475569; line-height: 1.6; }
    </style>
</head>
<body>
<main>
    <h1>{{ $message }}</h1>
    <p>Vérifiez l'adresse donnée par votre entreprise de livraison, ou contactez-la.</p>
    <p><a href="{{ config('platform.scheme') }}://{{ $domain }}">{{ $domain }}</a></p>
</main>
</body>
</html>
