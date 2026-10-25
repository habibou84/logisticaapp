<?php

namespace App\Services\Destinations;

use App\Models\Destination;
use App\Models\Zone;
use App\Services\Pricing\ClientPricing;
use App\Services\WhatsApp\ZoneMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Remise en ordre d'une destination saisie à la main : sigle et nom, ville (« ABJDJAN » → Abidjan), commune
 * rattachée aux zones de l'entreprise (« YOP », « 2PLATEAUX », « Z4 »), quartier, numéros.
 */
class DestinationCleaner
{
    /** Quartiers et abréviations d'Abidjan → commune */
    private const ALIASES = [
        'yop' => 'Yopougon', 'yopougon' => 'Yopougon', 'niangon' => 'Yopougon', 'siporex' => 'Yopougon', 'sideci' => 'Yopougon',
        'treich' => 'Treichville', 'treichville' => 'Treichville', 'zone 3' => 'Marcory', 'zone3' => 'Marcory',
        'zone 4' => 'Marcory', 'zone4' => 'Marcory', 'z4' => 'Marcory', 'bietry' => 'Marcory', 'marcory' => 'Marcory', 'anoumabo' => 'Marcory',
        'vridi' => 'Port-Bouët', 'port bouet' => 'Port-Bouët', 'gonzagueville' => 'Port-Bouët', 'aeroport' => 'Port-Bouët',
        'cocody' => 'Cocody', 'angre' => 'Cocody', 'riviera' => 'Cocody', '2 plateaux' => 'Cocody', '2plateaux' => 'Cocody',
        'ii plateaux' => 'Cocody', 'deux plateaux' => 'Cocody', 'danga' => 'Cocody', 'ambassades' => 'Cocody', 'vallon' => 'Cocody',
        'mermoz' => 'Cocody', 'blockhaus' => 'Cocody', 'attoban' => 'Cocody', 'palmeraie' => 'Cocody', 'bonoumin' => 'Cocody',
        'faya' => 'Cocody', 'abatta' => 'Cocody', 'saint jean' => 'Cocody', 'st jean' => 'Cocody', 'm pouto' => 'Cocody', 'vge' => 'Marcory',
        'plateau' => 'Plateau', 'adjame' => 'Adjamé', 'abobo' => 'Abobo', 'koumassi' => 'Koumassi', 'attecoube' => 'Attécoubé',
        'anyama' => 'Anyama', 'bingerville' => 'Bingerville', 'songon' => 'Songon',
        'vallons' => 'Cocody', 'z3' => 'Marcory', 'plt' => 'Plateau', 'kssi' => 'Koumassi', 'williamsville' => 'Adjamé',
        'bvd de marseille' => 'Marcory', 'boulevard de marseille' => 'Marcory', 'port de peche' => 'Treichville', 'port' => 'Treichville',
        '2 pltx' => 'Cocody', '2pltx' => 'Cocody', 'bd de marseille' => 'Marcory', 'cite des arts' => 'Cocody', 'ancien cocody' => 'Cocody', 'deux plateau' => 'Cocody', '2 plateau' => 'Cocody', '2plateau' => 'Cocody',
    ];

    private const ABIDJAN_COMMUNES = ['cocody', 'plateau', 'marcory', 'yopougon', 'treichville', 'koumassi', 'adjame', 'abobo', 'port bouet', 'attecoube', '2 plateau', '2 plateaux', 'deux plateaux'];

    /** @var Collection<int, Zone>|null */
    private ?Collection $zones = null;

    public function __construct(private readonly ZoneMatcher $matcher) {}

    /** Zones de l'entreprise (une seule lecture pour un import) */
    public function useZones(int $companyId): self
    {
        $this->zones = Zone::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->with('parent')->get();

        return $this;
    }

    /**
     * « ITWA(IMPERIAL TOBACCO WEST AFRICA) » → sigle ITWA, nom « IMPERIAL TOBACCO WEST AFRICA ».
     *
     * @return array{0: string, 1: ?string}
     */
    public static function name(string $raw): array
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if (preg_match('/^([\p{Lu}0-9&.\'\- ]{2,20}?)\s*\((.{3,})\)\s*$/u', $raw, $m)) {
            return [trim($m[2]), trim($m[1])];
        }

