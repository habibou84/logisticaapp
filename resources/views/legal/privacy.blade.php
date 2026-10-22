@php
    $legal = \App\Support\Legal::details();
    $operator = $legal['name'];
    $email = $legal['email'];
    $country = config('platform.country');
    $updated = '22 octobre 2026';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Politique de confidentialité · {{ $operator }}</title>
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
        a { color: #1d4ed8; }
    </style>
</head>
<body>
<main>
    <h1>Politique de confidentialité</h1>
    <p class="muted">Dernière mise à jour : {{ $updated }}</p>

    <div class="card">
        <p>
            Cette page explique quelles données sont utilisées par l'application de livraison de
            <strong>{{ $operator }}</strong>, pourquoi, avec qui elles sont partagées et comment exercer vos droits.
        </p>
    </div>

    <h2>1. Qui est responsable de vos données ?</h2>
    <p>
        {{ $operator }} est responsable des données de ses clients, de ses livreurs et des destinataires des colis :
        c'est elle qui décide de leur utilisation.
    </p>

    <h2>2. Données utilisées</h2>
    <ul>
        <li><strong>Comptes</strong> : nom, numéro de téléphone, adresse e-mail (facultative), mot de passe chiffré, rôle.</li>
        <li><strong>Courses</strong> : nom, téléphone et adresse du destinataire, repère, montant à encaisser, contenu déclaré,
            historique de la course (étapes, incidents, preuves de livraison et photos).</li>
        <li><strong>Clients</strong> : nom de la boutique, contact, lieu de ramassage, relevés et reversements.</li>
        <li><strong>Livreurs</strong> : position pendant les missions (pour le suivi et la répartition des courses),
            encaissements, versements et paie.</li>
        <li><strong>Messages</strong> : messages WhatsApp et SMS envoyés ou reçus par l'entreprise de livraison
            (suivi des colis, codes de livraison, commandes envoyées par WhatsApp).</li>
        <li><strong>Données techniques</strong> : journaux de connexion et d'erreurs, adresse IP, nécessaires à la sécurité.</li>
    </ul>

    <h2>3. Pourquoi ?</h2>
    <ul>
        <li>Organiser et suivre les livraisons, prévenir les destinataires et les clients.</li>
        <li>Tenir la caisse : encaissements, reversements aux clients, paie des livreurs.</li>
        <li>Recevoir des commandes par WhatsApp et répondre aux messages.</li>
        <li>Sécuriser les comptes (vérification du numéro, prévention des abus) et respecter nos obligations légales.</li>
    </ul>
    <p>Aucune donnée n'est vendue ni utilisée à des fins publicitaires.</p>

    <h2>4. WhatsApp et les autres prestataires</h2>
    <ul>
        <li><strong>WhatsApp (Meta Platforms)</strong> : les messages WhatsApp passent par la plateforme WhatsApp Business de Meta.
            Le numéro et le contenu des messages sont traités par Meta selon sa propre
            <a href="https://www.whatsapp.com/legal/privacy-policy" rel="noopener">politique de confidentialité</a>.</li>
        <li><strong>SMS</strong> : un opérateur d'envoi de SMS reçoit le numéro et le texte lorsqu'un SMS est envoyé.</li>
        <li><strong>Analyse des commandes</strong> : lorsque l'entreprise de livraison l'active, le texte des commandes reçues par
            WhatsApp peut être analysé par un service d'intelligence artificielle (Anthropic) pour en extraire le nom, le
            téléphone et l'adresse du destinataire ; il n'est pas utilisé pour entraîner ce service.</li>
        <li><strong>Hébergement</strong> : le serveur qui fait fonctionner l'application.</li>
    </ul>

    <h2>5. Combien de temps ?</h2>
    <p>
        Les données des courses et la comptabilité sont conservées le temps exigé par les obligations comptables. L'historique des positions des livreurs est supprimé automatiquement après
        {{ \App\Models\CourierLocation::RETENTION_DAYS }} jours. Les codes de vérification expirent au bout de 30 minutes.
    </p>

    <h2>6. Sécurité</h2>
    <p>
        Connexion chiffrée (HTTPS), mots de passe et jetons d'accès chiffrés, accès limité selon le rôle de chacun.
    </p>

    <h2>7. Vos droits</h2>
    <p>
        Vous pouvez demander l'accès à vos données, leur correction ou leur suppression, et vous opposer à leur utilisation,
        conformément à la loi applicable en {{ $country }} (loi n° 2013-450 relative à la protection des données à caractère
        personnel ; autorité de contrôle : ARTCI) en nous écrivant (voir ci-dessous).
    </p>

    <h2 id="suppression">8. Supprimer vos données</h2>
    <p>Pour demander la suppression de vos données (y compris celles liées à WhatsApp) :</p>
    <ul>
        <li>client ou livreur : demandez la fermeture de votre compte à {{ $operator }} ;</li>
        <li>destinataire d'un colis : écrivez-nous
            @if ($email) à <a href="mailto:{{ $email }}?subject=Suppression%20de%20mes%20donn%C3%A9es">{{ $email }}</a>@endif
            en indiquant votre numéro de téléphone.</li>
    </ul>
    <p>
        Nous confirmons la suppression sous 30 jours, sauf pour les informations que la loi impose de conserver
        (comptabilité), qui sont alors seulement archivées.
    </p>

    <h2>9. Cookies</h2>
    <p>
        L'application n'utilise pas de cookies publicitaires ni de mesure d'audience. Il garde seulement, dans votre navigateur,
        ce qui est nécessaire pour rester connecté.
    </p>

    <h2>10. Contact</h2>
    <p>
        {{ $operator }}{{ $legal['address'] ? ' · '.$legal['address'] : '' }}
        @if ($email)
            · <a href="mailto:{{ $email }}">{{ $email }}</a>
        @endif
    </p>
    <p class="muted">Cette politique peut évoluer ; la date de mise à jour figure en haut de la page.
        Voir aussi les <a href="/conditions">conditions générales d'utilisation</a>.</p>
</main>
</body>
</html>
