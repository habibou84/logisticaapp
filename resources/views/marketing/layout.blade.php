<!DOCTYPE html>
<html lang="fr" class="bg-white">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Jibiat · Le logiciel des entreprises de livraison')</title>
    <meta name="description" content="@yield('description', 'Courses, livreurs, caisse, paie et e-commerçants : Jibiat gère toute votre entreprise de livraison, sur ordinateur et sur téléphone. Essai gratuit de '.$trialDays.' jours.')">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta name="theme-color" content="#0b1120">
    <link rel="icon" type="image/png" href="/marketing/favicon.png">
    <link rel="apple-touch-icon" href="/marketing/apple-touch-icon.png">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:site_name" content="Jibiat">
    <meta property="og:title" content="@yield('title', 'Jibiat · Le logiciel des entreprises de livraison')">
    <meta property="og:description" content="@yield('description', 'Courses, livreurs, caisse et e-commerçants : toute votre entreprise de livraison dans une seule application.')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ url('/marketing/og-jibiat.jpg') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    @vite('resources/css/marketing.css')
    @stack('head')
</head>
<body class="min-h-screen bg-white font-sans text-slate-900 antialiased">
    @if (app()->environment('staging'))
        {{-- Préproduction : jamais confondue avec le site des clients --}}
        <div style="position:fixed;left:.5rem;bottom:.5rem;z-index:9999;pointer-events:none;background:#b45309;color:#fff;font:600 11px/1 system-ui,sans-serif;padding:.35rem .6rem;border-radius:999px;opacity:.9">Préproduction</div>
    @endif

    <a href="#contenu" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2">Aller au contenu</a>

    <header class="absolute inset-x-0 top-0 z-40">
        <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6" aria-label="Navigation principale">
            <a href="/" class="flex items-center gap-2 text-white">
                <img src="/marketing/logo.png" alt="" class="size-9" width="36" height="36">
                <span class="text-xl font-bold tracking-tight">Jibiat</span>
            </a>

            <div class="hidden items-center gap-8 text-sm font-medium text-slate-300 md:flex">
                <a href="/fonctionnalites" class="hover:text-white">Fonctionnalités</a>
                <a href="/#applications" class="hover:text-white">Applications</a>
                <a href="/tarifs" class="hover:text-white">Tarifs</a>
                <a href="/annuaire" class="hover:text-white">Annuaire</a>
                <a href="/#questions" class="hover:text-white">Questions</a>
            </div>

            <div class="flex items-center gap-2">
                @if ($demoUrl)
                    <a href="{{ $demoUrl }}" class="hidden rounded-xl px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/25 hover:bg-white/10 sm:inline-flex" rel="noopener" target="_blank">Demander une démo</a>
                @endif
                @if ($signupUrl)
                    <a href="{{ $signupUrl }}" class="btn-brand px-4 py-2 text-sm">Essai gratuit</a>
                @endif
                <details class="relative md:hidden">
                    <summary class="flex cursor-pointer list-none items-center rounded-lg p-2 text-white ring-1 ring-white/25" aria-label="Menu">
                        <x-marketing.icon name="menu" class="size-5" />
                    </summary>
                    <div class="absolute right-0 mt-2 w-56 rounded-2xl bg-white p-2 text-sm font-medium text-slate-700 shadow-xl ring-1 ring-slate-200">
                        <a href="/fonctionnalites" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Fonctionnalités</a>
                        <a href="/#applications" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Applications</a>
                        <a href="/tarifs" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Tarifs</a>
                        <a href="/annuaire" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Annuaire des livreurs</a>
                        <a href="/#questions" class="block rounded-lg px-3 py-2 hover:bg-slate-50">Questions</a>
                        @if ($demoUrl)
                            <a href="{{ $demoUrl }}" class="block rounded-lg px-3 py-2 text-brand-700 hover:bg-brand-50" rel="noopener" target="_blank">Demander une démo</a>
                        @endif
                    </div>
                </details>
            </div>
        </nav>
    </header>

    <main id="contenu">
        @yield('content')
    </main>

    <footer class="bg-slate-950 text-slate-400">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-3">
            <div class="space-y-3">
                <a href="/" class="flex items-center gap-2 text-white">
                    <img src="/marketing/logo.png" alt="" class="size-8" width="32" height="32" loading="lazy">
                    <span class="text-lg font-bold">Jibiat</span>
                </a>
                <p class="text-sm leading-relaxed">Le logiciel des entreprises de livraison : courses, livreurs, caisse, paie et e-commerçants, sur ordinateur et sur téléphone.</p>
            </div>
            <div class="space-y-2 text-sm">
                <p class="font-semibold text-white">Contact</p>
                @if ($whatsappUrl)
                    <a href="{{ $whatsappUrl }}" class="flex items-center gap-2 hover:text-white" rel="noopener" target="_blank">
                        <x-marketing.icon name="whatsapp" class="size-4" /> Nous écrire sur WhatsApp
                    </a>
                @endif
                @if ($contactEmail)
                    <a href="mailto:{{ $contactEmail }}" class="block hover:text-white">{{ $contactEmail }}</a>
                @endif
                <p>{{ config('marketing.legal.address') }}</p>
            </div>
            <div class="space-y-2 text-sm">
                <p class="font-semibold text-white">Liens</p>
                <a href="/fonctionnalites" class="block hover:text-white">Fonctionnalités</a>
                <a href="/tarifs" class="block hover:text-white">Tarifs</a>
                <a href="/annuaire" class="block hover:text-white">Annuaire des entreprises de livraison</a>
                @if ($signupUrl)
                    <a href="{{ $signupUrl }}" class="block hover:text-white">Créer mon entreprise</a>
                @endif
                <a href="/conditions" class="block hover:text-white">Conditions générales</a>
                <a href="/confidentialite" class="block hover:text-white">Confidentialité</a>
            </div>
        </div>
        <div class="border-t border-white/10">
            <p class="mx-auto max-w-6xl px-4 py-6 text-xs sm:px-6">© {{ date('Y') }} {{ config('marketing.legal.name') }}. Tous droits réservés.</p>
        </div>
    </footer>

    @if ($whatsappUrl)
        {{-- Bouton WhatsApp toujours à portée de pouce --}}
        <a href="{{ $demoUrl ?? $whatsappUrl }}" class="fixed bottom-5 right-5 z-50 inline-flex size-14 items-center justify-center rounded-full bg-[#25d366] text-white shadow-xl ring-4 ring-white/70 hover:bg-[#1ebe5b]" rel="noopener" target="_blank" aria-label="Nous écrire sur WhatsApp">
            <x-marketing.icon name="whatsapp" class="size-7" />
        </a>
    @endif
</body>
</html>
