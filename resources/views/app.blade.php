@php
    // Application installable selon l'espace : livreur ou e-commerçant (l'admin n'est pas une PWA)
    // Pages des clients (boutique d'un marchand, suivi d'un colis) : pas d'application à installer
    // Fenêtre « Connecter WhatsApp » : rien à installer non plus
    $pwa = request()->is('livreur*') ? 'livreur' : (request()->is('admin*', 'b/*', 'suivi*', 'connecter-whatsapp') ? null : 'marchand');
    // Marque blanche : sur l'adresse d'une entreprise, son nom ; sinon celui de la plateforme
    // (sans base de données joignable, le nom de la plateforme : la page doit toujours s'afficher)
    $brandCompany = rescue(fn () => \App\Support\Branding::company(), null, false);
    $siteName = $brandCompany?->name ?? config('app.name', 'Livraison');
    // Couleurs et icônes choisies par l'entreprise (Paramètres › Apparence)
    $look = rescue(fn () => \App\Support\Appearance::for($brandCompany)['spaces'], \App\Support\Appearance::DEFAULTS, false);
    $theme = $look[request()->is('admin*') ? 'admin' : ($pwa ?? 'admin')]['color'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ $theme }}">
    <title>{{ $siteName }}</title>
    @if ($pwa)
        <link rel="manifest" href="{{ route('pwa.manifest', $pwa, false) }}">
        <link rel="apple-touch-icon" href="{{ rescue(fn () => \App\Support\Appearance::iconUrl($brandCompany, $pwa, 'apple-180'), "/app-icons/{$pwa}-apple-180.png", false) }}">
        @if (rescue(fn () => \App\Support\Appearance::hasCustomIcon($brandCompany, $pwa), false, false))
            <link rel="icon" type="image/png" href="{{ \App\Support\Appearance::iconUrl($brandCompany, $pwa, '192') }}">
        @else
            <link rel="icon" type="image/svg+xml" href="/app-icons/{{ $pwa }}.svg">
        @endif
    @endif
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ $look[$pwa ?? 'marchand']['app_name'] ?? ($pwa === 'livreur' ? 'Livreur' : 'Mes livraisons') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    @if (app()->environment('staging'))
        {{-- Préproduction : jamais confondue avec le site des clients --}}
        <div style="position:fixed;left:.5rem;bottom:.5rem;z-index:9999;pointer-events:none;background:#b45309;color:#fff;font:600 11px/1 system-ui,sans-serif;padding:.35rem .6rem;border-radius:999px;opacity:.9">Préproduction</div>
    @endif

    <div id="app"></div>
</body>
</html>
