<?php

namespace App\Services\Zones;

use Illuminate\Support\Str;
use Jibiat\Socle\Places\Places;

/**
 * Lieux proposés à l'entreprise : la liste commune du socle (Abidjan, ses communes et leurs quartiers, les principales
 * villes et leurs quartiers), complétée par les autres villes de Côte d'Ivoire (chefs-lieux de département et villes
 * de passage) où la distribution et la mise à disposition livrent aussi. Ces villes en plus ont un code au même
 * format (« katiola ») mais ne sont pas dans la liste commune : elles ne sont pas envoyées à Jibiat Marchand comme
 * lieux communs (champ « place » à null), jusqu'à leur arrivée dans le socle, où elles garderont ce code.
 */
final class PlaceList
{
    /** Villes de l'intérieur en plus de la liste commune : nom => variantes d'écriture */
    private const EXTRA_CITIES = [
        'Adiaké' => [], 'Akoupé' => [], 'Alépé' => [], 'Arrah' => [], 'Attiégouakro' => [], 'Ayamé' => [], 'Azaguié' => [],
        'Bangolo' => [], 'Béoumi' => [], 'Bettié' => [], 'Biankouma' => [], 'Bloléquin' => [], 'Bocanda' => [], 'Bongouanou' => [],
        'Bonon' => [], 'Botro' => [], 'Bouna' => [], 'Boundiali' => [], 'Buyo' => [], 'Dabakala' => [], 'Danané' => [],
        'Dianra' => [], 'Didiévi' => [], 'Djékanou' => [], 'Doropo' => [], 'Facobly' => [], 'Fresco' => [], 'Gbéléban' => [],
        'Gohitafla' => [], 'Grand-Béréby' => ['grand bereby', 'bereby'], 'Guéyo' => [], 'Guibéroua' => [], 'Guitry' => [],
        'Hiré' => [], 'Kani' => [], 'Kaniasso' => [], 'Kong' => [], 'Koro' => [], 'Koun-Fao' => ['koun fao'],
        'Kouassi-Kouassikro' => [], 'Kouibly' => [], 'Kounahiri' => [], 'Kouto' => [], 'Lakota' => [], 'Madinani' => [],
        'Mankono' => [], 'Massala' => [], "M'Bahiakro" => ['mbahiakro'], "M'Batto" => ['mbatto'], 'Méagui' => [],
        'Minignan' => [], 'Nassian' => [], 'Niakaramandougou' => ['niakara'], 'Ouangolodougou' => ['ouangolo'],
        'Ouaninou' => [], 'Ouellé' => [], 'Oumé' => [], 'Ouragahio' => [], 'Prikro' => [], 'Sakassou' => [],
        'Samatiguila' => [], 'Sandégué' => [], 'Séguélon' => [], 'Sikensi' => [], 'Sinématiali' => [], 'Sipilou' => [],
        'Taabo' => [], 'Tafiré' => [], 'Taï' => [], 'Tanda' => [], 'Téhini' => [], 'Tengréla' => [], 'Tiapoum' => [],
        'Tiébissou' => [], 'Tortiya' => [], 'Touba' => [], 'Toulépleu' => [], 'Transua' => [], 'Vavoua' => [],
        'Yakassé-Attobrou' => [], 'Zouan-Hounien' => [], 'Zoukougbeu' => [], 'Zuénoula' => [],
    ];

    /** @var array<string, array{code: string, type: string, name: string, parent: ?string, aliases: list<string>, common: bool}>|null */
    private static ?array $all = null;

    /**
     * Tous les lieux, parents avant enfants : ceux de la liste commune, puis les villes en plus.
     *
     * @return list<array{code: string, type: string, name: string, parent: ?string, aliases: list<string>, common: bool}>
     */
    public static function all(): array
    {
        return array_values(self::index());
    }

    /**
     * @return array{code: string, type: string, name: string, parent: ?string, aliases: list<string>, common: bool}|null
     */
    public static function find(?string $code): ?array
    {
        return $code === null ? null : (self::index()[$code] ?? null);
    }

    /** Le lieu est-il dans la liste commune du socle (partagée avec Jibiat Marchand) ? */
    public static function isCommon(?string $code): bool
    {
        return $code !== null && Places::find($code) !== null;
    }

    /** Lieu d'Abidjan (commune ou quartier) : livré au tarif des courses, pas à celui des villes de l'intérieur */
    public static function inAbidjan(string $code): bool
    {
        return $code === 'abidjan' || str_starts_with($code, 'abidjan-');
    }

    /**
     * Ville d'un lieu : Abidjan pour ses communes et quartiers, sinon la ville elle-même (ou celle du quartier).
     */
    public static function city(string $code): ?string
    {
        $place = self::find($code);
        while ($place !== null && $place['parent'] !== null) {
            $place = self::find($place['parent']);
        }

        return $place['name'] ?? null;
    }

    /**
     * Villes de l'intérieur (hors Abidjan), par ordre alphabétique : pour les tarifs de la distribution.
     *
     * @return list<string>
     */
    public static function interiorCities(): array
    {
        return collect(self::index())->where('type', Places::CITY)->where('code', '!=', 'abidjan')
            ->pluck('name')->sort(fn ($a, $b) => strcmp(Places::normalize($a), Places::normalize($b)))->values()->all();
    }

    /**
     * @return array<string, array{code: string, type: string, name: string, parent: ?string, aliases: list<string>, common: bool}>
     */
    private static function index(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $all = [];
        foreach (Places::all() as $place) {
            $all[$place['code']] = [...$place, 'common' => true];
        }
        $known = array_flip(array_map(fn ($p) => Places::normalize($p['name']), array_filter($all, fn ($p) => $p['type'] === Places::CITY)));
        foreach (self::EXTRA_CITIES as $name => $aliases) {
            $code = Str::slug($name);
            // Ville arrivée depuis dans la liste commune : c'est elle qui compte
            if (isset($all[$code]) || isset($known[Places::normalize($name)])) {
                continue;
            }
            $all[$code] = ['code' => $code, 'type' => Places::CITY, 'name' => $name, 'parent' => null, 'aliases' => $aliases, 'common' => false];
        }

        return self::$all = $all;
    }
}
