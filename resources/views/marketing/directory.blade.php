@extends('marketing.layout')

@section('title', 'Annuaire des entreprises de livraison · Jibiat')
@section('description', 'Trouvez une entreprise de livraison fiable en Côte d\'Ivoire : villes couvertes, prix de départ, livraisons réussies et délai moyen réels des 90 derniers jours. Connexion en un clic depuis Jibiat Marchand.')

@section('content')
    <section class="relative overflow-hidden bg-slate-950 pt-32 pb-20 text-white">
        <div class="pointer-events-none absolute -top-40 right-[-10%] size-[32rem] rounded-full bg-brand-600/30 blur-3xl" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-3xl px-4 text-center sm:px-6">
            <p class="inline-block rounded-full bg-white/10 px-3 py-1 text-sm font-medium text-brand-200">Pour les e-commerçants</p>
            <h1 class="mt-5 text-4xl font-bold tracking-tight sm:text-5xl">Trouvez une entreprise de livraison fiable</h1>
            <p class="mt-5 text-lg text-slate-300">
                Des entreprises qui livrent avec Jibiat, avec leurs <strong class="text-white">vrais résultats</strong> :
                livraisons réussies et délai moyen des 90 derniers jours, calculés par l'application, pas déclarés.
            </p>
        </div>
    </section>

    <section class="py-14">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            @if (count($companies) === 0)
                <div class="mx-auto max-w-xl rounded-3xl bg-slate-50 p-8 text-center">
                    <p class="text-lg font-semibold">L'annuaire ouvre bientôt.</p>
                    <p class="mt-2 text-slate-600">Les premières entreprises de livraison s'y inscrivent en ce moment.</p>
                </div>
            @else
                <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($companies as $c)
                        <li class="flex flex-col rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                            <div class="flex items-center gap-3">
                                @if ($c['logo_url'])
                                    <img src="{{ $c['logo_url'] }}" alt="" class="size-12 rounded-xl object-contain" width="48" height="48" loading="lazy">
                                @else
                                    <span class="grid size-12 place-items-center rounded-xl bg-brand-600 text-lg font-bold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr($c['name'], 0, 1)) }}</span>
                                @endif
                                <div class="min-w-0">
                                    <h2 class="truncate text-lg font-bold">{{ $c['name'] }}</h2>
                                    <p class="truncate text-sm text-slate-500">{{ implode(', ', $c['cities']) ?: 'Côte d\'Ivoire' }}@if ($c['ships_inland']) · expédition vers l'intérieur @endif</p>
                                </div>
                            </div>

                            @if ($c['pitch'])
                                <p class="mt-4 text-slate-700">{{ $c['pitch'] }}</p>
                            @endif

                            <dl class="mt-5 grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-2xl bg-slate-50 p-3">
                                    <dt class="text-xs text-slate-500">Réussite</dt>
                                    <dd class="mt-1 text-lg font-bold">{{ $c['stats']['success_rate'] !== null ? round($c['stats']['success_rate'] * 100).' %' : '—' }}</dd>
                                </div>
                                <div class="rounded-2xl bg-slate-50 p-3">
                                    <dt class="text-xs text-slate-500">Délai moyen</dt>
                                    <dd class="mt-1 text-lg font-bold">{{ $c['stats']['average_delivery_hours'] !== null ? (int) round($c['stats']['average_delivery_hours']).' h' : '—' }}</dd>
                                </div>
                                <div class="rounded-2xl bg-slate-50 p-3">
                                    <dt class="text-xs text-slate-500">Dès</dt>
                                    <dd class="mt-1 text-lg font-bold">{{ $c['price_from'] !== null ? number_format($c['price_from'], 0, ',', ' ').' F' : '—' }}</dd>
                                </div>
                            </dl>
                            <p class="mt-2 text-xs text-slate-500">
                                {{ number_format($c['stats']['deliveries_90d'], 0, ',', ' ') }} colis livrés en 90 jours
                                @if ($c['stats']['success_rate'] === null) · chiffres affichés dès 20 courses @endif
                            </p>

                            <div class="mt-auto pt-6">
                                <a href="{{ $c['accepts'] === 'signup' ? $c['url'].'/inscription' : $c['url'].'/' }}" class="block rounded-full bg-brand-600 px-5 py-3 text-center font-semibold text-white hover:bg-brand-700">
                                    {{ $c['accepts'] === 'signup' ? 'Ouvrir mon compte' : 'Contacter cette entreprise' }}
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    <section class="bg-slate-50 py-16">
        <div class="mx-auto grid max-w-6xl gap-6 px-4 sm:px-6 md:grid-cols-2">
            <div class="rounded-3xl bg-white p-8 ring-1 ring-slate-200">
                <p class="text-3xl" aria-hidden="true">🛍️</p>
                <h2 class="mt-3 text-2xl font-bold">Vous vendez avec Jibiat Marchand ?</h2>
                <p class="mt-3 text-slate-600">
                    Dans Gestion › Livraisons › Entreprises de livraison, choisissez une entreprise de cet annuaire et touchez « Connecter » :
                    vos commandes partent chez elle en un clic, vous suivez chaque colis en direct et vous contrôlez vos reversements.
                </p>
                <a href="https://jibiat-marchand.com" class="mt-5 inline-block font-semibold text-brand-700 hover:underline">Découvrir Jibiat Marchand →</a>
            </div>
            <div class="rounded-3xl bg-slate-950 p-8 text-white">
                <p class="text-3xl" aria-hidden="true">🛵</p>
                <h2 class="mt-3 text-2xl font-bold">Vous êtes une entreprise de livraison ?</h2>
                <p class="mt-3 text-slate-300">
                    Figurez dans l'annuaire : les e-commerçants vous trouvent, voient vos résultats et vous envoient leurs colis.
                    Leurs demandes d'ouverture de compte arrivent directement dans votre espace.
                </p>
                @if ($signupUrl)
                    <a href="{{ $signupUrl }}" class="mt-5 inline-block rounded-full bg-brand-600 px-5 py-3 font-semibold hover:bg-brand-700">Essai gratuit {{ $trialDays }} jours</a>
                @elseif ($demoUrl)
                    <a href="{{ $demoUrl }}" class="mt-5 inline-block rounded-full bg-brand-600 px-5 py-3 font-semibold hover:bg-brand-700">Nous écrire sur WhatsApp</a>
                @endif
            </div>
        </div>
    </section>
@endsection
