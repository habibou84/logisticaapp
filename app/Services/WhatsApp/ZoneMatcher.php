<?php

namespace App\Services\WhatsApp;

use App\Models\Zone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Retrouve les zones citées dans un texte libre (« Yop », « Cocody Angré », « yopougon siporex »…).
 */
class ZoneMatcher
{
    /**
     * Zones citées, de la plus précise à la moins précise.
     *
     * @param  Collection<int, Zone>  $zones  zones actives, parents chargés
     * @return Collection<int, Zone>
     */
    public function inText(string $text, Collection $zones): Collection
    {
        $haystack = ' '.self::normalize($text).' ';

        return $zones
            ->filter(fn (Zone $z) => ($name = self::normalize($z->name)) !== '' && str_contains($haystack, " {$name} "))
            // Le quartier l'emporte sur sa commune, le nom le plus long sur le plus court
            ->sortByDesc(fn (Zone $z) => [$z->parent_id !== null ? 1 : 0, mb_strlen($z->name)])
            ->values()
            ->pipe(function (Collection $found) {
                // Un quartier trouvé rend sa commune redondante
                $parents = $found->pluck('parent_id')->filter();

                return $found->reject(fn (Zone $z) => $parents->contains($z->id))->values();
            });
    }

    /**
     * Réponse courte à « Quelle commune ? » : nom exact, puis début de nom (« yop »).
     *
     * @param  Collection<int, Zone>  $zones
     * @return Collection<int, Zone>
     */
    public function answer(string $text, Collection $zones): Collection
    {
        $found = $this->inText($text, $zones);
        if ($found->isNotEmpty()) {
            return $found;
        }

        $needle = self::normalize($text);
        if (mb_strlen($needle) < 3) {
            return collect();
        }

        return $zones->filter(fn (Zone $z) => str_starts_with(self::normalize($z->name), $needle)
            || str_contains(self::normalize($z->name), $needle))->values();
    }

    /**
     * Nom de zone renvoyé par l'IA : correspondance exacte (nom ou nom complet).
     *
     * @param  Collection<int, Zone>  $zones
     */
    public function byName(?string $name, Collection $zones): ?Zone
    {
        if (blank($name)) {
            return null;
        }

        $needle = self::normalize($name);

        return $zones->first(fn (Zone $z) => self::normalize($z->fullName()) === $needle)
            ?? $zones->first(fn (Zone $z) => self::normalize($z->name) === $needle);
    }

    public static function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii($value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }
}
