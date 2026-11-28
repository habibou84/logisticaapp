<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    |
    | « auto » (défaut) : chaque entreprise envoie pour de vrai dès que son
    | numéro est connecté (Paramètres > WhatsApp, vérifié auprès de Meta) ;
    | les autres restent en simulation. « log » : simulation partout
    | (développement). « meta » : envois réels dès qu'un numéro est renseigné.
    |
    */

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'auto'),
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        // Application Meta : signature des webhooks et vérification de l'abonnement
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        // Bouton « Connecter WhatsApp » (inscription intégrée) : identifiant de l'application Meta
        // et de sa configuration « Facebook Login for Business ». Sans eux, saisie manuelle seulement.
        'app_id' => env('WHATSAPP_APP_ID'),
        'signup_config_id' => env('WHATSAPP_SIGNUP_CONFIG_ID'),
        // Utilisateur système de Jibiat (jeton permanent) : ajouté au compte WhatsApp de chaque
        // entreprise connectée par le bouton, pour ne pas dépendre de son jeton de 60 jours.
        'system_user_id' => env('WHATSAPP_SYSTEM_USER_ID'),
        'system_user_token' => env('WHATSAPP_SYSTEM_USER_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp des alertes (connexion par QR code)
    |--------------------------------------------------------------------------
    |
    | Numéro WhatsApp ordinaire, relié en scannant un QR code, qui envoie
    | seulement les alertes de la direction (pas de modèle Meta à faire
    | approuver). La session est tenue par le conteneur « whatsapp-qr »
    | (WAHA, docker-compose.yml) ; sans clé, la connexion est indisponible.
    |
    */

    'qr' => [
        'url' => env('WHATSAPP_QR_URL', 'http://whatsapp-qr:3000'),
        'api_key' => env('WHATSAPP_QR_API_KEY'),
        'session' => 'default',
        // Envois espacés (secondes, tirées au hasard) et plafonnés, pour rester discret auprès de WhatsApp
        'spacing' => [4, 12],
        'max_per_hour' => 30,
        // Déconnexion signalée aux administrateurs après ce délai (minutes), une fois
        'warn_after' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS de repli
    |--------------------------------------------------------------------------
    |
    | Envoyé quand un message WhatsApp n'a pas pu être remis (numéro sans
    | WhatsApp, échec définitif). « log », « twilio » ou « none » (désactivé).
    |
    */

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Courses reçues sur WhatsApp
    |--------------------------------------------------------------------------
    |
    | Analyse des messages libres par Claude quand une clé API Anthropic est
    | configurée ; sinon (ou en cas d'erreur), analyse par règles.
    |
    */

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
    ],

    // Une conversation sans message depuis 30 minutes repart de zéro
    'session_minutes' => 30,

    // Envois : 3 tentatives, espacées de 30 s puis 2 min
    'tries' => 3,
    'backoff' => [30, 120],

];
