<?php

namespace App\Services\Zones;

use App\Models\Company;
use App\Models\InteriorRate;
use App\Models\Zone;
use App\Services\CompanyProvisioner;
use App\Services\Pricing\ClientPricing;
use Illuminate\Support\Collection;
use Jibiat\Socle\Places\Places;

/**
 * Zones standard de l'entreprise (repris de Delivery App) : une zone par lieu de la liste des lieux (communes et
 * quartiers d'Abidjan, villes de l'intérieur et leurs quartiers), rangée sous sa commune ou sa ville et portant le
 * code du lieu. L'entreprise n'a plus à saisir ses communes, quartiers et villes : elle coche où elle collecte, où
 * elle livre, ce que couvrent les forfaits, et fixe ses prix.
 *
 * - Abidjan : communes livrées, quartiers comme leur commune ; communes du forfait cochées à la première mise en
 *   service (distribution, MAD).
 * - Villes de l'intérieur : livrées en distribution et en MAD au tarif de la ville. Elles arrivent livrées si un
 *   tarif existe déjà pour la ville, sinon masquées (« On livre » les ouvre) ; jamais de collecte par défaut.
 *
 * Une zone qui existe déjà avec le même nom au bon endroit (une commune ou une ville, ou un quartier sous la sienne)
 * est rattachée à son lieu, sans rien changer d'autre. Un quartier saisi ailleurs (« Angré » au premier niveau) n'est
 * pas touché et son lieu n'est pas recréé en double. Relancer ne crée pas de doublon.
 */
class StandardZones
{
    /**
     * @return array{created: int, linked: int}
     */
    public function syncAll(): array
    {
        $total = ['created' => 0, 'linked' => 0];
        Company::query()->select('id')->eachById(function (Company $company) use (&$total) {
            foreach ($this->sync($company) as $key => $n) {
                $total[$key] += $n;
            }
        });

        return $total;
    }

    /**
     * @return array{created: int, linked: int}
     */
    public function sync(Company $company): array
    {
        $zones = Zone::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $fresh = $zones->isEmpty();
        $byCode = $zones->whereNotNull('place_code')->keyBy('place_code');
        $counts = ['created' => 0, 'linked' => 0];
        $sort = (int) $zones->max('sort_order');
        $pricedCities = InteriorRate::withoutGlobalScopes()->where('company_id', $company->id)->pluck('city')
            ->map(fn ($city) => ClientPricing::cityKey($city))->unique()->flip();

        foreach (PlaceList::all() as $place) {
            // Abidjan n'est pas une zone : ses communes sont les zones du premier niveau
            if ($place['code'] === 'abidjan' || $byCode->has($place['code'])) {
                continue;
            }
            $parent = $place['type'] === Places::NEIGHBORHOOD ? $byCode->get($place['parent']) : null;
            if ($place['type'] === Places::NEIGHBORHOOD && $parent === null) {
                continue;
            }

            $sameName = $this->sameName($zones, $place['name']);
            $inPlace = $sameName->first(fn (Zone $z) => $z->place_code === null && $z->parent_id === $parent?->id && ! $z->is_shipping);
            if ($inPlace !== null) {
                $inPlace->forceFill(['place_code' => $place['code']])->save();
                $byCode->put($place['code'], $inPlace);
                $counts['linked']++;

                continue;
            }
            // Même nom ailleurs (quartier saisi sans sa commune) : pas de doublon
            if ($sameName->contains(fn (Zone $z) => $z->place_code === null && ! $z->is_shipping)) {
                continue;
            }

            $abidjan = PlaceList::inAbidjan($place['code']);
            $city = PlaceList::city($place['code']);
            $zone = Zone::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'parent_id' => $parent?->id,
                'place_code' => $place['code'],
                'name' => $place['name'],
                'city' => $city,
                'is_active' => match (true) {
                    $parent !== null => (bool) $parent->is_active,
                    $abidjan => true,
                    default => $pricedCities->has(ClientPricing::cityKey($city)),
                },
                'is_pickup' => $abidjan,
                'in_forfait' => $fresh && $abidjan && $parent === null && in_array($place['name'], CompanyProvisioner::FORFAIT_COMMUNES, true),
                'sort_order' => ++$sort,
            ]);
            $zones->push($zone);
            $byCode->put($place['code'], $zone);
            $counts['created']++;
        }

        return $counts;
    }

    /**
     * Zone de l'entreprise pour une ville de l'intérieur (la ville elle-même, pas un de ses quartiers), ou null.
     */
    public static function cityZone(int $companyId, string $city): ?Zone
    {
        $key = ClientPricing::cityKey($city);

        return Zone::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('parent_id')->where('is_shipping', false)->get()
            ->sortBy(fn (Zone $z) => $z->place_code === null ? 1 : 0)
            ->first(fn (Zone $z) => ClientPricing::cityKey($z->city) === $key && ClientPricing::cityKey($z->name) === $key);
    }

    /**
     * @param  Collection<int, Zone>  $zones
     * @return Collection<int, Zone>
     */
    private function sameName(Collection $zones, string $name): Collection
    {
        $needle = Places::normalize($name);

        return $zones->filter(fn (Zone $z) => Places::normalize($z->name) === $needle);
    }
}
