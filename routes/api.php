<?php

use App\Enums\ApiScope;
use App\Http\Controllers\Api\Connect\ConnectController;
use App\Http\Controllers\Api\Connect\PublicConnectController;
use App\Http\Controllers\Api\NativeAppController;
use App\Http\Controllers\Api\PublicV1\PublicAccountController;
use App\Http\Controllers\Api\PublicV1\PublicApiController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AppearanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanySignupController;
use App\Http\Controllers\Api\V1\ConsoleBillingController;
use App\Http\Controllers\Api\V1\ConsoleController;
use App\Http\Controllers\Api\V1\ConsolePlanController;
use App\Http\Controllers\Api\V1\ConsoleWaveController;
use App\Http\Controllers\Api\V1\CourierAvailabilityController;
use App\Http\Controllers\Api\V1\CourierController;
use App\Http\Controllers\Api\V1\CourierMessageController;
use App\Http\Controllers\Api\V1\CourierSpaceController;
use App\Http\Controllers\Api\V1\CustomDomainController;
use App\Http\Controllers\Api\V1\DeliveryDayController;
use App\Http\Controllers\Api\V1\DemoAccountController;
use App\Http\Controllers\Api\V1\FieldReportController;
use App\Http\Controllers\Api\V1\FinanceController;
use App\Http\Controllers\Api\V1\HubController;
use App\Http\Controllers\Api\V1\LegacyImportController;
use App\Http\Controllers\Api\V1\MerchantController;
use App\Http\Controllers\Api\V1\MerchantLocationController;
use App\Http\Controllers\Api\V1\MerchantNotificationController;
use App\Http\Controllers\Api\V1\MerchantShopController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfflineSyncController;
use App\Http\Controllers\Api\V1\OrderActionController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderDraftController;
use App\Http\Controllers\Api\V1\OrderExpenseController;
use App\Http\Controllers\Api\V1\OrderImportController;
use App\Http\Controllers\Api\V1\ParcelController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PayPlanController;
use App\Http\Controllers\Api\V1\PlanningController;
use App\Http\Controllers\Api\V1\PricingGridController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductPhotoController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReturnSlipController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SetupGuideController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\SignupController;
use App\Http\Controllers\Api\V1\StatisticsController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StorageContractController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TrackingController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WhatsAppSettingsController;
use App\Http\Controllers\Api\V1\WhatsAppSignupController;
use App\Http\Controllers\Api\V1\ZoneController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Webhooks des fournisseurs (authentifiés par signature)
Route::prefix('webhooks')->name('webhooks.')->group(function () {
    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.verify');
    Route::post('whatsapp', [WhatsAppWebhookController::class, 'handle'])->name('whatsapp.handle');
});

// Reprise depuis l'ancienne application (Laravel 5.8) : paquets envoyés par son bouton, avec la clé de reprise
Route::post('legacy-import', [LegacyImportController::class, 'receive'])->middleware('throttle:120,1')->name('legacy-import.receive');

