<?php

namespace App\Services\Departments;

use App\Enums\Activity;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Department;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Notifications\ClientBillingAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Départements d'un client sur ses courses : lequel envoie (obligatoire ou non), vers lequel en courrier
 * interne, qui l'a demandée ; adresse d'un département sur un autre site ; employés limités à leurs
 * départements ; quota du mois de chaque département (avertissements, courses refusées au-delà).
 */
class Departments
{
    /** Seuils d'avertissement du quota (%) */
    public const THRESHOLDS = [100, 80];

    /** @var array<int, list<int>|null> */
    private array $scopes = [];

    public function __construct(private readonly DepartmentSettings $settings) {}

    /**
     * Départements actifs du client (vide : le client n'en a pas, ou ils ne sont pas proposés).
     *
     * @return Collection<int, Department>
     */
    public function of(Merchant $merchant): Collection
    {
        if (! $this->settings->company($merchant->company)['enabled']) {
            return collect();
        }

        return Department::forCompany($merchant->company_id)->where('merchant_id', $merchant->id)->active()->ordered()->get();
    }

    /**
     * Départements d'un compte client limité aux siens (employé rattaché, réglage « ne voit que les siens ») ;
     * null : il voit tout le client (gérant, employé sans département, personnel de l'agence).
     *
     * @return list<int>|null
     */
    public function scopeFor(User $user): ?array
    {
        if ($user->merchant_id === null || $user->hasRole(Role::MerchantOwner->value)) {
            return null;
        }
        if (array_key_exists($user->id, $this->scopes)) {
            return $this->scopes[$user->id];
        }
        $ids = $user->departments()->where('active', true)->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
        $merchant = Merchant::withoutGlobalScopes()->find($user->merchant_id);

        return $this->scopes[$user->id] = $ids !== [] && $merchant && $this->settings->for($merchant)['staff_own_only'] ? $ids : null;
    }

    /** Comptes et rattachements changés : relus à la prochaine demande */
    public function forget(): void
    {
        $this->scopes = [];
    }

    /**
     * Prépare une nouvelle course : département qui envoie (choisi, celui de l'employé s'il n'en a qu'un),
     * « demandé par », adresse de ramassage du département sur un autre site, et en courrier interne le
     * département destinataire (son adresse et son responsable deviennent ceux de la livraison).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(User $actor, Merchant $merchant, Activity $activity, array $data): array
    {
        $departments = $activity === Activity::Ecommerce ? collect() : $this->of($merchant);
        if ($departments->isEmpty()) {
            unset($data['department_id'], $data['to_department_id']);

            return $data;
        }
        $settings = $this->settings->for($merchant);
        $scope = $this->scopeFor($actor);
        $allowed = $scope === null ? $departments : $departments->whereIn('id', $scope)->values();

        $id = $data['department_id'] ?? null;
        if (($id === null || $id === '') && $allowed->count() === 1 && ($settings['required'] || $scope !== null)) {
            $id = $allowed->first()->id;
        }
        $department = $id !== null && $id !== '' ? $allowed->firstWhere('id', (int) $id) : null;
        if ($id !== null && $id !== '' && $department === null) {
            throw new BusinessRuleException($departments->firstWhere('id', (int) $id)
                ? 'Vous ne pouvez commander que pour votre département.'
                : 'Ce département n\'existe pas ou n\'est plus actif.', 'department_id');
        }
        if ($department === null && ($settings['required'] || $scope !== null)) {
            throw new BusinessRuleException('Choisissez le département qui envoie la course.', 'department_id');
        }
        $data['department_id'] = $department?->id;

        if ($settings['requested_by'] === 'off') {
            unset($data['requested_by']);
        } elseif ($settings['requested_by'] === 'required' && blank($data['requested_by'] ?? null)) {
            throw new BusinessRuleException('Indiquez qui demande la course dans le département.', 'requested_by');
        }

        // Département sur un autre site : on ramasse chez lui ; sinon chez le client, avec son emplacement
        if ($department && empty($data['pickup_hub_id'])) {
            $own = $department->hasOwnAddress();
            $data['pickup_zone_id'] ??= $own ? $department->pickup_zone_id : null;
            $data['pickup_address'] ??= $own ? $department->pickup_address : null;
            $data['pickup_landmark'] ??= $this->join($own ? $department->pickup_landmark : null, $department->location);
            $data['pickup_lat'] ??= $own ? $department->pickup_lat : null;
            $data['pickup_lng'] ??= $own ? $department->pickup_lng : null;
            $data['pickup_contact_name'] ??= $department->manager_name ? "{$department->manager_name} ({$department->name})" : "{$merchant->business_name} · {$department->name}";
            $data['pickup_phone'] ??= $department->phone;
        }

        // Courrier interne : vers un autre département du client
        $to = $data['to_department_id'] ?? null;
        if ($to !== null && $to !== '') {
            if (! $settings['internal_mail']) {
                throw new BusinessRuleException('Le courrier interne n\'est pas ouvert pour ce client.', 'to_department_id');
            }
            $target = $departments->firstWhere('id', (int) $to)
                ?? throw new BusinessRuleException('Ce département destinataire n\'existe pas ou n\'est plus actif.', 'to_department_id');
            if ($department && $target->id === $department->id) {
                throw new BusinessRuleException('Le département destinataire doit être un autre département.', 'to_department_id');
            }
            $data['to_department_id'] = $target->id;
            $data['recipient_name'] = filled($data['recipient_name'] ?? null) ? $data['recipient_name'] : ($target->manager_name ?: "{$merchant->business_name} · {$target->name}");
            $data['recipient_phone'] = filled($data['recipient_phone'] ?? null) ? $data['recipient_phone'] : ($target->phone ?: $merchant->phone);
            $data['delivery_zone_id'] ??= $target->hasOwnAddress() ? $target->pickup_zone_id : $merchant->pickup_zone_id;
            $data['delivery_address'] = filled($data['delivery_address'] ?? null) ? $data['delivery_address'] : ($target->hasOwnAddress() ? $target->pickup_address : $merchant->pickup_address);
            $data['delivery_landmark'] = filled($data['delivery_landmark'] ?? null) ? $data['delivery_landmark'] : $this->join($target->hasOwnAddress() ? $target->pickup_landmark : $merchant->pickup_landmark, $target->location);
            if ($target->hasOwnAddress()) {
                $data['delivery_lat'] ??= $target->pickup_lat;
                $data['delivery_lng'] ??= $target->pickup_lng;
            } else {
                $data['delivery_lat'] ??= $merchant->pickup_lat;
                $data['delivery_lng'] ??= $merchant->pickup_lng;
            }
        } else {
            $data['to_department_id'] = null;
        }

        return $data;
    }

    /**
     * Quota du mois du département : la course est refusée au-delà si l'administrateur l'a voulu (l'agence
     * peut toujours passer outre).
     */
    public function assertQuota(User $actor, Order $order): void
    {
        if ($order->department_id === null || $actor->merchant_id === null) {
            return;
        }
        $department = Department::withoutGlobalScopes()->find($order->department_id);
        if ($department?->monthly_quota === null || ! $this->settings->for($this->merchantOf($order))['quota_block']) {
            return;
        }
        $used = $this->used($department, $order->created_at ? CarbonImmutable::parse($order->created_at) : CarbonImmutable::now(), $order->id);
        if ($used + max(1, (int) $order->billing_units) > $department->monthly_quota) {
            throw new BusinessRuleException("Quota du mois de {$department->name} atteint ({$used} sur {$department->monthly_quota}) : voyez avec votre responsable ou l'agence.", 'department_id');
        }
    }

