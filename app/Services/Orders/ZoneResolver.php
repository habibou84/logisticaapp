<?php

namespace App\Services\Orders;

use App\Models\Zone;
use App\Services\WhatsApp\ZoneMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Zone indiquée par son nom (« Cocody », « Yopougon Siporex », « yop ») au lieu de son
 * identifiant : intégrations (boutiques en ligne, API) qui ne connaissent qu'une ville
 * ou un quartier saisis par le client.
 */
class ZoneResolver
{
    public const FIELDS = ['delivery_zone' => 'delivery_zone_id', 'pickup_zone' => 'pickup_zone_id'];

    public function __construct(private readonly ZoneMatcher $matcher) {}

    /**
     * Complète la requête avec l'identifiant des zones données par leur nom (quand
     * l'identifiant manque). Renvoie les erreurs : champ => message.
     *
     * @return array<string, string>
     */
    public function resolveInto(Request $request, int $companyId): array
    {
        $errors = [];
        $zones = null;

        foreach (self::FIELDS as $nameField => $idField) {
            $name = $request->input($nameField);
            if (! is_string($name) || trim($name) === '' || filled($request->input($idField))) {
                continue;
            }

            $zones ??= Zone::forCompany($companyId)->active()->with('parent')->get();
            $found = $this->match($name, $zones);

            if ($found->count() === 1) {
                $request->merge([$idField => $found->first()->id]);
            } elseif ($found->isEmpty()) {
                $errors[$nameField] = "Zone inconnue : « {$name} ». La liste des zones est donnée par GET /zones.";
            } else {
                $errors[$nameField] = "Zone ambiguë : « {$name} » peut être ".$found->take(5)->map->fullName()->join(', ', ' ou ').'. Précisez le quartier ou indiquez l\'identifiant de zone.';
            }
        }

        return $errors;
    }

    /**
     * Nom exact (« Cocody › Angré », « Angré »), puis zones citées dans le texte, puis début de nom.
     *
     * @param  Collection<int, Zone>  $zones
     * @return Collection<int, Zone>
     */
    public function match(string $name, Collection $zones): Collection
    {
        $exact = $zones->filter(fn (Zone $z) => ZoneMatcher::normalize($z->fullName()) === ZoneMatcher::normalize($name));
        if ($exact->count() === 1) {
            return $exact->values();
        }

        $byName = $this->matcher->byName($name, $zones);
        if ($byName && $zones->filter(fn (Zone $z) => ZoneMatcher::normalize($z->name) === ZoneMatcher::normalize($name))->count() === 1) {
            return collect([$byName]);
        }

        return $this->matcher->answer($name, $zones);
    }
}
