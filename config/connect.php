<?php

/*
 * Connexion en un clic (autorisation de type OAuth 2 avec PKCE) et annuaire public des entreprises de livraison.
 * Contrat : habibou84/socle, contract/v1 (directory, connect-token).
 */
return [
    // Applications autorisées à demander une clé au nom d'un e-commerçant
    'clients' => [
        'jibiat-marchand' => [
            'name' => 'Jibiat Marchand',
            // Hôtes acceptés pour l'adresse de retour (« *. » : tous les sous-domaines)
            'redirect_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CONNECT_JIBIAT_MARCHAND_HOSTS', 'jibiat-marchand.com,*.jibiat-marchand.com'))))),
            'scopes' => ['orders:read', 'orders:write', 'webhooks:write', 'payouts:read', 'stock:read'],
        ],
    ],

    // Durée de validité d'un code d'autorisation (secondes)
    'code_ttl' => 600,

    // Annuaire : chiffres affichés à partir de ce nombre de courses sur 90 jours
    'directory_min_orders' => 20,
];
