@php
    $legal = config('marketing.legal');
    $email = config('platform.contact_email') ?: $legal['email'];
    $trialDays = (int) config('platform.trial_days');
    $billing = config('platform.billing');
    $domain = \App\Support\Tenancy::enabled() ? \App\Support\Tenancy::domain() : null;
    // Sur l'adresse d'une entreprise de livraison : on rappelle qui fournit le service de livraison
    $company = rescue(fn () => \App\Support\Branding::company(), null, false);
    $updated = '14 octobre 2026';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conditions générales d'utilisation · {{ $company?->name ?? $legal['name'] }}</title>
    <meta name="description" content="Conditions générales d'utilisation et de vente du logiciel de gestion de livraisons édité par {{ $legal['name'] }}.">
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f8fafc; color: #0f172a; line-height: 1.6; }
        main { max-width: 46rem; margin: 0 auto; padding: 2.5rem 1rem 4rem; }
        h1 { font-size: 1.75rem; margin: 0 0 .25rem; }
        h2 { font-size: 1.15rem; margin: 2rem 0 .5rem; }
        p, li { color: #334155; }
        ul { padding-left: 1.25rem; }
        .muted { color: #64748b; font-size: .9rem; }
        .card { background: #fff; border-radius: .75rem; padding: 1rem 1.25rem; box-shadow: 0 1px 3px rgb(0 0 0 / .08); }
        nav.toc { columns: 2 14rem; font-size: .95rem; }
        nav.toc a { display: block; padding: .1rem 0; }
        a { color: #1d4ed8; }
    </style>
</head>
<body>
<main>
    <h1>Conditions générales d'utilisation et de vente</h1>
    <p class="muted">Dernière mise à jour : {{ $updated }}</p>

    <div class="card">
        <p>
            Ces conditions s'appliquent au logiciel de gestion des entreprises de livraison édité par
            <strong>{{ $legal['name'] }}</strong>@if ($domain) et proposé sur {{ $domain }}@endif.
            Elles s'appliquent à toute personne qui l'utilise : entreprises de livraison clientes, leurs équipes, leurs livreurs et
            leurs e-commerçants.
            @if ($company)
                Sur cette adresse, le service de livraison vous est fourni par <strong>{{ $company->name }}</strong>,
                qui utilise le logiciel.
            @endif
        </p>
    </div>

    <nav class="toc card" aria-label="Sommaire" style="margin-top:1rem">
        <a href="#editeur">1. Éditeur</a>
        <a href="#definitions">2. Définitions</a>
        <a href="#compte">3. Compte et accès</a>
        <a href="#services">4. Services</a>
        <a href="#abonnement">5. Essai, abonnement et paiement</a>
        <a href="#entreprise">6. Engagements de l'entreprise cliente</a>
        <a href="#utilisation">7. Règles d'utilisation</a>
        <a href="#donnees">8. Données</a>
        <a href="#responsabilite">9. Responsabilité</a>
        <a href="#resiliation">10. Suspension et résiliation</a>
        <a href="#propriete">11. Propriété intellectuelle</a>
        <a href="#modifications">12. Modification des conditions</a>
        <a href="#droit">13. Droit applicable et litiges</a>
    </nav>

    <h2 id="editeur">1. Éditeur</h2>
    <p>
        Le logiciel est édité par <strong>{{ $legal['name'] }}</strong>, {{ $legal['address'] }}@if ($legal['rccm']), immatriculée au RCCM sous le numéro {{ $legal['rccm'] }}@endif.
        Contact : <a href="mailto:{{ $email }}">{{ $email }}</a>.
    </p>

    <h2 id="definitions">2. Définitions</h2>
    <ul>
        <li><strong>Plateforme</strong> : le logiciel, ses applications (bureau, livreur, e-commerçant) et les pages publiques (suivi de colis, boutiques en ligne).</li>
        <li><strong>Entreprise cliente</strong> : l'entreprise de livraison qui crée un espace et souscrit une formule.</li>
        <li><strong>Utilisateurs</strong> : les personnes qui ont un compte dans l'espace d'une entreprise cliente : son équipe (administrateurs, dispatch, caisse), ses livreurs et ses e-commerçants.</li>
        <li><strong>Destinataires</strong> : les clients finaux qui reçoivent les colis.</li>
        <li><strong>Formule</strong> : l'offre d'abonnement choisie (prix mensuel, courses incluses, modules), décrite sur la page Tarifs.</li>
    </ul>

    <h2 id="compte">3. Compte et accès</h2>
    <ul>
        <li>L'entreprise cliente s'inscrit en ligne ou avec notre aide. Elle fournit des informations exactes et les tient à jour.</li>
        <li>Chaque utilisateur se connecte avec son numéro de téléphone (ou son e-mail) et son mot de passe. Il garde ses identifiants secrets et prévient l'entreprise cliente en cas d'usage suspect.</li>
        <li>L'entreprise cliente crée, modifie et suspend les comptes de son équipe, de ses livreurs et de ses e-commerçants. Elle est responsable des droits qu'elle leur donne et de ce qui est fait avec ces comptes.</li>
    </ul>

    <h2 id="services">4. Services</h2>
    <p>
        La plateforme permet notamment de gérer les courses, le dispatch, la caisse, les reversements, la paie des livreurs,
        les e-commerçants, les notifications et les statistiques. Le détail figure sur la page Fonctionnalités ; certains modules
        dépendent de la formule.
    </p>
    <p>
        Nous faisons nos meilleurs efforts pour que la plateforme soit disponible en permanence, mais elle peut être interrompue
        pour maintenance, mise à jour ou incident technique, en particulier chez nos prestataires (hébergement, réseau, WhatsApp, SMS).
        Nous faisons évoluer la plateforme pour l'améliorer ; une fonction essentielle d'une formule n'est pas retirée sans préavis.
    </p>

    <h2 id="abonnement">5. Essai, abonnement et paiement</h2>
    <ul>
        @if ($trialDays > 0)
            <li><strong>Essai gratuit</strong> : une nouvelle entreprise cliente bénéficie de {{ $trialDays }} jours d'essai gratuit, sans moyen de paiement demandé. Un seul essai par entreprise.</li>
        @endif
        <li><strong>Prix</strong> : les prix sont ceux de la formule choisie, indiqués en francs CFA sur la page Tarifs au moment de la souscription. Chaque formule comprend un nombre de courses par mois ; les courses au-delà sont facturées au prix par course de la formule.</li>
        <li><strong>Facturation</strong> : une facture est émise chaque mois, consultable dans l'espace de l'entreprise (menu « Mon abonnement »). Elle est payable sous {{ $billing['due_days'] }} jours.</li>
        <li><strong>Retard de paiement</strong> : passé l'échéance, un rappel est envoyé ; au-delà de {{ $billing['warning_after_days'] }} jours de retard, une alerte s'affiche dans l'espace ; au-delà de {{ $billing['restrict_after_days'] }} jours, la création de nouvelles courses est suspendue jusqu'au paiement. Les données restent consultables.</li>
        <li><strong>Changement de formule</strong> : possible à tout moment, sur demande ; la nouvelle formule s'applique à la période de facturation suivante, sauf accord contraire.</li>
        <li><strong>Frais de tiers</strong> : les messages WhatsApp envoyés par le numéro WhatsApp Business de l'entreprise cliente sont facturés directement par Meta à l'entreprise cliente, selon ses tarifs. Les notifications de la plateforme aux e-commerçants et aux livreurs sont incluses.</li>
        <li>Un mois commencé est dû ; il n'y a pas de remboursement au prorata, sauf manquement de notre part.</li>
    </ul>

    <h2 id="entreprise">6. Engagements de l'entreprise cliente</h2>
    <ul>
        <li>Elle exerce son activité de livraison sous sa propre responsabilité : relations avec ses e-commerçants, ses livreurs et les destinataires, prix pratiqués, encaissements, reversements, paie, assurances et obligations légales.</li>
        <li>La plateforme est un outil de gestion : les montants calculés (caisse, reversements, paie) reposent sur les informations saisies par l'entreprise et ses utilisateurs, qui restent responsables de leur exactitude et de leur vérification.</li>
        <li>Elle informe ses utilisateurs et les destinataires de l'usage de leurs données et respecte la loi applicable en matière de protection des données personnelles.</li>
        <li>Elle respecte les règles de WhatsApp et des opérateurs de SMS pour les messages envoyés en son nom.</li>
    </ul>

    <h2 id="utilisation">7. Règles d'utilisation (tous les utilisateurs)</h2>
    <ul>
        <li>Utiliser la plateforme pour un usage licite, lié à l'activité de livraison.</li>
        <li>Ne pas tenter d'accéder aux données d'une autre entreprise ou d'un autre utilisateur, de contourner les protections, ni de perturber le fonctionnement de la plateforme.</li>
        <li>Ne pas saisir de contenus illicites, trompeurs ou portant atteinte aux droits d'autrui (messages, photos, notes).</li>
        <li>Ne pas revendre ni mettre l'accès à la plateforme à disposition de tiers en dehors de l'entreprise cliente.</li>
    </ul>

    <h2 id="donnees">8. Données</h2>
    <ul>
        <li>Les données saisies par l'entreprise cliente et ses utilisateurs (courses, e-commerçants, livreurs, montants) appartiennent à l'entreprise cliente. {{ $legal['name'] }} les traite pour son compte, uniquement pour fournir le service.</li>
        <li>Les données de chaque entreprise sont séparées et ne sont jamais visibles par une autre entreprise.</li>
        <li>Le détail des données traitées, des prestataires et des droits des personnes figure dans la <a href="/confidentialite">politique de confidentialité</a>.</li>
        <li>À la fin du contrat, l'entreprise cliente peut demander l'export de ses données ; elles sont ensuite supprimées dans un délai raisonnable, sauf obligation légale de conservation.</li>
    </ul>

    <h2 id="responsabilite">9. Responsabilité</h2>
    <ul>
        <li>{{ $legal['name'] }} s'engage à fournir le service avec soin (obligation de moyens).</li>
        <li>{{ $legal['name'] }} n'est pas responsable des livraisons elles-mêmes : retards, pertes ou dommages aux colis, litiges entre l'entreprise cliente, ses livreurs, ses e-commerçants et les destinataires.</li>
        <li>{{ $legal['name'] }} n'est pas responsable des interruptions dues à des tiers (opérateurs, WhatsApp, coupures de réseau ou d'électricité) ni d'un usage non conforme de la plateforme.</li>
        <li>Si la responsabilité de {{ $legal['name'] }} est engagée, elle est limitée aux sommes payées par l'entreprise cliente au cours des trois derniers mois.</li>
    </ul>

    <h2 id="resiliation">10. Suspension et résiliation</h2>
    <ul>
        <li>L'entreprise cliente peut arrêter son abonnement à tout moment en nous écrivant ; l'arrêt prend effet à la fin du mois en cours.</li>
        <li>En cas d'impayé prolongé ou de manquement grave à ces conditions, {{ $legal['name'] }} peut suspendre l'accès après un avertissement resté sans effet, ou immédiatement en cas d'urgence (fraude, atteinte à la sécurité).</li>
        <li>Une entreprise cliente peut suspendre à tout moment le compte d'un de ses utilisateurs.</li>
    </ul>

    <h2 id="propriete">11. Propriété intellectuelle</h2>
    <p>
        Le logiciel, ses applications, son nom et ses contenus appartiennent à {{ $legal['name'] }}. L'abonnement donne un droit
        d'utilisation, personnel à l'entreprise cliente et à ses utilisateurs, pendant sa durée. L'entreprise cliente garde tous ses
        droits sur son nom, son logo et ses données.
    </p>

    <h2 id="modifications">12. Modification des conditions</h2>
    <p>
        Ces conditions peuvent évoluer. Les entreprises clientes sont prévenues au moins 30 jours avant tout changement important,
        notamment de prix ; l'utilisation de la plateforme après cette date vaut acceptation. La date de mise à jour figure en haut de la page.
    </p>

    <h2 id="droit">13. Droit applicable et litiges</h2>
    <p>
        Ces conditions sont soumises au droit ivoirien. En cas de désaccord, les parties cherchent d'abord une solution amiable
        en écrivant à <a href="mailto:{{ $email }}">{{ $email }}</a>. À défaut d'accord dans un délai de 30 jours, le litige est
        porté devant les juridictions compétentes d'Abidjan.
    </p>

    <p class="muted" style="margin-top:2.5rem">
        {{ $legal['name'] }} · {{ $legal['address'] }} · <a href="mailto:{{ $email }}">{{ $email }}</a> ·
        <a href="/confidentialite">Politique de confidentialité</a>
    </p>
</main>
</body>
</html>
