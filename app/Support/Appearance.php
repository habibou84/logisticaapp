<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

/**
 * Apparence des espaces de l'entreprise : couleur de l'espace e-commerçant, de l'espace livreur et de
 * l'espace équipe, nom et icône des deux applications installables. « Même apparence » : l'espace
 * livreur et l'espace équipe reprennent la couleur (et l'icône) de l'espace e-commerçant.
 */
class Appearance
{
    public const SPACES = ['marchand', 'livreur', 'admin'];

    /** Espaces qui s'installent comme une application (icône, nom sous l'icône) */
    public const APPS = ['marchand', 'livreur'];

    public const DEFAULTS = [
        'marchand' => ['color' => '#047857', 'app_name' => 'Mes livraisons'],
        'livreur' => ['color' => '#1d4ed8', 'app_name' => 'Livreur'],
        'admin' => ['color' => '#0f172a'],
    ];

    /** Tailles des icônes : nom => [taille, part occupée par le logo] */
    public const ICONS = [
        '192' => [192, 0.72],
        '512' => [512, 0.72],
        'maskable-512' => [512, 0.56],
        'apple-180' => [180, 0.72],
    ];

    /**
     * Apparence complète, valeurs par défaut comprises.
     *
     * @return array{same: bool, spaces: array<string, array<string, mixed>>}
     */
    public static function for(?Company $company): array
    {
        $saved = $company?->appearance ?? [];
        $same = (bool) ($saved['same'] ?? false);
        $spaces = [];

        foreach (self::SPACES as $space) {
            $own = $saved['spaces'][$space] ?? [];
            // Même apparence : la couleur et l'icône de l'espace e-commerçant partout
            $source = $same ? ($saved['spaces']['marchand'] ?? []) : $own;
            $spaces[$space] = ['color' => self::spaceColor($company, $space)];
            if (in_array($space, self::APPS, true)) {
                $default = self::defaultAppName($company, $space);
                // « Mes livraisons » enregistré tel quel (ancien nom par défaut) : remplacé par le nom par défaut
                $custom = filled($own['app_name'] ?? null) && ! ($space === 'marchand' && $own['app_name'] === self::DEFAULTS['marchand']['app_name']);
                $spaces[$space]['app_name'] = $custom ? $own['app_name'] : $default;
                $spaces[$space]['default_app_name'] = $default;
                $spaces[$space]['custom_icon'] = filled($source['icon_path'] ?? null);
                $spaces[$space]['icon_url'] = self::iconUrl($company, $space, '192');
            }
        }

        return ['same' => $same, 'spaces' => $spaces];
    }

    /**
     * Nom sous l'icône sans réglage : le nom de l'entreprise pour l'application e-commerçant
     * (c'est elle que les e-commerçants reconnaissent), « Livreur » pour l'application livreur.
     */
    public static function defaultAppName(?Company $company, string $app): string
    {
        if ($app === 'marchand' && filled($company?->name)) {
            return mb_substr($company->name, 0, 30);
        }

        return self::DEFAULTS[$app]['app_name'];
    }

    /** Couleur d'un espace (celle de l'espace e-commerçant avec « même apparence ») */
    public static function spaceColor(?Company $company, string $space): string
    {
        $saved = $company?->appearance ?? [];
        $from = ($saved['same'] ?? false) ? 'marchand' : $space;

        return self::color($saved['spaces'][$from]['color'] ?? null) ?? self::DEFAULTS[$from]['color'];
    }

    public static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }

    /** Fichier de l'icône envoyée pour cet espace (ou celle de l'e-commerçant avec « même apparence ») */
    public static function iconPath(?Company $company, string $app): ?string
    {
        $saved = $company?->appearance ?? [];
        $space = ($saved['same'] ?? false) ? 'marchand' : $app;

        return $saved['spaces'][$space]['icon_path'] ?? null;
    }

    /**
     * Adresse de l'icône : générée pour l'entreprise (icône envoyée, ou logo sur la couleur de
     * l'espace), sinon l'icône standard. Le paramètre change avec l'apparence (cache du navigateur).
     */
    public static function iconUrl(?Company $company, string $app, string $variant): string
    {
        if (! self::hasCustomIcon($company, $app)) {
            return "/app-icons/{$app}-{$variant}.png";
        }

        return "/branding/icon/{$app}/{$variant}.png?v=".self::version($company, $app);
    }

    public static function hasCustomIcon(?Company $company, string $app): bool
    {
        return $company !== null && function_exists('imagecreatetruecolor')
            && (self::iconPath($company, $app) !== null || $company->logo_path !== null);
    }

    public static function version(Company $company, string $app): string
    {
        return substr(md5(json_encode([self::iconPath($company, $app), $company->logo_path, self::spaceColor($company, $app)])), 0, 10);
    }

    /**
     * Icône PNG carrée : l'image envoyée (recadrée au centre) ou le logo sur la couleur de l'espace.
     * Gardée sur le disque, recalculée seulement si l'apparence change.
     */
    public static function renderIcon(Company $company, string $app, string $variant): ?string
    {
        if (! self::hasCustomIcon($company, $app) || ! isset(self::ICONS[$variant])) {
            return null;
        }

        $cached = "branding/company-{$company->id}/icons/{$app}-{$variant}-".self::version($company, $app).'.png';
        $disk = Storage::disk('local');
        if ($disk->exists($cached)) {
            return $disk->path($cached);
        }

        [$size, $ratio] = self::ICONS[$variant];
        $own = self::iconPath($company, $app);
        $source = self::load($disk->path($own ?? $company->logo_path));
        if ($source === null) {
            return null;
        }

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        [$r, $g, $b] = sscanf(self::spaceColor($company, $app), '#%02x%02x%02x');
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));

        $w = imagesx($source);
        $h = imagesy($source);
        if ($own !== null) {
            // Icône envoyée : recadrée en carré (zone de sécurité réduite pour la variante « maskable »)
            $side = min($w, $h);
            $inner = $variant === 'maskable-512' ? (int) round($size * 0.8) : $size;
            $offset = intdiv($size - $inner, 2);
            imagecopyresampled($canvas, $source, $offset, $offset, intdiv($w - $side, 2), intdiv($h - $side, 2), $inner, $inner, $side, $side);
        } else {
            // Logo : contenu au centre, sur une pastille blanche pour rester lisible sur toutes les couleurs
            $box = (int) round($size * $ratio);
            $pad = (int) round($box * 0.12);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            $x0 = intdiv($size - $box, 2);
            imagefilledellipse($canvas, intdiv($size, 2), intdiv($size, 2), $box, $box, $white);
            $scale = min(($box - 2 * $pad) * 0.72 / $w, ($box - 2 * $pad) * 0.72 / $h);
            $dw = max(1, (int) round($w * $scale));
            $dh = max(1, (int) round($h * $scale));
            imagecopyresampled($canvas, $source, $x0 + intdiv($box - $dw, 2), $x0 + intdiv($box - $dh, 2), 0, 0, $dw, $dh, $w, $h);
        }

        ob_start();
        imagepng($canvas);
        $disk->put($cached, ob_get_clean());
        imagedestroy($canvas);
        imagedestroy($source);

        return $disk->path($cached);
    }

    private static function load(string $file): ?\GdImage
    {
        if (! is_file($file)) {
            return null;
        }
        $image = @imagecreatefromstring((string) file_get_contents($file));

        return $image ?: null;
    }
}