// API publique des marchands (clé API, quota par clé) : contrat documenté dans public/docs/openapi.yaml
Route::prefix('public/v1')->name('public.')->middleware(['api.key', 'throttle:public-api'])->controller(PublicApiController::class)->group(function () {
    $read = 'api.scope:'.ApiScope::OrdersRead->value;
    $write = 'api.scope:'.ApiScope::OrdersWrite->value;

    Route::get('zones', 'zones')->name('zones');
    Route::get('hubs', 'hubs')->name('hubs');
    Route::get('delivery-day', 'deliveryDay')->name('delivery-day');
    Route::post('quotes', 'quote')->name('quotes');
    Route::get('orders', 'orders')->middleware($read)->name('orders.index');
    Route::post('orders', 'store')->middleware([$write, 'idempotent'])->name('orders.store');
    Route::get('orders/{trackingCode}', 'show')->middleware($read)->name('orders.show');
    Route::post('orders/{trackingCode}/cancel', 'cancel')->middleware($write)->name('orders.cancel');
    // Actions du marchand : modifier, décider après un échec, demander le retour, laisser une note
    Route::patch('orders/{trackingCode}', 'update')->middleware($write)->name('orders.update');
    Route::post('orders/{trackingCode}/decision', 'decision')->middleware($write)->name('orders.decision');
    Route::post('orders/{trackingCode}/return-request', 'returnRequest')->middleware($write)->name('orders.return-request');
    Route::post('orders/{trackingCode}/notes', 'note')->middleware($write)->name('orders.notes');
    Route::get('reports/summary', 'summary')->middleware($read)->name('reports.summary');
    Route::get('products', 'products')->middleware('api.scope:'.ApiScope::StockRead->value)->name('products');

    // Compte du marchand : identité, adresses webhook, reversements
    Route::controller(PublicAccountController::class)->group(function () {
        $webhooks = 'api.scope:'.ApiScope::WebhooksWrite->value;
        $payouts = 'api.scope:'.ApiScope::PayoutsRead->value;
        Route::get('me', 'me')->name('me');
        Route::get('webhooks', 'webhooks')->middleware($webhooks)->name('webhooks.index');
        Route::post('webhooks', 'storeWebhook')->middleware($webhooks)->name('webhooks.store');
        Route::delete('webhooks/{webhook}', 'destroyWebhook')->middleware($webhooks)->whereNumber('webhook')->name('webhooks.destroy');
        Route::get('payouts', 'payouts')->middleware($payouts)->name('payouts.index');
        Route::get('payouts/{reference}', 'payout')->middleware($payouts)->name('payouts.show');
    });
});

// Annuaire des entreprises de livraison (adresse de la plateforme) et connexion en un clic d'une application
// au compte d'un e-commerçant (adresse de l'entreprise) ; contrat : directory et connect-token (habibou84/socle)
Route::get('public/directory', [PublicConnectController::class, 'directory'])->middleware('throttle:60,1')->name('public.directory');
Route::prefix('public/connect')->name('public.connect.')->controller(PublicConnectController::class)->group(function () {
    Route::get('client', 'client')->middleware('throttle:60,1')->name('client');
    Route::post('requests', 'requestAccount')->middleware('throttle:verification-start')->name('requests');
    Route::post('token', 'token')->middleware('throttle:30,1')->name('token');
});

// Applications Android et iOS : entreprise désignée par son code (depuis l'adresse de la plateforme)
Route::get('public/apps/companies/{code}', [NativeAppController::class, 'company'])
    ->middleware('throttle:30,1')->where('code', '[A-Za-z0-9-]{1,60}')->name('public.apps.company');

// Photo de preuve d'une course (lien signé et temporaire renvoyé par l'API publique, sans clé)
Route::get('public/v1/attachments/{attachment}', [PublicApiController::class, 'attachment'])
    ->middleware('signed')->whereNumber('attachment')->name('public.attachments.show');

