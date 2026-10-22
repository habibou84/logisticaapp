@extends('marketing.layout')

@section('title', 'Tarifs · Jibiat, logiciel des entreprises de livraison')
@section('description', 'Abonnement mensuel selon votre activité : courses incluses, livreurs, e-commerçants illimités. Essai gratuit de '.$trialDays.' jours, sans carte bancaire.')

@section('content')
    <section class="relative overflow-hidden bg-slate-950 pt-32 pb-40 text-white">
        <div class="pointer-events-none absolute -top-40 right-[-10%] size-[32rem] rounded-full bg-brand-600/30 blur-3xl" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-3xl px-4 text-center sm:px-6">
            <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">Des tarifs simples, selon votre activité</h1>
            <p class="mt-5 text-lg text-slate-300">
                Toutes les formules comprennent le bureau, l'application livreur, l'application e-commerçant, la caisse et la paie des livreurs.
                @if ($trialDays > 0) Commencez par {{ $trialDays }} jours d'essai gratuit, sans carte bancaire. @endif
            </p>
        </div>
    </section>

    <section class="-mt-28 pb-20">
        <div class="relative mx-auto max-w-6xl px-4 sm:px-6">
            @include('marketing.partials.plans')
        </div>
    </section>

    <section class="bg-slate-50 py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <h2 class="text-center text-2xl font-bold tracking-tight sm:text-3xl">Inclus dans toutes les formules</h2>
            <ul class="mx-auto mt-10 grid max-w-4xl gap-x-10 gap-y-4 text-slate-700 sm:grid-cols-2">
                @foreach ([
                    'Courses, dispatch et carte des livreurs en direct',
                    'Application livreur : missions, encaissement, gains',
                    'Application e-commerçant : courses, suivi, reversements',
                    'Caisse : versements des livreurs, avances et frais',
                    'Reversements aux e-commerçants avec relevés',
                    'Paie des livreurs (commission, salaire, primes)',
                    'Retours, reports et colis perdus suivis jusqu\'au bout',
                    'Statistiques de livraison et des livreurs',
                    'Notifications gratuites aux e-commerçants et aux livreurs',
                    'Lien de suivi du colis pour vos clients',
                    'Import des courses depuis Excel',
                    'Assistance par WhatsApp',
                ] as $line)
                    <li class="flex gap-3"><x-marketing.icon name="check" class="mt-0.5 size-5 shrink-0 text-brand-600" /><span>{{ $line }}</span></li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="py-20">
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <h2 class="text-center text-2xl font-bold tracking-tight sm:text-3xl">Questions sur l'abonnement</h2>
            <div class="mt-10 divide-y divide-slate-200 rounded-3xl ring-1 ring-slate-200">
                @foreach (array_filter([
                    $trialDays > 0 ? ['Comment fonctionne l\'essai gratuit ?', 'Vous créez votre espace et utilisez toutes les fonctions de la formule choisie pendant '.$trialDays.' jours. Aucun paiement n\'est demandé à l\'inscription.'] : null,
                    ['Que se passe-t-il si je dépasse les courses incluses ?', 'Rien ne s\'arrête : chaque course supplémentaire est facturée au tarif indiqué sur votre formule, à la fin du mois.'],
                    ['Puis-je changer de formule ?', 'Oui, à tout moment, selon l\'évolution de votre activité. Écrivez-nous et nous faisons le changement.'],
                    ['Comment se passe la facturation ?', 'Une facture par mois, consultable dans votre espace (menu Mon abonnement).'],
                    ['Les messages WhatsApp sont-ils compris ?', 'Les notifications aux e-commerçants et aux livreurs sont gratuites. Les messages WhatsApp aux destinataires passent par votre numéro WhatsApp Business : Meta vous les facture directement, quelques francs par message.'],
                ]) as [$question, $answer])
                    <details class="group p-6">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold">
                            {{ $question }}
                            <span class="text-2xl leading-none text-brand-600 transition group-open:rotate-45" aria-hidden="true">+</span>
                        </summary>
                        <p class="mt-3 text-slate-600">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
            @if ($demoUrl)
                <p class="mt-10 text-center text-slate-600">Une autre question ?
                    <a href="{{ $demoUrl }}" class="font-semibold text-brand-700 hover:text-brand-600" rel="noopener" target="_blank">Écrivez-nous sur WhatsApp</a></p>
            @endif
        </div>
    </section>
@endsection
