<?php

/*
 * Site de présentation (adresse principale de la plateforme, ex. jibiat.com).
 */
return [

    // Numéro WhatsApp commercial : bouton « Demander une démo » et pied de page
    'whatsapp' => env('MARKETING_WHATSAPP_NUMBER', '+2250507016739'),

    // Éditeur de la plateforme (conditions générales, pied de page)
    'legal' => [
        'name' => env('LEGAL_NAME', 'Jibiat SARL'),
        'address' => env('LEGAL_ADDRESS', 'Abidjan, Côte d\'Ivoire'),
        'email' => env('LEGAL_EMAIL', 'contact@jibiat.com'),
        // Numéro au registre du commerce (RCCM), affiché dans les conditions générales s'il est renseigné
        'rccm' => env('LEGAL_RCCM'),
    ],

    // Avis de clients affichés sur l'accueil
    'testimonials' => [
        ['author' => 'Express DS', 'quote' => 'Application très pratique, j\'ai multiplié ma productivité par 2.'],
        ['author' => 'Tivaya', 'quote' => 'Je ne passe plus des nuits à faire des points. Tout est automatisé. Quel bonheur !'],
    ],
];
