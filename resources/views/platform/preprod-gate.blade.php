<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Accès</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #0f172a; display: grid; place-items: center; min-height: 100vh; }
        main { width: 100%; max-width: 22rem; padding: 1.25rem; box-sizing: border-box; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 2px rgb(0 0 0 / .05); }
        .error { margin: 0 0 1rem; font-size: .875rem; border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; border-radius: .5rem; padding: .5rem .75rem; }
        input { display: block; width: 100%; box-sizing: border-box; padding: .6rem .75rem; border: 1px solid #cbd5e1; border-radius: .5rem; font-size: 1rem; }
        button { display: block; width: 100%; margin-top: .75rem; padding: .6rem; border: 0; border-radius: .5rem; background: #047857; color: #fff; font-size: 1rem; font-weight: 600; cursor: pointer; }
    </style>
</head>
<body>
<main>
    <div class="card">
        @if ($error)
            <p class="error" role="alert">{{ $error }}</p>
        @endif
        <form method="POST" action="{{ $action }}">
            <input type="hidden" name="retour" value="{{ $back }}">
            <input type="password" name="code" required autofocus autocomplete="current-password" aria-label="Code d'accès" placeholder="Code d'accès">
            <button type="submit">Entrer</button>
        </form>
    </div>
</main>
</body>
</html>
