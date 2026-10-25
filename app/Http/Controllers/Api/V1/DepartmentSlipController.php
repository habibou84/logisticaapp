<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use App\Models\DepartmentSlip;
use App\Models\Order;
use App\Models\User;
use App\Services\Departments\Departments;
use App\Services\Departments\DepartmentSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bordereau du jour d'un département : ce qu'il a remis au livreur (pickup) ou ce qui lui a été livré
 * (delivery), à signer dans l'application par la personne du département, ou à imprimer.
 */
class DepartmentSlipController extends Controller
{
    public function __construct(private readonly Departments $departments, private readonly DepartmentSettings $settings) {}

    public function show(Request $request, Department $department): JsonResponse
    {
        $this->authorizeView($request->user(), $department);
        $data = $request->validate([
            'day' => ['nullable', 'date'],
            'kind' => ['nullable', Rule::in(DepartmentSlip::KINDS)],
        ]);
        $kind = $data['kind'] ?? 'pickup';
        $day = CarbonImmutable::parse($data['day'] ?? 'today')->toDateString();

        $slips = DepartmentSlip::query()->where('department_id', $department->id)->where('kind', $kind)->whereDate('day', $day)->latest('id')->get();

        return response()->json(['data' => [
            'company' => Company::query()->whereKey($department->company_id)->value('name'),
            'merchant' => $department->merchant()->value('business_name'),
            'department' => [
                'id' => $department->id, 'name' => $department->name, 'label' => $department->label(), 'location' => $department->location,
                'manager_name' => $department->manager_name, 'cost_center' => $department->cost_center,
            ],
            'kind' => $kind,
            'day' => $day,
            'enabled' => $this->settings->for($department->merchant()->firstOrFail())['slips'],
            'orders' => $this->orders($department, $kind, $day)->map(fn (Order $o) => $this->line($o, $kind))->values(),
            'slips' => $slips->map(fn (DepartmentSlip $s) => [
                'id' => $s->id,
                'order_ids' => $s->order_ids,
                'signed_by_name' => $s->signed_by_name,
                'signed_by_role' => $s->signed_by_role,
                'signed_at' => $s->signed_at,
                'signature_url' => $s->signature_path ? "/api/v1/department-slips/{$s->id}/signature" : null,
            ])->values(),
        ]]);
    }

    /** Signature du bordereau par la personne du département : la liste du moment est gardée */
    public function sign(Request $request, Department $department): JsonResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $department);
        $data = $request->validate([
            'day' => ['required', 'date'],
            'kind' => ['required', Rule::in(DepartmentSlip::KINDS)],
            'signed_by_name' => ['required', 'string', 'max:120'],
            'signed_by_role' => ['nullable', 'string', 'max:80'],
            'signature' => ['required', 'string', 'max:700000'],
        ], [], ['signed_by_name' => 'nom de la personne qui signe']);
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data['signature'], $m) || ($png = base64_decode($m[1], true)) === false || $png === '') {
            throw new BusinessRuleException('Signature illisible.', 'signature');
        }
        $day = CarbonImmutable::parse($data['day'])->toDateString();
        $ids = $this->orders($department, $data['kind'], $day)->modelKeys();
        if ($ids === []) {
            throw new BusinessRuleException('Aucune course sur ce bordereau.', 'day');
        }

        $path = "departments/{$department->id}/slip-{$data['kind']}-{$day}-".now()->format('His').'.png';
        Storage::disk('local')->put($path, $png);
        $slip = DepartmentSlip::create([
            'company_id' => $department->company_id,
            'merchant_id' => $department->merchant_id,
            'department_id' => $department->id,
            'kind' => $data['kind'],
            'day' => $day,
            'order_ids' => $ids,
            'signed_by_name' => trim($data['signed_by_name']),
            'signed_by_role' => filled($data['signed_by_role'] ?? null) ? trim($data['signed_by_role']) : null,
            'signature_path' => $path,
            'signed_at' => now(),
            'created_by' => $user->id,
        ]);

        return response()->json(['data' => ['id' => $slip->id, 'orders' => count($ids)]], 201);
    }

    public function signature(Request $request, DepartmentSlip $departmentSlip): StreamedResponse
    {
        $this->authorizeView($request->user(), $departmentSlip->department()->firstOrFail());
        abort_if($departmentSlip->signature_path === null || ! Storage::disk('local')->exists($departmentSlip->signature_path), 404);

        return Storage::disk('local')->response($departmentSlip->signature_path);
    }

    /**
     * Courses du bordereau : remises au livreur ce jour-là par le département (ou prévues ce jour-là, pas
     * encore remises), ou livrées ce jour-là au département (courrier interne).
     *
     * @return Collection<int, Order>
     */
    private function orders(Department $department, string $kind, string $day): Collection
    {
        $cancelled = [OrderStatus::Cancelled->value, OrderStatus::Rejected->value];

        return Order::query()->with(['deliveryZone:id,name', 'toDepartment', 'pickupCourier.user:id,name', 'deliveryCourier.user:id,name'])
            ->whereNotIn('status', $cancelled)
            ->when($kind === 'pickup', fn ($q) => $q->where('department_id', $department->id)
                ->where(fn ($q) => $q->whereDate('picked_up_at', $day)
                    ->orWhere(fn ($q) => $q->whereNull('picked_up_at')->whereDate('created_at', $day))))
            ->when($kind === 'delivery', fn ($q) => $q->where('to_department_id', $department->id)->whereDate('delivered_at', $day))
            ->orderBy('id')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function line(Order $o, string $kind): array
    {
        return [
            'id' => $o->id,
            'tracking_code' => $o->tracking_code,
            'reference' => $o->merchant_reference,
            'recipient' => $o->toDepartment?->label() ?? ($o->recipient_name ?: $o->recipient_phone),
            'zone' => $o->deliveryZone?->name,
            'shipment' => $o->shipment_type ? ($o->shipment_quantity > 1 ? "{$o->shipment_quantity} × {$o->shipment_type->label()}" : $o->shipment_type->label()) : null,
            'requested_by' => $o->requested_by,
            'status_label' => $o->statusLabel(),
            'picked_up_at' => $o->picked_up_at,
            'delivered_at' => $o->delivered_at,
            'handed_by' => $o->handed_by_name ? trim($o->handed_by_name.($o->handed_by_role ? " ({$o->handed_by_role})" : '')) : null,
            'received_by' => $o->received_by_name ? trim($o->received_by_name.($o->received_by_role ? " ({$o->received_by_role})" : '')) : null,
            'courier' => ($kind === 'pickup' ? $o->pickupCourier : $o->deliveryCourier)?->user?->name,
        ];
    }

    /** Personnel qui voit les courses, ou compte du client (limité à ses départements s'il l'est) */
    private function authorizeView(User $user, Department $department): void
    {
        if ($user->merchant_id !== null) {
            $scope = $this->departments->scopeFor($user);
            abort_unless($user->merchant_id === $department->merchant_id && ($scope === null || in_array($department->id, $scope, true)), 403);

            return;
        }
        abort_unless(! $user->isCourier() && $user->can(Permission::OrdersView->value), 403);
    }
}
