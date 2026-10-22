@php
    $legal = \App\Support\Legal::details();
    $email = $legal['email'];
    $updated = '22 octobre 2026';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conditions générales d'utilisation · {{ $legal['name'] }}</title>
    <meta name="description" content="Conditions générales d'utilisation de l'application de livraison de {{ $legal['name'] }}.">
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
    <h1>Conditions générales d'utilisation</h1>
    <p class="muted">Dernière mise à jour : {{ $updated }}</p>

    <div class="card">
        <p>
            Ces conditions s'appliquent à l'application de livraison de <strong>{{ $legal['name'] }}</strong> : l'espace de son
            équipe, l'application des livreurs, l'application des clients et les pages publiques (suivi de colis, boutiques
            en ligne). Elles s'appliquent à toute personne qui l'utilise.
        </p>
    </div>

    <nav class="toc card" aria-label="Sommaire" style="margin-top:1rem">
        <a href="#editeur">1. Exploitant</a>
        <a href="#definitions">2. Définitions</a>
        <a href="#compte">3. Compte et accès</a>
        <a href="#services">4. Services</a>
        <a href="#utilisation">5. Règles d'utilisation</a>
        <a href="#donnees">6. Données</a>
        <a href="#responsabilite">7. Responsabilité</a>
        <a href="#suspension">8. Suspension d'un compte</a>
        <a href="#modifications">9. Modification des conditions</a>
        <a href="#droit">10. Droit applicable et litiges</a>
    </nav>

    <h2 id="editeur">1. Exploitant</h2>
    <p>
        L'application est exploitée par <strong>{{ $legal['name'] }}</strong>{{ $legal['address'] ? ', '.$legal['address'] : '' }}{{ $legal['rccm'] ? ', immatriculée au RCCM sous le numéro '.$legal['rccm'] : '' }}.
        @if ($email)
            Contact : <a href="mailto:{{ $email }}">{{ $email }}</a>.
        @endif
    </p>

    <h2 id="definitions">2. Définitions</h2>
    <ul>
        <li><strong>Application</strong> : l'espace de l'équipe, les applications des livreurs et des clients, et les pages publiques.</li>
        <li><strong>Utilisateurs</strong> : les personnes qui ont un compte : l'équipe de {{ $legal['name'] }} (administrateurs, dispatch, caisse), ses livreurs et ses clients.</li>
        <li><strong>Destinataires</strong> : les clients finaux qui reçoivent les colis.</li>
    </ul>

    <h2 id="compte">3. Compte et accès</h2>
    <ul>
        <li>Chaque utilisateur se connecte avec son numéro de téléphone (ou son e-mail) et son mot de passe. Il garde ses identifiants secrets et prévient {{ $legal['name'] }} en cas d'usage suspect.</li>
        <li>{{ $legal['name'] }} crée, modifie et suspend les comptes de son équipe et de ses livreurs. Un client peut créer son compte en ligne quand l'inscription est ouverte ; il fournit des informations exactes et les tient à jour.</li>
    </ul>

    <h2 id="services">4. Services</h2>
    <p>
        L'application permet notamment de créer et suivre les courses, de gérer le dispatch, la caisse, les reversements aux
        clients, la paie des livreurs, les notifications et les statistiques.
    </p>
    <p>
        {{ $legal['name'] }} fait ses meilleurs efforts pour que l'application soit disponible en permanence, mais elle peut être
        interrompue pour maintenance, mise à jour ou incident technique, en particulier chez ses prestataires (hébergement, réseau,
        WhatsApp, SMS).
    </p>

    <h2 id="utilisation">5. Règles d'utilisation</h2>
    <ul>
        <li>Utiliser l'application pour un usage licite, lié à l'activité de livraison.</li>
        <li>Ne pas tenter d'accéder aux données d'un autre utilisateur, de contourner les protections, ni de perturber le fonctionnement de l'application.</li>
        <li>Ne pas saisir de contenus illicites, trompeurs ou portant atteinte aux droits d'autrui (messages, photos, notes).</li>
        <li>Les montants affichés (caisse, reversements, paie) reposent sur les informations saisies : chacun vérifie celles qu'il saisit.</li>
    </ul>

    <h2 id="donnees">6. Données</h2>
    <ul>
        <li>{{ $legal['name'] }} traite les données saisies dans l'application (courses, clients, livreurs, montants) pour fournir le service de livraison.</li>
        <li>Le détail des données traitées, des prestataires et des droits des personnes figure dans la <a href="/confidentialite">politique de confidentialité</a>.</li>
    </ul>

    <h2 id="responsabilite">7. Responsabilité</h2>
    <ul>
        <li>{{ $legal['name'] }} n'est pas responsable des interruptions dues à des tiers (opérateurs, WhatsApp, coupures de réseau ou d'électricité) ni d'un usage non conforme de l'application.</li>
        <li>Les conditions de livraison (prix, délais, colis perdus ou endommagés, reversements) sont celles convenues entre {{ $legal['name'] }} et chaque client.</li>
    </ul>

    <h2 id="suspension">8. Suspension d'un compte</h2>
    <p>
        En cas de manquement grave à ces conditions, {{ $legal['name'] }} peut suspendre un compte après un avertissement resté
        sans effet, ou immédiatement en cas d'urgence (fraude, atteinte à la sécurité).
    </p>

    <h2 id="modifications">9. Modification des conditions</h2>
    <p>
        Ces conditions peuvent évoluer. La date de mise à jour figure en haut de la page ; l'utilisation de l'application après un
        changement vaut acceptation.
    </p>

    <h2 id="droit">10. Droit applicable et litiges</h2>
    <p>
        Ces conditions sont soumises au droit ivoirien. En cas de désaccord, les parties cherchent d'abord une solution amiable
        @if ($email)
            en écrivant à <a href="mailto:{{ $email }}">{{ $email }}</a>
        @endif. À défaut d'accord dans un délai de 30 jours, le litige est
        porté devant les juridictions compétentes d'Abidjan.
    </p>

    <p class="muted" style="margin-top:2.5rem">
        {{ $legal['name'] }}{{ $legal['address'] ? ' · '.$legal['address'] : '' }}
        @if ($email)
            · <a href="mailto:{{ $email }}">{{ $email }}</a>
        @endif
        ·
        <a href="/confidentialite">Politique de confidentialité</a>
    </p>
</main>
</body>
</html>