    /**
     * Envois du mois d'un département (plis et chèques comptés un à un s'ils sont facturés ainsi).
     */
    public function used(Department $department, ?CarbonImmutable $month = null, ?int $exceptOrderId = null): int
    {
        $month ??= CarbonImmutable::now();
        $timezone = $this->timezone($department);
        $from = $month->setTimezone($timezone)->startOfMonth();

        return (int) Order::withoutGlobalScopes()->where('department_id', $department->id)->whereNull('deleted_at')
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])
            ->where('created_at', '>=', $from->utc())->where('created_at', '<', $from->addMonth()->utc())
            ->when($exceptOrderId, fn ($q) => $q->whereKeyNot($exceptOrderId))
            ->sum('billing_units');
    }

    /**
     * Quota et consommation du mois d'un département.
     *
     * @return array{quota: ?int, used: int, percent: ?int}
     */
    public function usage(Department $department, ?CarbonImmutable $month = null): array
    {
        $used = $this->used($department, $month);
        $quota = $department->monthly_quota;

        return ['quota' => $quota, 'used' => $used, 'percent' => $quota ? (int) min(999, round(100 * $used / $quota)) : null];
    }

    /**
     * Après une course : avertissement à 80 % puis à 100 % du quota du département, au gérant du client et aux
     * comptes du département (une fois par seuil et par mois).
     */
    public function afterOrder(Order $order): void
    {
        if ($order->department_id === null) {
            return;
        }
        try {
            $department = Department::withoutGlobalScopes()->find($order->department_id);
            if ($department?->monthly_quota === null || ! $this->settings->for($this->merchantOf($order))['quota_alerts']) {
                return;
            }
            $usage = $this->usage($department);
            $reached = collect(self::THRESHOLDS)->first(fn (int $t) => $usage['percent'] >= $t);
            $month = CarbonImmutable::now($this->timezone($department))->format('Y-m');
            [$lastMonth, $last] = array_pad(explode(':', (string) $department->quota_alert), 2, null);
            if ($reached === null || ($lastMonth === $month && (int) $last >= $reached)) {
                return;
            }
            $department->forceFill(['quota_alert' => "{$month}:{$reached}"])->save();

            $users = User::withoutGlobalScopes()->where('merchant_id', $department->merchant_id)->where('status', 'active')
                ->where(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', Role::MerchantOwner->value))
                    ->orWhereHas('departments', fn ($d) => $d->whereKey($department->id)))
                ->get();
            Notification::send($users, new ClientBillingAlert('department_quota',
                $reached >= 100 ? "🏢 Quota du mois atteint · {$department->name}" : "🏢 Quota utilisé à {$reached} % · {$department->name}",
                "{$usage['used']} envois sur {$usage['quota']} prévus ce mois-ci.", '/marchand/courses'));
        } catch (Throwable $e) {
            // Un avertissement manqué ne doit jamais empêcher la course
            Log::warning('Avertissement de quota non envoyé', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function merchantOf(Order $order): Merchant
    {
        return $order->relationLoaded('merchant') ? $order->merchant : Merchant::withoutGlobalScopes()->findOrFail($order->merchant_id);
    }

    private function timezone(Department $department): string
    {
        return Company::query()->whereKey($department->company_id)->value('timezone') ?: config('app.timezone');
    }

    private function join(?string ...$parts): ?string
    {
        $text = implode(' · ', array_filter(array_map(fn ($p) => trim((string) $p), $parts)));

        return $text === '' ? null : $text;
    }
}
