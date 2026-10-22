<?php

/*
 * Plateforme multi-entreprises : chaque entreprise de livraison a son adresse,
 * {slug}.PLATFORM_DOMAIN (ex. entreprise1.jibiat.com). Sans PLATFORM_DOMAIN,
 * l'installation sert une seule entreprise (BRANDING_COMPANY, sinon la première).
 */
return [
    // Éditeur de la plateforme (politique de confidentialité, demandes de suppression)
    'operator' => env('PLATFORM_OPERATOR', env('APP_NAME', 'Jibiat')),
    'contact_email' => env('PLATFORM_CONTACT_EMAIL'),
    'contact_phone' => env('PLATFORM_CONTACT_PHONE'),
    'country' => env('PLATFORM_COUNTRY', 'Côte d\'Ivoire'),

    // Préproduction fermée au public : code d'accès demandé avant toute page (ignoré en production)
    'preprod_access_code' => (string) env('PREPROD_ACCESS_CODE', ''),

    // Moteurs de recherche : seule la production est indexée (ailleurs : en-tête noindex et robots.txt fermé)
    'indexable' => (bool) env('SEARCH_INDEXING', env('APP_ENV') === 'production'),

    // Domaine de la plateforme, sans « www » (ex. jibiat.com) ; vide = une seule entreprise
    'domain' => env('PLATFORM_DOMAIN'),

    // Console du super administrateur : admin.PLATFORM_DOMAIN
    'admin_subdomain' => env('PLATFORM_ADMIN_SUBDOMAIN', 'admin'),

    // Protocole des liens envoyés (WhatsApp, SMS) vers l'adresse de l'entreprise
    'scheme' => env('PLATFORM_SCHEME', 'https'),

    // Requêtes par minute sur l'espace connecté : par compte, et pour toute une entreprise
    // (une entreprise très active ne doit pas ralentir les autres)
    'limits' => [
        'user_per_minute' => (int) env('PLATFORM_USER_RATE_LIMIT', 300),
        'company_per_minute' => (int) env('PLATFORM_COMPANY_RATE_LIMIT', 3000),
    ],

    // Essai gratuit des nouvelles entreprises créées avec une formule (jours ; 0 = aucun)
    'trial_days' => (int) env('PLATFORM_TRIAL_DAYS', 30),

    // Factures mensuelles des entreprises : échéance après émission, puis alerte (bandeau) et
    // restriction (plus de nouvelles courses) après l'échéance, en jours
    'billing' => [
        'invoice_prefix' => env('PLATFORM_INVOICE_PREFIX', 'FAC'),
        'due_days' => (int) env('PLATFORM_INVOICE_DUE_DAYS', 10),
        'warning_after_days' => (int) env('PLATFORM_BILLING_WARNING_DAYS', 7),
        'restrict_after_days' => (int) env('PLATFORM_BILLING_RESTRICT_DAYS', 15),
    ],

    // Domaines personnalisés des entreprises (ex. livraison.rapide-express.ci) :
    //  - target : nom vers lequel le client fait pointer son domaine (CNAME), servi par Cloudflare for SaaS ;
    //  - server_ip : adresse IP à indiquer pour un domaine racine (enregistrement A) ;
    //  - cloudflare : création automatique du nom d'hôte (jeton « Custom Hostnames : Edit » et zone de PLATFORM_DOMAIN)
    'custom_domains' => [
        'target' => env('PLATFORM_CUSTOM_DOMAIN_TARGET'),
        'server_ip' => env('PLATFORM_SERVER_IP'),
        'cloudflare' => [
            'token' => env('CLOUDFLARE_API_TOKEN'),
            'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        ],
    ],

    // Sous-domaines qui ne peuvent pas être attribués à une entreprise
    'reserved_subdomains' => ['www', 'admin', 'api', 'app', 'mail', 'smtp', 'ftp', 'ws', 'socket', 'static', 'cdn', 'docs', 'status', 'support', 'aide', 'blog'],
];