        return [$raw, null];
    }

    /** « ABJDJAN », « Abidan », « 2 PLATEAU » → Abidjan ; « BASSAM » → Grand-Bassam ; sinon la ville en clair */
    public static function city(?string $raw): ?string
    {
        $key = Destination::normalize($raw);
        if ($key === '') {
            return null;
        }
        if (levenshtein($key, 'abidjan') <= 2 || str_starts_with($key, 'abj') || str_starts_with($key, 'abid') || in_array($key, self::ABIDJAN_COMMUNES, true)) {
            return 'Abidjan';
        }
        if (str_contains($key, 'bassam')) {
            return 'Grand-Bassam';
        }

        return Str::title(Str::lower(trim((string) $raw)));
    }

    /**
     * Commune (zone) et quartier d'après la commune saisie, puis la ville, puis l'adresse.
     *
     * @return array{0: ?Zone, 1: ?string}
     */
    public function zone(?string $commune, ?string $city, ?string $address = null): array
    {
        $zones = $this->zones ?? collect();
        $text = trim((string) $commune);
        $key = Destination::normalize($text);
        // « Ii Plateaux » → « II Plateaux », « 7È Tranche » → « 7e Tranche »
        $district = $text !== '' ? preg_replace(['/\bIi\b/u', '/(\d)(?:È|E|Ème|Eme)\b/u'], ['II', '$1e'], Str::title(Str::lower($text))) : null;

        // La commune écrite en tête (« COCODY II PLATEAUX ») l'emporte sur une zone citée plus loin ; un quartier
        // connu de cette commune est gardé
        $alias = $key !== '' ? $this->alias($key) : null;
        $found = $key !== '' ? $this->matcher->inText($text, $zones)->first() : null;
        $zone = $found !== null && ($alias === null || $found->id === $alias->id || $found->parent_id === $alias->id) ? $found : ($alias ?? $found);
        // Ville de l'intérieur : la zone de la ville
        if ($zone === null && $city !== null && $city !== 'Abidjan') {
            $zone = $zones->first(fn (Zone $z) => $z->parent_id === null && ClientPricing::cityKey($z->name) === ClientPricing::cityKey($city));
        }
        $zone ??= $address ? ($this->matcher->inText($address, $zones)->first() ?? $this->alias(Destination::normalize($address), true)) : null;

        // Quartier : seulement s'il dit plus que la commune (« Cocody Angré » → « Angré »)
        if ($zone !== null && $district !== null) {
            $commune = Destination::normalize($zone->parent_id ? ($zone->parent?->name ?? $zone->name) : $zone->name);
            // Abréviation de la commune (« YOP », « TREICH », « PLT ») : rien de plus
            $short = Destination::normalize($district);
            if ($short === Destination::normalize($zone->name) || str_starts_with($commune, $short) || in_array($short, ['plt', 'kssi'], true)) {
                $district = null;
            } elseif (str_starts_with(Destination::normalize($district), $commune.' ')) {
                $district = trim(mb_substr($district, mb_strlen($commune) + 1)) ?: null;
            }
        }

        return [$zone, $district];
    }

    private function alias(string $key, bool $anywhere = false): ?Zone
    {
        // Le plus long d'abord (« port bouet » avant « port ») ; « riviera3 », « angre7 » : début du mot
        $aliases = self::ALIASES;
        uksort($aliases, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($aliases as $alias => $name) {
            $hit = $anywhere ? str_contains(" {$key} ", " {$alias} ") : str_starts_with($key, $alias);
            if ($hit) {
                return $this->commune($name);
            }
        }
        if ($anywhere) {
            return null;
        }

        // Faute de frappe (« MACORY », « TRECHVILLE », « VRIDY ») : un mot proche d'une commune ou d'un alias
        $word = explode(' ', $key)[0];
        if (strlen($word) >= 5) {
            foreach ($aliases as $alias => $name) {
                if (! str_contains($alias, ' ') && strlen($alias) >= 5 && levenshtein($word, $alias) <= (strlen($alias) >= 8 ? 2 : 1)) {
                    return $this->commune($name);
                }
            }
        }

        return null;
    }

    private function commune(string $name): ?Zone
    {
        return ($this->zones ?? collect())->first(fn (Zone $z) => $z->parent_id === null && Destination::normalize($z->name) === Destination::normalize($name));
    }
}
