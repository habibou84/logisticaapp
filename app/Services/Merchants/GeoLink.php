<?php

namespace App\Services\Merchants;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Coordonnées d'un lien de carte collé par l'agence ou le marchand : lien Google Maps
 * (long, ou court « maps.app.goo.gl » partagé depuis le téléphone, dont on suit les
 * redirections), lien OpenStreetMap, ou coordonnées écrites « 5.3600, -3.9700 ».
 */
class GeoLink
{
    // Seuls ces sites sont contactés pour suivre un lien court
    private const SHORT_HOSTS = ['maps.app.goo.gl', 'goo.gl', 'g.co'];

    private const MAP_HOSTS = ['google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl', 'goo.gl', 'g.co'];

    private const MAX_REDIRECTS = 4;

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function resolve(string $input): ?array
    {
        $input = trim($input);
        if (($found = $this->parse($input)) !== null) {
            return $found;
        }

        $url = $input;
        for ($i = 0; $i < self::MAX_REDIRECTS; $i++) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! in_array($host, self::SHORT_HOSTS, true) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                return null;
            }

            try {
                $response = Http::timeout(5)->withOptions(['allow_redirects' => false])->get($url);
            } catch (Throwable) {
                return null;
            }

            $next = $response->header('Location');
            if (! $next) {
                return null;
            }
            if (($found = $this->parse(urldecode($next))) !== null) {
                return $found;
            }
            if (! in_array(strtolower((string) parse_url($next, PHP_URL_HOST)), self::MAP_HOSTS, true)) {
                return null;
            }
            $url = $next;
        }

        return null;
    }

    /**
     * Coordonnées présentes dans le texte, sans appel extérieur.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function parse(string $text): ?array
    {
        $num = '(-?\d{1,3}(?:\.\d+)?)';
        $patterns = [
            "/!3d{$num}!4d{$num}/",                       // repère précis d'un lieu Google Maps
            "/[?&](?:q|query|ll|center|destination)={$num}(?:,|%2C)\s*{$num}/i",
            "/@{$num},{$num}/",                           // centre de la vue Google Maps
            "/[?&#]mlat={$num}&mlon={$num}/",             // OpenStreetMap
            "/#map=\d+\/{$num}\/{$num}/",
            "/^\s*{$num}\s*[,; ]\s*{$num}\s*$/",          // coordonnées écrites
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) && abs((float) $m[1]) <= 90 && abs((float) $m[2]) <= 180 && ((float) $m[1] !== 0.0 || (float) $m[2] !== 0.0)) {
                return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
            }
        }

        return null;
    }
}
