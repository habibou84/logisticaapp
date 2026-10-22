<?php

/*
 * Installation pour une seule entreprise de livraison, servie à l'adresse APP_URL.
 */
return [
    // Exploitant de l'application (conditions générales, politique de confidentialité) ;
    // vides : le nom, l'adresse et l'e-mail de l'entreprise (Paramètres)
    'legal' => [
        'name' => env('LEGAL_NAME'),
        'address' => env('LEGAL_ADDRESS'),
        'email' => env('LEGAL_EMAIL'),
        // Numéro au registre du commerce (RCCM), affiché dans les conditions générales s'il est renseigné
        'rccm' => env('LEGAL_RCCM'),
    ],
    'country' => env('PLATFORM_COUNTRY', 'Côte d\'Ivoire'),

    // Préproduction fermée au public : code d'accès demandé avant toute page (ignoré en production)
    'preprod_access_code' => (string) env('PREPROD_ACCESS_CODE', ''),

    // Moteurs de recherche : seule la production est indexée (ailleurs : en-tête noindex et robots.txt fermé)
    'indexable' => (bool) env('SEARCH_INDEXING', env('APP_ENV') === 'production'),

    // Factures mensuelles des clients sous formule (distribution, MAD) : préfixes des numéros, échéance en jours
    'client_invoices' => [
        // Une série par activité
        'prefixes' => [
            'distribution' => env('CLIENT_INVOICE_PREFIX_DISTRIBUTION', 'DIS'),
            'mad' => env('CLIENT_INVOICE_PREFIX_MAD', 'MAD'),
        ],
        'due_days' => (int) env('CLIENT_INVOICE_DUE_DAYS', 15),
    ],

    // Requêtes par minute sur l'espace connecté : par compte, et pour toute l'entreprise
    'limits' => [
        'user_per_minute' => (int) env('PLATFORM_USER_RATE_LIMIT', 300),
        'company_per_minute' => (int) env('PLATFORM_COMPANY_RATE_LIMIT', 3000),
    ],
];
