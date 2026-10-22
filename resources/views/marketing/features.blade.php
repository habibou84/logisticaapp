@extends('marketing.layout')

@section('title', 'Fonctionnalités · Jibiat, logiciel des entreprises de livraison')
@section('description', 'Dispatch, suivi en direct, caisse, reversements, paie des livreurs, applications livreur et e-commerçant, commandes WhatsApp, statistiques, stock et API : tout Jibiat en détail.')

@php
    // Domaines fonctionnels : ancre, icône, titre, accroche, points, capture ou vidéo éventuelle, module selon formule
    $sections = [
        [
            'id' => 'courses', 'icon' => 'route', 'title' => 'Courses et dispatch',
            'intro' => 'De la commande à la livraison, chaque course suit un parcours clair et le dispatch sait toujours quoi faire ensuite.',
            'points' => [
                'Files de travail : à valider, à ramasser, à préparer, à livrer, incidents, retours',
                'Assignation du ramassage et de la livraison en un clic, même à deux livreurs différents',
                'Courses sans livreur signalées, avec une heure limite du jour et des alertes en cas de retard',
                'Tarif calculé automatiquement selon les zones de départ et d\'arrivée',
                'Échecs de livraison : relivrer à une date, retourner au marchand ou remettre en stock, en un clic',
                'Courses reportées rappelées le matin du jour prévu',
                'Bons de retour groupés par e-commerçant, signés à la remise',
                'Expéditions vers l\'intérieur du pays : dépôt à la gare, transporteur et ticket suivis',
                'Création rapide au bureau, par import Excel ou par les e-commerçants eux-mêmes',
            ],
            'image' => '/marketing/app-courses.webp', 'alt' => 'Liste des courses : statuts, trajets, livreurs et montants à encaisser',
            'video' => ['course-de-a-a-z', 'Une course de A à Z'],
        ],
        [
            'id' => 'suivi', 'icon' => 'map', 'title' => 'Suivi en direct des livreurs',
            'intro' => 'Voyez votre flotte sur la carte et gardez le contact avec vos livreurs sans passer vos journées au téléphone.',
            'points' => [
                'Carte des livreurs en direct : position, missions en cours, livreurs disponibles',
                'Trajet du jour de chaque livreur : étapes des courses, distance, heures de service',
                'Ramassages sans livreur affichés sur la carte pour décider vite',
                'Remontées terrain : notes et problèmes signalés par les livreurs, avec alerte au bureau',
                'Consignes du bureau au livreur, avec accusés « lu » et « compris »',
                'Colis restés trop longtemps chez un livreur repérés automatiquement',
            ],
        ],
        [
            'id' => 'caisse', 'icon' => 'wallet', 'title' => 'Caisse et reversements',
            'intro' => 'Le paiement à la livraison est enregistré au moment où il a lieu : vos points de caisse se font en quelques minutes, sans écart inexpliqué.',
            'points' => [
                'Montant attendu de chaque livreur, calculé à partir de ses livraisons',
                'Point de caisse du livreur avec les colis rendus, écarts signalés',
                'Avances aux livreurs et frais de mission (carburant, transport) déduits au bon moment',
                'Reversements aux e-commerçants avec relevé détaillé course par course',
                'Frais de livraison retenus ou payés par le client, selon chaque e-commerçant',
                'Indemnité au marchand et retenue au livreur en cas de colis perdu',
                'Grand livre de chaque e-commerçant : ce qui lui est dû, à tout moment',
            ],
            'image' => '/marketing/app-caisse.webp', 'alt' => 'Caisse : argent chez les livreurs, versements et reversements',
            'video' => ['caisse', 'Le point de caisse en 2 minutes'],
        ],
        [
            'id' => 'paie', 'icon' => 'payroll', 'title' => 'Paie des livreurs',
            'intro' => 'Fini les calculs à la main en fin de mois : la paie suit vos règles et se prépare toute seule.',
            'points' => [
                'Rémunération à la course, au salaire fixe, à la journée ou à l\'heure, ou un mélange',
                'Primes d\'objectifs (nombre de livraisons, taux de réussite)',
                'Avances et retenues déduites, avec un plafond pour protéger le livreur',
                'Périodes de paie et fiches générées automatiquement',
                'Simulateur pour tester une nouvelle formule de rémunération avant de l\'appliquer',
                'Gains visibles par le livreur dans son application',
            ],
        ],
        [
            'id' => 'livreur', 'icon' => 'bike', 'title' => 'Application livreur',
            'intro' => 'Une application simple, pensée pour la moto : de grosses touches, l\'essentiel à l\'écran, et ça marche même avec une connexion faible.',
            'points' => [
                'Bouton « Je suis en service » et missions du jour : à ramasser, à livrer, retours',
                'Notification sur le téléphone à chaque nouvelle mission, même écran éteint',
                'Adresse, repère et téléphone du client en un geste',
                'Code de livraison et photo comme preuve de remise',
                'Encaissement, frais de mission et versement à la caisse',
                'Ses gains et ses avances, à jour',
            ],
            'image' => '/marketing/app-livreur.webp', 'alt' => 'Application livreur : missions à ramasser et à livrer', 'phone' => true,
            'video' => ['livreur', 'La journée du livreur'],
        ],
        [
            'id' => 'ecommercant', 'icon' => 'store', 'title' => 'Application e-commerçant',
            'intro' => 'Vos clients e-commerçants deviennent autonomes : ils créent leurs courses, suivent leurs colis et consultent leur argent sans vous appeler.',
            'points' => [
                'Inscription en ligne avec leur numéro de téléphone',
                'Nouvelle course en quelques étapes, avec le tarif affiché à l\'avance',
                'Suivi en direct et colis « à traiter » (client injoignable, report…)',
                'Reversements et relevés consultables à tout moment',
                'Statistiques : taux de livraison, motifs d\'échec, communes livrées',
                'Notifications gratuites sur leur téléphone à chaque étape',
                'Position de ramassage enregistrée pour des livreurs plus rapides',
            ],
            'image' => '/marketing/app-ecommercant.webp', 'alt' => 'Application e-commerçant : courses du jour et colis à traiter', 'phone' => true,
            'video' => ['ecommercant', 'L\'e-commerçant crée sa course'],
        ],
        [
            'id' => 'whatsapp', 'icon' => 'message', 'title' => 'WhatsApp et notifications',
            'intro' => 'Les bonnes personnes sont prévenues au bon moment, par le canal le moins cher.',
            'points' => [
                'Destinataire prévenu quand le colis part : montant à payer, code de livraison, lien de suivi',
                'E-commerçants prévenus par notification gratuite, WhatsApp seulement en secours ; livreurs prévenus de leurs missions',
                'SMS de secours si le message WhatsApp n\'arrive pas',
                'Commandes par WhatsApp : l\'e-commerçant écrit, la course est créée',
                'Point quotidien ou hebdomadaire envoyé à chaque e-commerçant',
                'Point de la semaine envoyé au gérant chaque lundi matin',
                'Votre propre numéro WhatsApp Business, à votre nom',
            ],
            'module' => 'Commandes par WhatsApp selon la formule',
            'phone' => true, 'video' => ['whatsapp', 'Les commandes par WhatsApp'],
        ],
        [
            'id' => 'statistiques', 'icon' => 'chart', 'title' => 'Statistiques',
            'intro' => 'Pilotez avec des chiffres fiables plutôt qu\'à l\'intuition.',
            'points' => [
                'Courses créées et livrées, taux de livraison, motifs d\'échec',
                'Heures de pointe pour organiser vos équipes',
                'Classement des livreurs : livraisons, réussite, délais',
                'E-commerçants les plus actifs et ceux à relancer',
                'Analyse par zone et finances : revenus, dépenses, marge',
                'Export des tableaux vers Excel',
            ],
            'image' => '/marketing/app-statistiques.webp', 'alt' => 'Statistiques : courses par jour, taux de livraison, chiffre d\'affaires',
            'video' => ['statistiques', 'Vos statistiques'],
            'module' => 'Zones, finances et export selon la formule',
        ],
        [
            'id' => 'stock', 'icon' => 'box', 'title' => 'Stock et boutiques en ligne',
            'intro' => 'Allez plus loin que la livraison : stockez les produits de vos e-commerçants et donnez-leur une vitrine.',
            'points' => [
                'Produits des e-commerçants stockés à votre entrepôt, entrées et sorties suivies',
                'Commandes préparées depuis le stock, stock remis à jour à la livraison',
                'Facturation du stockage',
                'Boutique en ligne de chaque e-commerçant : catalogue, panier et commande',
                'Les commandes de la boutique arrivent directement en courses',
            ],
            'module' => 'Selon la formule',
        ],
        [
            'id' => 'integrations', 'icon' => 'plug', 'title' => 'API et intégrations',
            'intro' => 'Reliez les sites de vos plus gros e-commerçants pour qu\'ils n\'aient plus rien à saisir.',
            'points' => [
                'API documentée pour créer et suivre les courses depuis un site (WooCommerce…)',
                'Webhooks signés : le site de l\'e-commerçant est prévenu à chaque changement de statut',
                'Zone indiquée par son nom, sans connaître les identifiants',
                'Import de centaines de courses depuis un fichier Excel ou CSV',
            ],
            'module' => 'API selon la formule',
        ],
        [
            'id' => 'marque', 'icon' => 'globe', 'title' => 'Votre marque et votre équipe',
            'intro' => 'Vos clients voient votre entreprise, pas la nôtre.',
            'points' => [
                'Votre logo et votre couleur sur les applications et les pages de suivi',
                'Votre adresse : votre-entreprise.'.$domain.', ou votre propre domaine',
                'Rôles et droits : administrateur, dispatch, caissier, agent de dépôt',
                'Données de chaque entreprise strictement séparées, connexion chiffrée',
                'Applications installables sur Android et iPhone, sans magasin d\'applications',
            ],
            'module' => 'Domaine personnalisé selon la formule',
        ],
    ];
