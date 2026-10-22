@extends('marketing.layout')

@section('description', 'Trouvez de nouveaux clients : les marchands de Jibiat Marchand cherchent un livreur, et ils vous trouvent. Jibiat envoie aussi vos courses au bon livreur, vous alerte et fait le point de caisse. Essai gratuit de '.$trialDays.' jours.')

@push('head')
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'Jibiat',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web, Android, iOS',
            'description' => 'Logiciel de gestion pour les entreprises de livraison : courses, livreurs, caisse, paie et e-commerçants.',
            'url' => url('/'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endpush

@section('content')
    {{-- Présentation --}}
    <section class="relative overflow-hidden bg-slate-950 pt-28 text-white sm:pt-32">
        <div class="pointer-events-none absolute -top-40 right-[-10%] size-[36rem] rounded-full bg-brand-600/30 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute bottom-0 left-[-15%] size-[28rem] rounded-full bg-flame/20 blur-3xl" aria-hidden="true"></div>

        <div class="relative mx-auto max-w-6xl px-4 sm:px-6">
            <div class="mx-auto max-w-3xl text-center">
                <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-brand-200 ring-1 ring-white/15">
                    Conçu à Abidjan pour les entreprises de livraison
                </p>
                <h1 class="mt-6 text-4xl font-bold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                    Trouvez des clients.
                    <span class="bg-gradient-to-r from-brand-400 to-flame bg-clip-text text-transparent">Livrez plus. Sans effort.</span>
                </h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg text-pretty text-slate-300">
                    Les marchands de Jibiat Marchand cherchent un livreur : ils vous trouvent et vous envoient leurs colis.
                    Et vos courses partent seules au bon livreur, avec des alertes et un point de caisse en deux minutes.
                </p>
                <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    @if ($signupUrl)
                        <a href="{{ $signupUrl }}" class="btn-brand w-full px-7 py-3.5 text-base sm:w-auto">
                            Essayer gratuitement{{ $trialDays > 0 ? ' '.$trialDays.' jours' : '' }}
                            <x-marketing.icon name="arrow" class="size-5" />
                        </a>
                    @endif
                    @if ($demoUrl)
                        <a href="{{ $demoUrl }}" class="btn-ghost w-full px-7 py-3.5 text-base text-white ring-white/25 hover:bg-white/10 sm:w-auto" rel="noopener" target="_blank">
                            <x-marketing.icon name="whatsapp" class="size-5" /> Demander une démo
                        </a>
                    @endif
                </div>
                <p class="mt-4 text-sm text-slate-400">Sans engagement · Sans carte bancaire · Prêt en 2 minutes</p>
            </div>

            {{-- La vidéo phare, en boucle : la productivité de l'entreprise de livraison --}}
            <x-marketing.loop name="entreprise-productivite" title="Jibiat : des marchands vous trouvent, puis assignation automatique, alertes, points de caisse et suivi en direct" class="mx-auto mt-14 max-w-5xl pb-16" />
        </div>
    </section>

    {{-- L'annuaire : des e-commerçants déjà en ligne trouvent l'entreprise de livraison --}}
    <section id="annuaire" class="scroll-mt-20 bg-slate-950 py-20 text-white">
        <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2">
            <div>
                <p class="font-semibold text-brand-400">De nouveaux clients, sans démarcher</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Trouvez des clients déjà sur Jibiat Marchand</h2>
                <p class="mt-4 text-lg text-slate-300">
                    Une fois sur Jibiat, vous apparaissez dans l'annuaire des entreprises de livraison. Les e-commerçants qui vendent avec
                    Jibiat Marchand y cherchent un livreur fiable : ils vous trouvent, voient vos vrais résultats, vous contactent directement
                    et vous confient leurs colis.
                </p>
                <ul class="mt-8 space-y-5">
                    @foreach ([
                        ['Compris dans toutes les formules', 'Cochez « Figurer dans l\'annuaire » dans vos réglages, ajoutez une phrase de présentation. Vous pouvez vous retirer à tout moment.'],
                        ['Vos vrais résultats, pas des promesses', 'Colis livrés, part de réussite et délai moyen des 90 derniers jours, calculés par l\'application.'],
                        ['Des clients déjà équipés', 'L\'e-commerçant demande à ouvrir un compte ou se connecte en un clic. Vous acceptez, et ses commandes arrivent en courses, sans ressaisie.'],
                    ] as [$title, $text])
                        <li class="flex gap-4">
                            <x-marketing.icon name="check" class="mt-1 size-6 shrink-0 text-brand-400" />
                            <span><span class="block font-semibold">{{ $title }}</span><span class="block text-slate-300">{{ $text }}</span></span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                    @if ($signupUrl)
                        <a href="{{ $signupUrl }}" class="btn-brand px-6 py-3">Figurer dans l'annuaire</a>
                    @endif
                    <a href="/annuaire" class="btn-ghost px-6 py-3 text-white ring-white/25 hover:bg-white/10">Voir l'annuaire</a>
                </div>
            </div>
            <x-marketing.video name="entreprise-nouveaux-clients-v" title="Vos prochains clients sont déjà sur Jibiat Marchand" vertical large />
        </div>
    </section>

    {{-- Productivité : ce que Jibiat fait à la place de votre équipe --}}
    <section id="productivite" class="scroll-mt-20 py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="mx-auto max-w-2xl text-center">
                <p class="font-semibold text-brand-600">Gagnez des heures chaque jour</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Ce que Jibiat fait à la place de votre équipe</h2>
                <p class="mt-4 text-lg text-slate-600">Chaque vidéo montre les vrais écrans de l'application, en moins de 25 secondes.</p>
            </div>
            <div class="mt-14 space-y-16 sm:space-y-24">
                @foreach ([
                    ['entreprise-assignation-auto', 'Assignation automatique', 'Le planning dit qui ramasse et qui livre où. Chaque course part seule au bon livreur ; s\'il est absent ou injoignable, son remplaçant la reçoit.'],
                    ['entreprise-alertes', 'Des alertes au bon moment', 'Ramassage pas commencé avant l\'heure limite, livreur injoignable, course sans livreur, colis en échec ou gardé trop longtemps.'],
                    ['entreprise-points', 'Les points en deux minutes', 'L\'argent chez chaque livreur au franc près. Il verse, vous cochez, c\'est validé, frais et avances déduits.'],
                    ['entreprise-suivi', 'Le suivi en direct', 'Chaque étape notée toute seule, avec la photo et le code de livraison. Le destinataire suit son colis sur WhatsApp.'],
                    ['entreprise-clients-autonomes', 'Vos clients saisissent eux-mêmes', 'Message WhatsApp collé, fichier Excel ou boutique en ligne reliée : votre équipe ne ressaisit plus rien.'],
                    ['entreprise-livreur', 'L\'application des livreurs', 'Missions dans l\'ordre, encaissements et gains, même sans réseau. Vous voyez qui est libre et où.'],
                    ['entreprise-chiffres', 'Vos vrais chiffres', 'Réussite, chiffre d\'affaires, livreurs, clients et zones, et le rapport de la semaine chaque lundi sur WhatsApp.'],
                ] as $i => [$video, $title, $text])
                    <div class="grid items-center gap-8 lg:grid-cols-2 lg:gap-16">
                        <div @class(['lg:order-2' => $i % 2 === 1])>
                            <x-marketing.video :name="$video.'-v'" :title="$title" vertical large />
                        </div>
                        <div class="text-center lg:text-left">
                            <h3 class="text-2xl font-bold tracking-tight text-balance sm:text-3xl">{{ $title }}</h3>
                            <p class="mt-3 text-lg text-slate-600">{{ $text }}</p>
                        </div>
                    </div>
                @endforeach
                <div class="mx-auto max-w-2xl rounded-3xl bg-gradient-to-br from-brand-600 to-flame p-8 text-center text-white">
                    <p class="text-2xl font-bold">Essayez sur votre propre journée</p>
                    <p class="mt-3 text-white/90">Vos zones, vos livreurs, vos clients : tout est prêt en quelques minutes.</p>
                    @if ($signupUrl)
                        <a href="{{ $signupUrl }}" class="mt-6 inline-flex items-center justify-center rounded-xl bg-white px-5 py-3 font-semibold text-brand-700 hover:bg-brand-50">Essayer gratuitement</a>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- Les problèmes du quotidien --}}
    <section class="bg-slate-50 py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-3xl font-bold tracking-tight text-balance sm:text-4xl">Fini les cahiers, les appels et les points à minuit</h2>
                <p class="mt-4 text-lg text-slate-600">Chaque course est suivie de la commande au reversement. Vous savez à tout moment où est chaque colis et chaque franc.</p>
            </div>
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                @foreach ([
                    ['Avant', 'Des courses notées dans des cahiers ou sur WhatsApp, des livreurs qu\'on appelle sans arrêt.', 'Avec Jibiat', 'Le dispatch voit toutes les courses et assigne les livreurs en un clic ; chaque livreur reçoit ses missions sur son téléphone.'],
                    ['Avant', 'Le point de caisse du soir à la main, des écarts difficiles à expliquer.', 'Avec Jibiat', 'Chaque encaissement est enregistré à la livraison : la caisse attend le bon montant de chaque livreur.'],
                    ['Avant', 'Des e-commerçants qui appellent pour savoir où est leur colis et quand ils seront payés.', 'Avec Jibiat', 'Ils suivent leurs courses en direct, reçoivent une notification à chaque étape et voient leurs reversements.'],
                ] as [$before, $pain, $after, $gain])
                    <div class="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-slate-200">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $before }}</p>
                        <p class="mt-2 text-slate-500">{{ $pain }}</p>
                        <p class="mt-6 text-xs font-semibold uppercase tracking-wider text-brand-600">{{ $after }}</p>
                        <p class="mt-2 font-medium text-slate-800">{{ $gain }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>


    {{-- Démonstration en vidéo --}}
    <section id="video" class="scroll-mt-20 py-20">
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
            <div class="mx-auto max-w-2xl text-center">
                <p class="font-semibold text-brand-600">Voir Jibiat en action</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Une course de A à Z, en une minute</h2>
                <p class="mt-4 text-lg text-slate-600">L'e-commerçant crée la course, l'agence assigne le livreur, le livreur livre et encaisse, la caisse reçoit l'argent et l'e-commerçant est payé.</p>
            </div>
            <x-marketing.video name="course-de-a-a-z" title="Une course de A à Z" class="mt-10" />
            <p class="mt-6 text-center"><a href="/fonctionnalites" class="font-semibold text-brand-600 hover:text-brand-700">Toutes les vidéos sur la page Fonctionnalités →</a></p>
        </div>
    </section>

    {{-- Les trois applications --}}
    <section id="applications" class="scroll-mt-20 py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="mx-auto max-w-2xl text-center">
                <p class="font-semibold text-brand-600">3 applications, une seule plateforme</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Chacun a son espace, tout le monde voit la même information</h2>
            </div>

            <div class="mt-16 space-y-20">
                {{-- Bureau --}}
                <div class="grid items-center gap-10 lg:grid-cols-2">
                    <div>
                        <span class="inline-flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><x-marketing.icon name="monitor" /></span>
                        <h3 class="mt-4 text-2xl font-bold">Le bureau : piloter la journée</h3>
                        <p class="mt-3 text-slate-600">Gérant, dispatch et caissier travaillent sur le même tableau, depuis un ordinateur ou une tablette.</p>
                        <ul class="mt-6 space-y-3 text-slate-700">
                            @foreach ([
                                'Toutes les courses du jour, filtrées par statut : à valider, à ramasser, en route, incidents',
                                'Assignation des livreurs, carte en direct et alertes des courses en retard',
                                'Caisse : versements des livreurs, reversements aux e-commerçants, avances et frais',
                                'Paie des livreurs calculée automatiquement selon vos règles',
                            ] as $line)
                                <li class="flex gap-3"><x-marketing.icon name="check" class="mt-0.5 size-5 shrink-0 text-brand-600" /><span>{{ $line }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="overflow-hidden rounded-2xl shadow-xl ring-1 ring-slate-200">
                        <img src="/marketing/app-courses.webp" alt="Liste des courses dans le bureau : statut, livreur, montant à encaisser" class="block w-full" width="1440" height="900" loading="lazy">
                    </div>
                </div>

                {{-- Livreur et e-commerçant --}}
                <div class="grid gap-10 lg:grid-cols-2">
                    @foreach ([
                        ['bike', 'L\'application livreur', 'Ses missions du jour, l\'adresse et le contact du client, le code de livraison, l\'encaissement et ses gains. Même téléphone en veille, il est prévenu des nouvelles missions.', '/marketing/app-livreur.webp', 'Application livreur : missions à ramasser et à livrer'],
                        ['store', 'L\'application e-commerçant', 'Il crée ses courses en quelques secondes, suit ses colis en direct, consulte ses statistiques et ses reversements, et partage sa boutique en ligne.', '/marketing/app-ecommercant.webp', 'Application e-commerçant : courses du jour et colis à traiter'],
                    ] as [$icon, $title, $text, $image, $alt])
                        <div class="flex flex-col overflow-hidden rounded-3xl bg-slate-50 ring-1 ring-slate-200 sm:flex-row lg:flex-col xl:flex-row">
                            <div class="flex-1 p-7">
                                <span class="inline-flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><x-marketing.icon :name="$icon" /></span>
                                <h3 class="mt-4 text-xl font-bold">{{ $title }}</h3>
                                <p class="mt-3 text-slate-600">{{ $text }}</p>
                                <p class="mt-4 text-sm text-slate-500">S'installe sur Android et iPhone depuis le navigateur, sans passer par un magasin d'applications.</p>
                            </div>
                            <div class="flex justify-center px-7 pt-2 sm:items-end sm:pt-7 lg:pt-0 xl:pt-7">
                                <img src="{{ $image }}" alt="{{ $alt }}" class="w-48 rounded-t-[1.5rem] border-[5px] border-b-0 border-slate-900 shadow-lg" width="390" height="844" loading="lazy">
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- Fonctionnalités --}}
    <section id="fonctionnalites" class="scroll-mt-20 bg-slate-950 py-20 text-white">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="mx-auto max-w-2xl text-center">
                <p class="font-semibold text-brand-400">Fonctionnalités</p>
                <h2 class="mt-2 text-3xl font-bold tracking-tight text-balance sm:text-4xl">Tout ce qu'il faut pour livrer plus, avec moins d'erreurs</h2>
            </div>
            <div class="mt-14 grid gap-px overflow-hidden rounded-3xl bg-white/10 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['route', 'Dispatch et carte', 'Toutes les courses du jour par statut, les livreurs sur la carte, les ramassages groupés par client.'],
                    ['bell', 'Échecs, reports et retours', 'Chaque échec à décider d\'un toucher, les reports rappelés le bon jour, les retours groupés par e-commerçant.'],
                    ['wallet', 'Caisse et reversements', 'Versements des livreurs, reversements aux e-commerçants avec relevés, colis perdus et indemnités.'],
                    ['payroll', 'Paie des livreurs', 'Commission, salaire, primes d\'objectifs, frais, avances et retenues : la fiche se calcule toute seule.'],
                    ['message', 'Commandes par WhatsApp', 'Vos e-commerçants envoient leurs courses à votre numéro WhatsApp, l\'application les enregistre.'],
                    ['chart', 'Statistiques et rapports', 'Taux de livraison, motifs d\'échec, livreurs, zones, chiffre d\'affaires, exports Excel.'],
                    ['box', 'Stock et entrepôt', 'Les produits de vos e-commerçants chez vous : entrées, sorties et commandes préparées.'],
                    ['plug', 'API, import et boutiques', 'Sites de vos clients reliés, import de centaines de courses, page de commande pour chaque e-commerçant.'],
                    ['store', 'À votre nom', 'Votre logo, vos couleurs et votre propre adresse : vos clients voient votre entreprise.'],
                ] as [$icon, $title, $text])
                    <div class="bg-slate-950 p-7">
                        <span class="inline-flex size-11 items-center justify-center rounded-xl bg-brand-500/15 text-brand-400"><x-marketing.icon :name="$icon" /></span>
                        <h3 class="mt-4 font-semibold">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-400">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
            <div class="mt-10 flex flex-col items-center gap-3">
                <a href="/fonctionnalites" class="btn-ghost text-white ring-white/25 hover:bg-white/10">Voir toutes les fonctionnalités <x-marketing.icon name="arrow" class="size-5" /></a>
                <p class="text-sm text-slate-400">Certaines fonctions dépendent de la formule choisie. <a href="/tarifs" class="font-medium text-brand-400 hover:text-brand-300">Voir les tarifs</a></p>
            </div>
        </div>
    </section>

    {{-- Démarrage --}}
    <section class="py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">Opérationnel dès aujourd'hui</h2>
                <p class="mt-4 text-lg text-slate-600">Pas d'installation, pas de matériel : un téléphone et une connexion suffisent.</p>
            </div>
            <ol class="mt-12 grid gap-6 md:grid-cols-3">
                @foreach ([
                    ['Créez votre espace', 'En 2 minutes, avec l\'adresse de votre entreprise : '.'votre-entreprise.'.$domain.'.'],
                    ['Ajoutez vos zones et vos livreurs', 'Communes, tarifs de livraison, comptes des livreurs. Nous vous aidons à tout paramétrer.'],
                    ['Invitez vos e-commerçants', 'Ils s\'inscrivent avec leur numéro et créent leurs premières courses dans la journée.'],
                ] as $i => [$title, $text])
                    <li class="rounded-3xl p-7 ring-1 ring-slate-200">
                        <span class="flex size-10 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-flame font-bold text-white">{{ $i + 1 }}</span>
                        <h3 class="mt-5 text-lg font-semibold">{{ $title }}</h3>
                        <p class="mt-2 text-slate-600">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Témoignages --}}
    @if ($testimonials)
        <section class="bg-brand-50 py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <h2 class="text-center text-3xl font-bold tracking-tight sm:text-4xl">Ils livrent avec Jibiat</h2>
                <div class="mx-auto mt-12 grid max-w-4xl gap-6 md:grid-cols-2">
                    @foreach ($testimonials as $t)
                        <figure class="rounded-3xl bg-white p-8 shadow-sm ring-1 ring-brand-100">
                            <x-marketing.icon name="quote" class="size-8 text-brand-300" />
                            <blockquote class="mt-4 text-lg font-medium text-slate-800">« {{ $t['quote'] }} »</blockquote>
                            <figcaption class="mt-6 flex items-center gap-3">
                                <span class="flex size-10 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-flame font-bold text-white">{{ mb_substr($t['author'], 0, 1) }}</span>
                                <span><span class="block font-semibold">{{ $t['author'] }}</span><span class="text-sm text-slate-500">Entreprise de livraison</span></span>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Tarifs --}}
    <section class="bg-slate-50 py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="mx-auto mb-12 max-w-2xl text-center">
                <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">Des tarifs simples</h2>
                <p class="mt-4 text-lg text-slate-600">Un abonnement mensuel selon votre activité.{{ $trialDays > 0 ? ' '.$trialDays.' jours d\'essai gratuit pour commencer.' : '' }}</p>
            </div>
            @include('marketing.partials.plans')
            <p class="mt-8 text-center font-medium text-brand-800">Dans toutes les formules : les marchands de Jibiat Marchand vous trouvent dans l'annuaire.</p>
            <p class="mt-3 text-center"><a href="/tarifs" class="font-semibold text-brand-700 hover:text-brand-600">Comparer les formules en détail →</a></p>
        </div>
    </section>

    {{-- Questions --}}
    <section id="questions" class="scroll-mt-20 py-20">
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <h2 class="text-center text-3xl font-bold tracking-tight sm:text-4xl">Questions fréquentes</h2>
            <div class="mt-10 divide-y divide-slate-200 rounded-3xl ring-1 ring-slate-200">
                @foreach ([
                    ['Comment Jibiat m\'aide-t-il à trouver des clients ?', 'Les marchands qui vendent avec Jibiat Marchand, et les visiteurs de '.$domain.'/annuaire, cherchent une entreprise de livraison dans l\'annuaire. Ils voient vos villes, votre prix de départ et vos vrais résultats, puis vous demandent d\'ouvrir un compte ou se connectent à vous en un clic : leurs commandes arrivent chez vous en courses.'],
                    ['Faut-il installer quelque chose ?', 'Non. Jibiat fonctionne dans le navigateur. Vos livreurs et vos e-commerçants peuvent l\'ajouter à l\'écran d\'accueil de leur téléphone (Android ou iPhone) comme une application, sans passer par un magasin d\'applications.'],
                    ['Que se passe-t-il à la fin de l\'essai ?', 'Vous choisissez de continuer avec la formule qui vous convient. Vos données, vos e-commerçants et vos livreurs restent en place.'],
                    ['J\'utilise l\'ancienne version de notre logiciel : puis-je garder mes e-commerçants ?', 'Oui. Nous reprenons vos e-commerçants et vos livreurs : ils se connectent avec le même numéro et le même mot de passe.'],
                    ['Les messages aux clients coûtent-ils cher ?', 'Les e-commerçants et les livreurs reçoivent des notifications gratuites sur leur téléphone. Les messages WhatsApp aux destinataires des colis passent par votre propre numéro WhatsApp Business.'],
                    ['Mes données sont-elles protégées ?', 'Chaque entreprise a son espace séparé, avec une connexion chiffrée et des accès limités selon le rôle de chacun. Les données d\'une entreprise ne sont jamais visibles par une autre.'],
                    ['Je suis e-commerçant ou livreur : comment me connecter ?', 'Ouvrez l\'adresse que vous a donnée votre entreprise de livraison, par exemple votre-entreprise.'.$domain.'.'],
                    ['L\'annuaire est-il payant ?', 'Non. Il est compris dans toutes les formules : il suffit de cocher « Figurer dans l\'annuaire » dans Réglages › E-commerçants.'],
                ] as [$question, $answer])
                    <details class="group p-6">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold">
                            {{ $question }}
                            <span class="text-2xl leading-none text-brand-600 transition group-open:rotate-45" aria-hidden="true">+</span>
                        </summary>
                        <p class="mt-3 text-slate-600">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Appel final --}}
    <section class="px-4 pb-20 sm:px-6">
        <div class="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-gradient-to-br from-brand-600 to-flame px-6 py-14 text-center text-white sm:px-12">
            <img src="/marketing/logo.png" alt="" class="pointer-events-none absolute -right-10 -bottom-16 size-72 opacity-15 brightness-0 invert" loading="lazy">
            <h2 class="relative text-3xl font-bold tracking-tight text-balance sm:text-4xl">Prêt à livrer plus sereinement ?</h2>
            <p class="relative mx-auto mt-4 max-w-xl text-lg text-white/90">Créez votre espace en 2 minutes, ou parlons de votre entreprise sur WhatsApp.</p>
            <div class="relative mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                @if ($signupUrl)
                    <a href="{{ $signupUrl }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-7 py-3.5 font-semibold text-brand-700 shadow-lg hover:bg-brand-50">Commencer l'essai gratuit</a>
                @endif
                @if ($demoUrl)
                    <a href="{{ $demoUrl }}" class="inline-flex items-center justify-center gap-2 rounded-xl px-7 py-3.5 font-semibold text-white ring-1 ring-white/50 hover:bg-white/10" rel="noopener" target="_blank">
                        <x-marketing.icon name="whatsapp" class="size-5" /> Nous écrire sur WhatsApp
                    </a>
                @endif
            </div>
        </div>
    </section>
@endsection
