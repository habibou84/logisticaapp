<?php

namespace App\Services\Destinations;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Destination;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Notifications\DestinationAlert;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Annuaire des destinations sur les courses : suggestions pendant la saisie, fiche remplie depuis la
 * destination choisie, annuaire enrichi et corrigé par chaque course (un bon numéro saisi remplace un numéro
 * « à vérifier »).
 */
class Destinations
{
    public function __construct(private readonly DestinationSettings $settings) {}

    /**
     * Destinations qui répondent à la saisie (nom, sigle, quartier, adresse, numéro) : celles déjà livrées pour
     * ce client d'abord, puis les plus livrées.
     *
     * @return Collection<int, Destination>
     */
    public function search(int $companyId, string $term, ?int $merchantId = null, bool $staff = false, int $limit = 8): Collection
    {
        $words = array_filter(explode(' ', Destination::normalize($term)), fn ($w) => mb_strlen($w) >= 2);
        if ($words === []) {
            return new Collection;
        }

        return Destination::query()->forCompany($companyId)
            ->with('zone:id,name,parent_id')
            ->when(! $staff, fn ($q) => $q->where('status', 'active'))
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->where('search', 'like', '%'.$word.'%');
                }
            })
            // Sigle exact, puis nom qui contient la saisie, puis déjà livrée pour ce client
            ->orderByRaw('CASE WHEN LOWER(acronym) = ? THEN 0 WHEN LOWER(name) LIKE ? THEN 1 ELSE 2 END', [mb_strtolower(trim($term)), '%'.mb_strtolower(trim($term)).'%'])
            ->when($merchantId, fn ($q) => $q->withCount(['orders as mine' => fn ($o) => $o->withoutGlobalScopes()->where('merchant_id', $merchantId)])->orderByDesc('mine'))
            ->orderByDesc('deliveries_count')->orderBy('name')
            ->limit($limit)->get();
    }

    /**
     * Nouvelle course : la destination choisie remplit ce qui manque (nom, numéro, commune, adresse, position).
     * Un numéro « à vérifier » n'est jamais repris : le bon numéro doit être saisi.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(Merchant $merchant, string $activity, array $data): array
    {
        $id = $data['destination_id'] ?? null;
        if (! $this->settings->appliesTo($merchant->company, $activity) || ! empty($data['to_department_id'])) {
            unset($data['destination_id']);

            return $data;
        }
        if ($id === null || $id === '') {
            return $data;
        }
        $destination = Destination::query()->forCompany($merchant->company_id)->find((int) $id)
            ?? throw new BusinessRuleException('Cette destination n\'existe plus dans l\'annuaire.', 'destination_id');
        $settings = $this->settings->for($merchant->company);

        $data['destination_id'] = $destination->id;
        $data['recipient_name'] = filled($data['recipient_name'] ?? null) ? $data['recipient_name'] : mb_substr($destination->label(), 0, 255);
        if (blank($data['recipient_phone'] ?? null)) {
            $data['recipient_phone'] = $destination->phone
                ?? throw new BusinessRuleException('Cette destination n\'a pas de numéro vérifié : saisissez le bon numéro'
                    .($destination->phones_raw ? " (ancien : {$destination->phones_raw})" : '').'. L\'annuaire sera corrigé.', 'recipient_phone');
            $data['recipient_phone2'] ??= $destination->phone2;
        }
        $data['delivery_zone_id'] ??= $destination->zone_id;
        $data['delivery_address'] = filled($data['delivery_address'] ?? null) ? $data['delivery_address'] : $destination->address;
        $data['delivery_landmark'] = filled($data['delivery_landmark'] ?? null) ? $data['delivery_landmark'] : $destination->landmark;
        if ($settings['use_location'] && $destination->hasLocation() && ! $destination->location_conflict) {
            $data['delivery_lat'] ??= $destination->lat;
            $data['delivery_lng'] ??= $destination->lng;
        }

        return $data;
    }

    /**
     * Après la course : l'annuaire apprend. Destination choisie : numéro corrigé, adresse ou commune qui
     * manquaient ; sinon une destination qui a le même numéro (ou le même nom dans la même commune) est
     * reliée, ou une nouvelle est ajoutée (directement ou à valider, selon le réglage).
     */
    public function learn(Order $order, User $actor): ?Destination
    {
        $company = Company::query()->find($order->company_id);
        if (! $this->settings->appliesTo($company, $order->activity?->value) || $order->to_department_id !== null) {
            return null;
        }
        $settings = $this->settings->for($company);
        $phone = DestinationPhones::valid($order->recipient_phone);

        $destination = $order->destination_id ? Destination::query()->forCompany($order->company_id)->find($order->destination_id) : null;
        $destination ??= $this->match($order->company_id, $phone, $order->recipient_name, $order->delivery_zone_id);
        if ($destination === null) {
            if ($settings['auto_add'] === 'off' || blank($order->recipient_name)) {
                return null;
            }
            $destination = Destination::create([
                'company_id' => $order->company_id,
                'name' => mb_substr(trim($order->recipient_name), 0, 255),
                'city' => $order->deliveryZone()->value('city'),
                'zone_id' => $order->delivery_zone_id,
                'address' => $order->delivery_address,
                'landmark' => $order->delivery_landmark,
                'phone' => $phone,
                'phone2' => DestinationPhones::valid($order->recipient_phone2),
                'source' => 'order',
                'status' => $settings['auto_add'] === 'pending' ? 'pending' : 'active',
                'created_by' => $actor->id,
            ]);
            if ($destination->status === 'pending') {
                $this->tellStaff($order->company_id, 'destination_pending', "📇 Destination à valider : {$destination->name}",
                    "Ajoutée par la course {$order->tracking_code}.", '/admin/annuaire?filtre=pending');
            }
        } elseif ($settings['auto_update']) {
            $this->correct($destination, $phone, $order);
        }

        $order->forceFill(['destination_id' => $destination->id])->saveQuietly();

        return $destination;
    }

    /**
     * Numéro saisi sur une course pour cette destination : il remplace les numéros « à vérifier » (le bon numéro
     * a été trouvé), ou s'ajoute aux autres ; adresse, repère et commune complétés s'ils manquaient.
     */
    public function correct(Destination $destination, ?string $phone, Order $order): void
    {
        if ($phone !== null && ! in_array($phone, $destination->phones(), true)) {
            if ($destination->phone_check || $destination->phone === null) {
                $destination->fill(['phone' => $phone, 'phone2' => $destination->phone ?? $destination->phone2, 'phone3' => $destination->phone ? $destination->phone2 : $destination->phone3, 'phone_check' => false]);
            } elseif ($destination->phone2 === null) {
                $destination->phone2 = $phone;
            } elseif ($destination->phone3 === null) {
                $destination->phone3 = $phone;
            }
        }
        $destination->zone_id ??= $order->delivery_zone_id;
        $destination->address = filled($destination->address) ? $destination->address : $order->delivery_address;
        $destination->landmark = filled($destination->landmark) ? $destination->landmark : $order->delivery_landmark;
        if ($destination->isDirty()) {
            $destination->save();
        }
    }

    /** Course livrée : la destination compte une livraison de plus */
    public function delivered(Order $order): void
    {
        if ($order->destination_id) {
            Destination::withoutGlobalScopes()->whereKey($order->destination_id)->update(['deliveries_count' => DB::raw('deliveries_count + 1'), 'last_used_at' => now()]);
        }
    }

    private function match(int $companyId, ?string $phone, ?string $name, ?int $zoneId): ?Destination
    {
        $query = Destination::query()->forCompany($companyId);
        if ($phone !== null) {
            $found = (clone $query)->where(fn ($q) => $q->where('phone', $phone)->orWhere('phone2', $phone)->orWhere('phone3', $phone))->first();
            if ($found) {
                return $found;
            }
        }
        $key = Destination::normalize($name);
        if ($key === '' || $zoneId === null) {
            return null;
        }

        return $query->where('zone_id', $zoneId)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $name))])->first()
            ?? $query->where('zone_id', $zoneId)->get(['id', 'name', 'acronym'])->first(fn (Destination $d) => Destination::normalize($d->name) === $key || Destination::normalize($d->acronym) === $key)?->fresh();
    }

    public function tellStaff(int $companyId, string $kind, string $title, string $body, string $href): void
    {
        $users = User::forCompany($companyId)->where('status', 'active')
            ->role([Role::Admin->value, Role::ActivityManager->value, Role::Dispatcher->value])->get();
        Notification::send($users, new DestinationAlert($kind, $title, $body, $href));
    }

    /** Affichage d'un numéro (« 07 48 20 86 66 ») */
    public static function display(?string $phone): ?string
    {
        return $phone ? PhoneNumber::display($phone) : null;
    }
}