@endphp

@section('content')
    <section class="relative overflow-hidden bg-slate-950 pt-32 pb-16 text-white">
        <div class="pointer-events-none absolute -top-40 right-[-10%] size-[32rem] rounded-full bg-brand-600/30 blur-3xl" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-3xl px-4 text-center sm:px-6">
            <p class="font-semibold text-brand-400">Fonctionnalités</p>
            <h1 class="mt-2 text-4xl font-bold tracking-tight text-balance sm:text-5xl">Tout ce dont votre entreprise de livraison a besoin</h1>
            <p class="mt-5 text-lg text-slate-300">Du dispatch à la paie des livreurs, en passant par la caisse et les e-commerçants : chaque étape est couverte, et chaque information n'est saisie qu'une fois.</p>
        </div>
    </section>

    {{-- Sommaire, reste visible en haut de l'écran --}}
    <nav class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur" aria-label="Sommaire">
        <div class="mx-auto flex max-w-6xl gap-2 overflow-x-auto px-4 py-3 sm:px-6 [scrollbar-width:none]">
            @foreach ($sections as $s)
                <a href="#{{ $s['id'] }}" class="shrink-0 rounded-full px-3 py-1.5 text-sm font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-brand-50 hover:text-brand-700 hover:ring-brand-200">{{ $s['title'] }}</a>
            @endforeach
        </div>
    </nav>

    <div class="mx-auto max-w-6xl divide-y divide-slate-200 px-4 sm:px-6">
        @foreach ($sections as $i => $s)
            <section id="{{ $s['id'] }}" class="scroll-mt-20 py-16">
                <div @class(['grid items-start gap-10', 'lg:grid-cols-2' => (isset($s['image']) || isset($s['video'])) && ! isset($s['phone']), 'lg:grid-cols-[1fr_auto]' => isset($s['phone'])])>
                    <div @class(['lg:order-2' => (isset($s['image']) || isset($s['video'])) && ! isset($s['phone']) && $i % 2 === 1])>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="inline-flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><x-marketing.icon :name="$s['icon']" /></span>
                            @isset($s['module'])
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">{{ $s['module'] }}</span>
                            @endisset
                        </div>
                        <h2 class="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">{{ $s['title'] }}</h2>
                        <p class="mt-3 text-lg text-slate-600">{{ $s['intro'] }}</p>
                        <ul @class(['mt-6 grid gap-x-8 gap-y-3 text-slate-700', 'sm:grid-cols-2' => ! isset($s['image']) && ! isset($s['video'])])>
                            @foreach ($s['points'] as $point)
                                <li class="flex gap-3"><x-marketing.icon name="check" class="mt-0.5 size-5 shrink-0 text-brand-600" /><span>{{ $point }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                    @if (isset($s['video']))
                        <div @class(['lg:pl-6' => isset($s['phone']), 'lg:order-1' => ! isset($s['phone']) && $i % 2 === 1])>
                            <x-marketing.video :name="$s['video'][0]" :title="$s['video'][1]" :vertical="isset($s['phone'])" />
                        </div>
                    @elseif (isset($s['image']))
                        @isset($s['phone'])
                            <div class="flex justify-center lg:pl-6">
                                <img src="{{ $s['image'] }}" alt="{{ $s['alt'] }}" class="w-56 rounded-[2rem] border-[6px] border-slate-900 shadow-xl" width="390" height="844" loading="lazy">
                            </div>
                        @else
                            <div @class(['overflow-hidden rounded-2xl shadow-xl ring-1 ring-slate-200', 'lg:order-1' => $i % 2 === 1])>
                                <img src="{{ $s['image'] }}" alt="{{ $s['alt'] }}" class="block w-full" width="1440" height="900" loading="lazy">
                            </div>
                        @endisset
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    {{-- Appel final --}}
    <section class="px-4 pt-4 pb-20 sm:px-6">
        <div class="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-gradient-to-br from-brand-600 to-flame px-6 py-14 text-center text-white sm:px-12">
            <h2 class="relative text-3xl font-bold tracking-tight text-balance sm:text-4xl">Le mieux, c'est d'essayer</h2>
            <p class="relative mx-auto mt-4 max-w-xl text-lg text-white/90">
                {{ $trialDays > 0 ? $trialDays.' jours gratuits, sans carte bancaire.' : 'Créez votre espace en 2 minutes.' }} Ou demandez-nous une démonstration sur WhatsApp.
            </p>
            <div class="relative mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                @if ($signupUrl)
                    <a href="{{ $signupUrl }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-7 py-3.5 font-semibold text-brand-700 shadow-lg hover:bg-brand-50">Commencer l'essai gratuit</a>
                @endif
                <a href="/tarifs" class="inline-flex items-center justify-center gap-2 rounded-xl px-7 py-3.5 font-semibold text-white ring-1 ring-white/50 hover:bg-white/10">Voir les tarifs</a>
            </div>
        </div>
    </section>
@endsection