Route::prefix('v1')->name('v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    // Inscription des e-commerçants et mot de passe oublié : numéro vérifié par WhatsApp ou SMS
    Route::get('signup', [SignupController::class, 'options'])->name('signup.options');
    Route::post('signup', [SignupController::class, 'store'])->middleware('throttle:verification-start')->name('signup.store');
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:verification-start')->name('password.forgot');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1')->name('password.reset');
    Route::get('verifications/{token}', [VerificationController::class, 'show'])->middleware('throttle:90,1')->name('verifications.show');
    Route::post('verifications/{token}/sms', [VerificationController::class, 'sms'])->middleware('throttle:verification-sms')->name('verifications.sms');
    Route::post('verifications/{token}/code', [VerificationController::class, 'code'])->middleware('throttle:20,1')->name('verifications.code');

    // Inscription d'une entreprise de livraison en libre-service (adresse de la console)
    Route::get('company-signup', [CompanySignupController::class, 'options'])->middleware('throttle:60,1')->name('company-signup.options');
    Route::get('company-signup/slug', [CompanySignupController::class, 'slug'])->middleware('throttle:60,1')->name('company-signup.slug');
    Route::post('company-signup', [CompanySignupController::class, 'store'])->middleware('throttle:company-signup')->name('company-signup.store');

    // Page de commande publique d'un marchand (/b/{lien}) : catalogue, devis, commande
    Route::get('shops/{slug}', [ShopController::class, 'show'])->middleware('throttle:60,1')->name('shops.show');
    Route::post('shops/{slug}/quote', [ShopController::class, 'quote'])->middleware('throttle:60,1')->name('shops.quote');
    Route::post('shops/{slug}/orders', [ShopController::class, 'order'])->middleware('throttle:shop-order')->name('shops.order');

    // Bouton « Connecter WhatsApp » : page de jibiat.com ouverte avec un ticket de l'espace de l'entreprise
    Route::post('whatsapp-signup/session', [WhatsAppSignupController::class, 'session'])->middleware('throttle:30,1')->name('whatsapp-signup.session');
    Route::post('whatsapp-signup/complete', [WhatsAppSignupController::class, 'complete'])->middleware('throttle:10,1')->name('whatsapp-signup.complete');

    // Nom, logo et accroche de l'entreprise de livraison (pages de connexion)
    Route::get('branding', [BrandingController::class, 'show'])->name('branding.show');
    // Préproduction seulement : comptes de démonstration de l'entreprise affichés sur les pages de connexion
    Route::get('demo-accounts', DemoAccountController::class)->middleware('throttle:20,1')->name('demo-accounts');

    // Suivi public d'un colis (destinataire)
    Route::get('tracking/{code}', TrackingController::class)
        ->middleware('throttle:30,1')
        ->name('tracking.show');

    Route::middleware(['auth:sanctum', 'active', 'throttle:tenant'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('company/logo', [BrandingController::class, 'upload'])->name('company.logo.store');
        Route::delete('company/logo', [BrandingController::class, 'destroy'])->name('company.logo.destroy');
        // Apparence des espaces : couleurs, noms et icônes des applications
        Route::get('company/appearance', [AppearanceController::class, 'show'])->name('company.appearance.show');
        Route::put('company/appearance', [AppearanceController::class, 'update'])->name('company.appearance.update');
        Route::post('company/appearance/icons/{space}', [AppearanceController::class, 'uploadIcon'])->name('company.appearance.icon.store');
        Route::delete('company/appearance/icons/{space}', [AppearanceController::class, 'destroyIcon'])->name('company.appearance.icon.destroy');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        // Notifications push de l'appareil (Web Push)
        Route::get('push', [PushSubscriptionController::class, 'show'])->name('push.show');
        Route::post('push/subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:20,1')->name('push.subscribe');
        Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
        Route::post('push/test', [PushSubscriptionController::class, 'test'])->middleware('throttle:5,1')->name('push.test');

        // Console du super administrateur (plateforme)
        Route::prefix('console')->name('console.')->middleware('role:super_admin')->controller(ConsoleController::class)->group(function () {
            Route::get('companies', 'companies')->name('companies');
            Route::get('companies/{company}', 'show')->name('companies.show');
            Route::post('companies/{company}/support-session', 'supportSession')->middleware('throttle:10,1')->name('companies.support');
        });
        Route::prefix('console')->name('console.')->middleware('role:super_admin')->controller(ConsolePlanController::class)->group(function () {
            Route::get('plans', 'index')->name('plans.index');
            Route::post('plans', 'store')->name('plans.store');
            Route::post('plans/suggested', 'suggested')->name('plans.suggested');
            Route::patch('plans/{plan}', 'update')->name('plans.update');
        });
        Route::prefix('console')->name('console.')->middleware('role:super_admin')->controller(ConsoleBillingController::class)->group(function () {
            Route::get('invoices', 'index')->name('invoices.index');
            Route::post('invoices/generate', 'generate')->name('invoices.generate');
            Route::get('invoices/{invoice}', 'show')->name('invoices.show');
            Route::post('invoices/{invoice}/payments', 'pay')->name('invoices.pay');
            Route::post('invoices/{invoice}/void', 'void')->name('invoices.void');
            Route::get('settings', 'settings')->name('settings.show');
            Route::put('settings', 'updateSettings')->name('settings.update');
        });
        Route::prefix('console')->name('console.')->middleware('role:super_admin')->controller(ConsoleWaveController::class)->group(function () {
            Route::get('wave', 'show')->name('wave.show');
            Route::put('wave', 'update')->name('wave.update');
            Route::post('wave/qr', 'uploadQr')->name('wave.qr');
            Route::delete('wave/qr', 'removeQr')->name('wave.qr.remove');
            Route::put('wave/mail', 'updateMail')->name('wave.mail');
            Route::post('wave/test-alert', 'testAlert')->middleware('throttle:5,1')->name('wave.test-alert');
            Route::get('wave/payments', 'pending')->name('wave.pending');
            Route::post('wave/payments/{payment}/decide', 'decide')->name('wave.decide');
        });

        // Domaine personnalisé de l'entreprise (module de la formule pour le choisir)
        // Reprise de l'ancienne application : clé à coller dans son bouton, module à y copier
        Route::prefix('company/legacy-import')->name('company.legacy-import.')->middleware('can:settings.manage')->controller(LegacyImportController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::post('key', 'createKey')->middleware('throttle:10,1')->name('key.store');
            Route::delete('key', 'destroyKey')->name('key.destroy');
            Route::get('module', 'module')->name('module');
        });
        Route::prefix('company/domain')->name('company.domain.')->middleware('can:settings.manage')->controller(CustomDomainController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::put('/', 'update')->middleware(['feature:custom_domain', 'throttle:10,1'])->name('update');
            Route::post('check', 'check')->middleware(['feature:custom_domain', 'throttle:10,1'])->name('check');
            Route::delete('/', 'destroy')->name('destroy');
        });

        // Formule, essai et consommation du mois de l'entreprise
        Route::get('subscription', [SubscriptionController::class, 'show'])->middleware('can:settings.manage')->name('subscription.show');
        Route::get('subscription/invoices/{invoice}', [SubscriptionController::class, 'invoice'])->middleware('can:settings.manage')->name('subscription.invoice');
        Route::post('subscription/invoices/{invoice}/wave', [SubscriptionController::class, 'waveStart'])->middleware(['can:settings.manage', 'throttle:10,1'])->name('subscription.wave');
        Route::post('subscription/wave-payments/{payment}/paid', [SubscriptionController::class, 'waveClaim'])->middleware(['can:settings.manage', 'throttle:10,1'])->name('subscription.wave.paid');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::apiResource('companies', CompanyController::class)->except('destroy');
        Route::apiResource('users', UserController::class);

        // Référentiels
        Route::get('zones', [ZoneController::class, 'index'])->name('zones.index');
        Route::get('incident-reasons', [ReferenceController::class, 'incidentReasons'])->name('incident-reasons.index');
        Route::get('recipients', [ReferenceController::class, 'recipients'])->name('recipients.index');
        Route::post('quotes', QuoteController::class)->name('quotes.store');
        Route::get('hubs', [HubController::class, 'index'])->name('hubs.index');

        Route::middleware('can:settings.manage')->group(function () {
            // Parcours de mise en route de l'administrateur
            Route::get('setup', [SetupGuideController::class, 'show'])->name('setup.show');
            Route::post('setup/skip', [SetupGuideController::class, 'skip'])->name('setup.skip');
            Route::post('setup/seen', [SetupGuideController::class, 'seen'])->name('setup.seen');
            Route::post('setup/test-order', [SetupGuideController::class, 'startTest'])->name('setup.test-order.start');
            Route::delete('setup/test-order', [SetupGuideController::class, 'cleanTest'])->name('setup.test-order.clean');
            Route::post('zones', [ZoneController::class, 'store'])->name('zones.store');
            Route::patch('zones/{zone}', [ZoneController::class, 'update'])->name('zones.update');
            Route::delete('zones/{zone}', [ZoneController::class, 'destroy'])->name('zones.destroy');
            Route::post('hubs', [HubController::class, 'store'])->name('hubs.store');
            Route::patch('hubs/{hub}', [HubController::class, 'update'])->name('hubs.update');

            Route::apiResource('pricing-grids', PricingGridController::class);
            Route::put('pricing-grids/{pricing_grid}/rules', [PricingGridController::class, 'syncRules'])->name('pricing-grids.rules');
            Route::put('pricing-grids/{pricing_grid}/surcharges', [PricingGridController::class, 'syncSurcharges'])->name('pricing-grids.surcharges');

            // Paie des livreurs : plans de rémunération
            Route::post('pay-plans/simulate', [PayPlanController::class, 'simulate'])->middleware('throttle:30,1')->name('pay-plans.simulate');
            Route::apiResource('pay-plans', PayPlanController::class);

            // WhatsApp
            Route::get('whatsapp/settings', [WhatsAppSettingsController::class, 'show'])->name('whatsapp.settings');
            Route::put('whatsapp/settings', [WhatsAppSettingsController::class, 'update'])->name('whatsapp.settings.update');
            Route::post('whatsapp/test', [WhatsAppSettingsController::class, 'test'])->middleware('throttle:10,1')->name('whatsapp.test');
            Route::post('whatsapp/templates/sync', [WhatsAppSettingsController::class, 'syncTemplates'])->name('whatsapp.templates.sync');
            Route::post('whatsapp/connect', [WhatsAppSettingsController::class, 'connect'])->middleware('throttle:20,1')->name('whatsapp.connect');
            Route::post('whatsapp/signup-ticket', [WhatsAppSignupController::class, 'ticket'])->middleware('throttle:10,1')->name('whatsapp.signup-ticket');
            Route::post('whatsapp/templates/submit', [WhatsAppSettingsController::class, 'submitTemplates'])->middleware('throttle:5,1')->name('whatsapp.templates.submit');
            Route::post('whatsapp/simulate', [WhatsAppSettingsController::class, 'simulate'])->middleware(['throttle:60,1', 'feature:whatsapp_bot'])->name('whatsapp.simulate');
        });

        // Remontées terrain des livreurs (notes, problèmes) : consultation et suivi par le dispatch
        Route::middleware('can:orders.dispatch')->prefix('field-reports')->name('field-reports.')->group(function () {
            Route::get('/', [FieldReportController::class, 'index'])->name('index');
            Route::get('counts', [FieldReportController::class, 'counts'])->name('counts');
            Route::post('{event}/handle', [FieldReportController::class, 'handle'])->name('handle');
            Route::post('{event}/reopen', [FieldReportController::class, 'reopen'])->name('reopen');
        });

        // Bons de retour groupés par marchand (droits vérifiés dans le contrôleur)
        Route::get('return-slips', [ReturnSlipController::class, 'index'])->name('return-slips.index');
        Route::post('return-slips', [ReturnSlipController::class, 'store'])->name('return-slips.store');
        Route::get('return-slips/{returnSlip}', [ReturnSlipController::class, 'show'])->name('return-slips.show');
        Route::post('return-slips/{returnSlip}/hand-over', [ReturnSlipController::class, 'handOver'])->middleware('throttle:30,1')->name('return-slips.hand-over');
        Route::post('return-slips/{returnSlip}/cancel', [ReturnSlipController::class, 'cancel'])->name('return-slips.cancel');
        Route::get('return-slips/{returnSlip}/proof/{kind}', [ReturnSlipController::class, 'proof'])->whereIn('kind', ['signature', 'photo'])->name('return-slips.proof');

        // Colis chez les livreurs (droits vérifiés dans le contrôleur)
        Route::get('parcels/held', [ParcelController::class, 'index'])->name('parcels.held');
        Route::get('parcels/held/counts', [ParcelController::class, 'counts'])->name('parcels.held.counts');
        Route::post('couriers/{courier}/parcels/receive', [ParcelController::class, 'receive'])->name('couriers.parcels.receive');

        // Journal des messages WhatsApp et SMS
        Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
        Route::post('messages/{message}/retry', [MessageController::class, 'retry'])->name('messages.retry');

        // Connexion en un clic d'une application (gérant) et demandes d'ouverture de compte (équipe)
        Route::post('connect/authorize', [ConnectController::class, 'authorize'])->middleware('throttle:20,1')->name('connect.authorize');
        Route::get('connect-requests', [ConnectController::class, 'requests'])->name('connect-requests.index');
        Route::post('connect-requests/{connectRequest}/accept', [ConnectController::class, 'accept'])->name('connect-requests.accept');
        Route::post('connect-requests/{connectRequest}/decline', [ConnectController::class, 'decline'])->name('connect-requests.decline');

        // Marchands
        Route::apiResource('merchants', MerchantController::class)->except('destroy');
        Route::post('merchants/{merchant}/users', [MerchantController::class, 'storeUser'])->name('merchants.users.store');
        Route::put('merchants/{merchant}/pickup-location', [MerchantLocationController::class, 'update'])->name('merchants.pickup-location');
        Route::get('merchant/pickup-location', [MerchantLocationController::class, 'mine'])->name('merchant.pickup-location');
        // Statistiques de l'e-commerçant (son application)
        Route::get('merchant/stats', [StatisticsController::class, 'merchant'])->middleware('can:orders.view')->name('merchant.stats');
        Route::put('merchant/pickup-location', [MerchantLocationController::class, 'updateMine'])->name('merchant.pickup-location.update');
        // Lien Google Maps collé → coordonnées
        Route::post('geo/link', [MerchantLocationController::class, 'resolveLink'])->middleware('throttle:20,1')->name('geo.link');
        Route::get('merchants/{merchant}/notifications', [MerchantNotificationController::class, 'show'])->name('merchants.notifications');
        Route::put('merchants/{merchant}/notifications', [MerchantNotificationController::class, 'update'])->name('merchants.notifications.update');

        // Planning des livreurs : qui ramasse ou livre où et quand, absences
        Route::middleware('can:orders.dispatch')->controller(PlanningController::class)->group(function () {
            Route::get('planning', 'show')->name('planning.show');
            Route::post('planning/pause', 'pause')->name('planning.pause');
            Route::post('planning/rules', 'storeRule')->name('planning.rules.store');
            Route::put('planning/rules/{rule}', 'updateRule')->name('planning.rules.update');
            Route::delete('planning/rules/{rule}', 'destroyRule')->name('planning.rules.destroy');
            Route::post('planning/absences', 'storeAbsence')->name('planning.absences.store');
            Route::delete('planning/absences/{absence}', 'destroyAbsence')->name('planning.absences.destroy');
        });

        // Livreurs (gestion par le personnel)
        Route::get('couriers/map', [CourierController::class, 'map'])->middleware('can:orders.dispatch')->name('couriers.map');
        Route::get('couriers/availability', [CourierAvailabilityController::class, 'index'])->middleware('can:orders.dispatch')->name('couriers.availability');
        Route::get('couriers/{courier}/availability', [CourierAvailabilityController::class, 'show'])->middleware('can:orders.dispatch')->name('couriers.availability.show');
        Route::post('couriers/{courier}/pickup-mission', [CourierAvailabilityController::class, 'pickupMission'])->middleware('can:orders.dispatch')->name('couriers.pickup-mission');
        Route::get('couriers/{courier}/track', [CourierController::class, 'track'])->middleware('can:orders.dispatch')->name('couriers.track');
        Route::get('couriers', [CourierController::class, 'index'])->middleware('can:orders.dispatch')->name('couriers.index');
        Route::get('couriers/{courier}', [CourierController::class, 'show'])->middleware('can:orders.dispatch')->name('couriers.show');
        Route::patch('couriers/{courier}', [CourierController::class, 'update'])->middleware('can:users.manage')->name('couriers.update');

        // Journée de livraison : état (dispatch, marchands), fermeture et réouverture (dispatch)
        Route::get('delivery-day', [DeliveryDayController::class, 'show'])->name('delivery-day.show');
        Route::post('delivery-day/close', [DeliveryDayController::class, 'close'])->name('delivery-day.close');
        Route::post('delivery-day/reopen', [DeliveryDayController::class, 'reopen'])->name('delivery-day.reopen');

        // Courses
        Route::get('orders/import/template', [OrderImportController::class, 'template'])->name('orders.import.template');
        Route::post('orders/import', [OrderImportController::class, 'store'])->middleware('throttle:20,1')->name('orders.import');
        Route::post('orders/bulk-assign', [OrderActionController::class, 'bulkAssign'])->name('orders.bulk-assign');
        Route::post('orders/bulk-unassign', [OrderActionController::class, 'bulkUnassign'])->name('orders.bulk-unassign');
        Route::post('orders/bulk-confirm', [OrderActionController::class, 'bulkConfirm'])->name('orders.bulk-confirm');
        // Avant la ressource : « counts » n'est pas un identifiant de course
        Route::get('orders/counts', [OrderController::class, 'counts'])->name('orders.counts');
        Route::get('orders/pickup-groups', [OrderController::class, 'pickupGroups'])->name('orders.pickup-groups');
        // Aide à la saisie : analyse d'un message collé, doublons du jour
        Route::post('orders/parse-message', [OrderDraftController::class, 'parse'])->middleware('throttle:30,1')->name('orders.parse-message');
        Route::get('orders/duplicates', [OrderDraftController::class, 'duplicates'])->name('orders.duplicates');
        Route::apiResource('orders', OrderController::class)->except('destroy');
        Route::post('orders/{order}/status', [OrderActionController::class, 'transition'])->name('orders.transition');
        Route::post('orders/{order}/assign', [OrderActionController::class, 'assign'])->name('orders.assign');
        Route::post('orders/{order}/unassign', [OrderActionController::class, 'unassign'])->name('orders.unassign');
        Route::post('orders/{order}/decision', [OrderActionController::class, 'decide'])->name('orders.decision');
        Route::post('orders/{order}/lost', [OrderActionController::class, 'declareLost'])->name('orders.lost');
        Route::post('orders/{order}/notes', [OrderActionController::class, 'note'])->name('orders.notes');
        Route::post('orders/{order}/verify-delivery-code', [OrderActionController::class, 'verifyDeliveryCode'])->name('orders.verify-delivery-code');
        Route::post('orders/{order}/return-request', [OrderActionController::class, 'requestReturn'])->name('orders.return-request');
        Route::post('orders/{order}/attachments', [OrderActionController::class, 'storeAttachment'])->name('orders.attachments.store');
        Route::get('orders/{order}/messages', [MessageController::class, 'forOrder'])->name('orders.messages');
        Route::get('orders/{order}/courier-messages', [CourierMessageController::class, 'index'])->name('orders.courier-messages.index');
        Route::post('orders/{order}/courier-messages', [CourierMessageController::class, 'store'])->middleware('throttle:60,1')->name('orders.courier-messages.store');
        Route::post('orders/{order}/expenses', [OrderExpenseController::class, 'store'])->name('orders.expenses.store');
        Route::post('orders/{order}/expenses/{expense}/cancel', [OrderExpenseController::class, 'cancel'])->name('orders.expenses.cancel');
        Route::get('orders/{order}/attachments/{attachment}', [OrderActionController::class, 'showAttachment'])->name('orders.attachments.show');

        // Clés de l'API publique et webhooks sortants : module « API et webhooks »
        Route::middleware('feature:api')->group(function () {
            // Le marchand pour lui-même, l'administration pour un marchand
            Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
            Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
            Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

            Route::apiResource('webhooks', WebhookController::class)->except('show');
            Route::post('webhooks/{webhook}/secret', [WebhookController::class, 'rotateSecret'])->name('webhooks.secret');
            Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1')->name('webhooks.test');
            Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->name('webhooks.deliveries');
            Route::post('webhook-deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver'])->middleware('throttle:30,1')->name('webhook-deliveries.redeliver');
        });

        // Produits : catalogue de la boutique ou stock en entrepôt (droits vérifiés par ProductPolicy)
        Route::middleware('feature:stock,shop')->group(function () {
            Route::apiResource('products', ProductController::class);
            Route::post('products/{product}/photo', [ProductPhotoController::class, 'store'])->middleware('throttle:30,1')->name('products.photo.store');
            Route::delete('products/{product}/photo', [ProductPhotoController::class, 'destroy'])->name('products.photo.destroy');
        });
        // Réglages de la page de commande du marchand
        Route::middleware('feature:shop')->group(function () {
            Route::get('shop', [MerchantShopController::class, 'show'])->name('shop.show');
            Route::patch('shop', [MerchantShopController::class, 'update'])->name('shop.update');
        });
        Route::middleware('feature:stock')->group(function () {
            Route::get('stock/movements', [StockMovementController::class, 'index'])->name('stock.movements.index');
            Route::post('stock/movements', [StockMovementController::class, 'store'])->name('stock.movements.store');
            Route::apiResource('storage-contracts', StorageContractController::class)->except('show');
        });

        Route::get('reports/summary', [ReportController::class, 'summary'])->name('reports.summary');

        // Statistiques de l'entreprise (back-office)
        Route::prefix('stats')->name('stats.')->middleware('can:orders.view')->controller(StatisticsController::class)->group(function () {
            Route::get('overview', 'overview')->name('overview');
            Route::get('deliveries', 'deliveries')->name('deliveries');
            Route::get('couriers', 'couriers')->name('couriers');
            Route::get('merchants', 'merchants')->name('merchants');
            // Statistiques avancées : selon la formule de l'entreprise
            Route::get('zones', 'zones')->middleware('feature:advanced_stats')->name('zones');
            Route::get('finances', 'finances')->middleware('feature:advanced_stats')->name('finances');
        });

        // Caisse et finances (droits vérifiés dans le contrôleur : finance.view / finance.manage)
        Route::prefix('finance')->name('finance.')->controller(FinanceController::class)->group(function () {
            Route::get('cash', 'cash')->name('cash');
            Route::get('couriers/{courier}/collections', 'collections')->name('couriers.collections');
            Route::post('couriers/{courier}/advances', 'storeAdvance')->name('couriers.advances.store');
            Route::get('couriers/{courier}/earnings', 'earnings')->name('couriers.earnings');
            Route::post('couriers/{courier}/adjustments', 'adjustCourier')->name('couriers.adjustments');
            Route::get('remittances', 'remittances')->name('remittances.index');
            Route::post('remittances', 'storeRemittance')->name('remittances.store');

            Route::get('merchants', 'merchants')->name('merchants.index');
            Route::get('merchants/{merchant}/ledger', 'ledger')->name('merchants.ledger');
            Route::post('merchants/{merchant}/adjustments', 'adjustMerchant')->name('merchants.adjustments');
            Route::get('payouts', 'payouts')->name('payouts.index');
            Route::post('payouts', 'storePayout')->name('payouts.store');
            Route::get('payouts/{payout}', 'showPayout')->name('payouts.show');
            Route::post('payouts/{payout}/pay', 'payPayout')->name('payouts.pay');
            Route::post('payouts/{payout}/cancel', 'cancelPayout')->name('payouts.cancel');

            Route::get('courier-payouts', 'courierPayouts')->name('courier-payouts.index');
            Route::post('courier-payouts', 'storeCourierPayout')->name('courier-payouts.store');
            Route::get('courier-payouts/{courierPayout}', 'showCourierPayout')->name('courier-payouts.show');
            Route::post('courier-payouts/{courierPayout}/pay', 'payCourierPayout')->name('courier-payouts.pay');
            Route::post('courier-payouts/{courierPayout}/cancel', 'cancelCourierPayout')->name('courier-payouts.cancel');
        });

        // Espace livreur
        Route::prefix('courier')->name('courier.')->middleware('role:courier')->group(function () {
            Route::get('missions', [CourierSpaceController::class, 'missions'])->name('missions');
            Route::post('assignments/{assignment}/accept', [CourierSpaceController::class, 'accept'])->name('assignments.accept');
            Route::post('assignments/{assignment}/refuse', [CourierSpaceController::class, 'refuse'])->name('assignments.refuse');
            Route::patch('status', [CourierSpaceController::class, 'updateStatus'])->name('status');
            // Actions faites hors connexion, envoyées au retour du réseau
            Route::post('sync', OfflineSyncController::class)->middleware('throttle:120,1')->name('sync');
            Route::get('wallet', [FinanceController::class, 'wallet'])->name('wallet');
            Route::get('messages', [CourierMessageController::class, 'mine'])->name('messages');
            Route::post('messages/{message}/ack', [CourierMessageController::class, 'acknowledge'])->name('messages.ack');
            Route::get('return-slips', [ReturnSlipController::class, 'mine'])->name('return-slips');
        });
    });
});
