<?php

namespace App\Http\Controllers;

use App\Support\Appearance;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;

/**
 * Manifestes des deux applications installables (PWA) : l'espace e-commerçant
 * et l'application livreur s'installent comme deux applications distinctes.
 */
class PwaManifestController extends Controller
{
    private const APPS = [
        'marchand' => [
            'suffix' => 'E-commerçant',
            'description' => 'Créez et suivez vos livraisons, recevez vos paiements.',
        ],
        'livreur' => [
            'suffix' => 'Livreur',
            'description' => 'Vos ramassages et livraisons du jour.',
        ],
    ];

    public function __invoke(string $app): JsonResponse
    {
        abort_unless(isset(self::APPS[$app]), 404);

        $config = self::APPS[$app];
        // Apparence choisie par l'entreprise (Paramètres › Apparence) : nom, couleur, icônes
        $company = rescue(fn () => Branding::company(), null, false);
        $look = Appearance::for($company)['spaces'][$app];
        $companyName = $company?->name ?? config('app.name');

        return response()->json([
            'id' => "/{$app}",
            // Sur la plateforme : le nom de l'entreprise de l'adresse ; l'application e-commerçant porte
            // simplement ce nom, l'application livreur le complète (« Entreprise · Livreur »)
            'name' => $app === 'marchand' ? $companyName : $companyName.' · '.$config['suffix'],
            'short_name' => $look['app_name'],
            'description' => $config['description'],
            'lang' => 'fr',
            // « app » : l'application ouverte se verrouille sur son espace (voir resources/js/native)
            'start_url' => "/{$app}?source=pwa&app={$app}",
            // Chaque application limitée à son espace : des portées qui se recouvrent (« / » contient
            // /livreur) font croire à Chrome que l'autre application est déjà installée
            'scope' => "/{$app}",
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#f8fafc',
            'theme_color' => $look['color'],
            // Pas de /icons/ : sous Debian, Apache le réserve à ses propres icônes (Alias /icons/)
            'icons' => [
                ['src' => Appearance::iconUrl($company, $app, '192'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => Appearance::iconUrl($company, $app, '512'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => Appearance::iconUrl($company, $app, 'maskable-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
