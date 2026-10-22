<?php

use App\Http\Controllers\Api\V1\ProductPhotoController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\PwaManifestController;
use App\Http\Controllers\WavePaymentValidationController;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Route;

Route::get('/manifest/{app}.webmanifest', PwaManifestController::class)
    ->whereIn('app', ['marchand', 'livreur'])
    ->name('pwa.manifest');

// Logo de l'entreprise de livraison (pages de connexion, en-têtes)
Route::get('/branding/logo', [BrandingController::class, 'logo'])->name('branding.logo');
// Icônes des applications installables, aux couleurs et au logo de l'entreprise
Route::get('/branding/icon/{app}/{variant}.png', [BrandingController::class, 'icon'])
    ->whereIn('app', ['marchand', 'livreur'])->whereIn('variant', ['192', '512', 'maskable-512', 'apple-180'])
    ->name('branding.icon');
Route::get('/produits/{product}/photo', [ProductPhotoController::class, 'show'])->name('products.photo');

// Contrôle d'un domaine personnalisé : l'entreprise reconnue à cette adresse
Route::get('/.well-known/domain-check', fn () => response()->json([
    'slug' => app(Tenancy::class)->company()?->slug,
]))->middleware('throttle:60,1')->name('domain.check');

// Moteurs de recherche : tout est ouvert en production, tout est fermé ailleurs (préproduction)
Route::get('/robots.txt', fn () => response("User-agent: *\nDisallow:".(config('platform.indexable') ? '' : ' /')."\n")
    ->header('Content-Type', 'text/plain; charset=UTF-8'))->name('robots');

// Politique de confidentialité (exigée par Meta pour WhatsApp) et conditions générales, sur toutes les adresses
// Validation d'un paiement Wave depuis le lien signé reçu par e-mail ou SMS (code du validateur)
Route::get('/paiements-wave/valider/{payment}', [WavePaymentValidationController::class, 'show'])->middleware('signed:relative')->whereNumber('payment')->name('wave-payments.validate');
Route::post('/paiements-wave/valider/{payment}', [WavePaymentValidationController::class, 'decide'])->middleware(['signed:relative', 'throttle:20,1'])->whereNumber('payment');

Route::view('/confidentialite', 'legal.privacy')->name('privacy');
Route::view('/conditions', 'legal.terms')->name('terms');

// Documentation de l'API publique (spécification : public/docs/openapi.yaml)
Route::view('/developpeurs/api', 'api-docs')->name('api.docs');

// Toutes les autres adresses servent l'application, sauf l'API et les fichiers compilés : un fichier de
// /build disparu (ancienne version après une mise à jour) répond 404, jamais la page HTML à sa place
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api(/|$))(?!build/).*');
