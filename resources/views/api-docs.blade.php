<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API publique · {{ config('app.name') }}</title>
    <style>body { margin: 0; background: #fff; }</style>
</head>
<body>
    <p id="fallback" style="font-family: sans-serif; padding: 1rem;">
        Chargement de la documentation… Si elle ne s'affiche pas, ouvrez la
        <a href="{{ asset('docs/openapi.yaml') }}">spécification OpenAPI</a> dans votre outil (Postman, Insomnia, Swagger).
    </p>
    <redoc spec-url="{{ asset('docs/openapi.yaml') }}" hide-download-button="false"></redoc>
    <script src="https://cdn.jsdelivr.net/npm/redoc@2.1.5/bundles/redoc.standalone.js" onload="document.getElementById('fallback').remove()"></script>
</body>
</html>
