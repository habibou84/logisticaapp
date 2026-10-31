<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\EarningType;
use App\Events\CourierLocationUpdated;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CourierResource;
use App\Http\Resources\V1\OrderAssignmentResource;
use App\Models\CourierEarning;
use App\Models\CourierMessage;
use App\Models\OrderAssignment;
use App\Services\Assignment\AutoAssigner;
use App\Services\Assignment\MadCouriers;
use App\Services\Couriers\CourierCapabilities;
use App\Services\Couriers\CourierTracker;
use App\Services\Finance\CourierPay;
use App\Services\Orders\DeliveryDayRule;
use App\Services\Orders\OrderDispatcher;
use App\Services\Vehicles\VehicleChecks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Espace du livreur connecté : ses missions, sa disponibilité, sa position.
 */
class CourierSpaceController extends Controller
{
    public function missions(Request $request, CourierPay $pay): AnonymousResourceCollection
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');
        $courier->touchSeen();

        $assignments = OrderAssignment::query()
            ->where('courier_id', $courier->id)
            ->when(
                $request->boolean('history'),
                fn ($q) => $q->whereNotIn('status', ['assigned', 'accepted', 'in_progress'])->whereDate('updated_at', today()),
                fn ($q) => $q->active(),
            )
            // Frais déjà déclarés par ce livreur sur la course
            ->with(['order' => fn ($q) => $q->with([
                ...OrderController::LIST_RELATIONS,
                'billingPlan',
                'expenses' => fn ($e) => $e->active()->where('courier_id', $courier->id)->orderBy('id'),
            ])])
            ->orderByRaw("CASE type WHEN 'pickup' THEN 0 WHEN 'delivery' THEN 1 ELSE 2 END")
            ->orderBy('assigned_at')
            ->get();

        // Livraisons d'un autre jour (réglage par activité) : pas montrées, elles se règlent à l'agence
        $otherDays = 0;
        if (! $request->boolean('history')) {
            $rule = app(DeliveryDayRule::class);
            $assignments = $assignments->filter(function (OrderAssignment $a) use ($rule, &$otherDays) {
                $keep = $a->type !== AssignmentType::Delivery || $rule->allows($a->order);
                $otherDays += $keep ? 0 : 1;

                return $keep;
            })->values();
        }

        // Consignes de l'agence non lues, par course
        $unread = CourierMessage::where('courier_id', $courier->id)->whereNull('read_at')
            ->whereIn('order_id', $assignments->pluck('order_id'))
            ->groupBy('order_id')->selectRaw('order_id, COUNT(*) AS total')->pluck('total', 'order_id');
        $assignments->each(fn (OrderAssignment $a) => $a->setAttribute('unread_messages', (int) ($unread[$a->order_id] ?? 0)));

        // Gain : attendu selon le plan pour une mission en cours, acquis pour une mission terminée
        if ($request->boolean('history')) {
            $earned = CourierEarning::where('courier_id', $courier->id)->whereIn('order_id', $assignments->pluck('order_id'))
                ->whereIn('type', [EarningType::Pickup, EarningType::Delivery, EarningType::FailedAttempt, EarningType::Return])
                ->get(['order_id', 'type', 'amount'])
                ->groupBy(fn (CourierEarning $e) => $e->order_id.'-'.match ($e->type) {
                    EarningType::Pickup => AssignmentType::Pickup->value,
                    EarningType::Return => AssignmentType::Return->value,
                    default => AssignmentType::Delivery->value,
                });
            $assignments->each(fn (OrderAssignment $a) => $a->setAttribute('gain', $earned->get($a->order_id.'-'.$a->type->value)?->sum('amount') ?: null));
        } elseif (app(CourierCapabilities::class)->for($courier)['see_gain']) {
            // Gain prévu : seulement si l'entreprise le montre à ce livreur
            $assignments->each(fn (OrderAssignment $a) => $a->setAttribute('gain', $pay->expectedFor($courier, $a->type, $a->order)));
        }

        // Mission acceptée pas encore commencée : le livreur peut la rendre (empêchement), sauf missions d'office sans refus
        $canReport = $courier->canReportImpediment();
        $assignments->each(fn (OrderAssignment $a) => $a->setAttribute('can_report_impediment', $canReport && $a->status === AssignmentStatus::Accepted));

        return OrderAssignmentResource::collection($assignments)->additional(['meta' => [
            'acceptance' => $courier->acceptanceMode(),
            // Livraisons confiées mais prévues un autre jour (cachées)
            'other_days' => $otherDays,
            // Mis à disposition d'un client aujourd'hui (bandeau de l'application)
            'mad' => app(MadCouriers::class)->of($courier),
            // Toutes consignes non lues (y compris sur des missions terminées)
            'unread_messages' => CourierMessage::where('courier_id', $courier->id)->whereNull('read_at')->where('created_at', '>=', now()->subDays(2))->count(),
            // Véhicule du jour, état des lieux à faire, entretien à prévoir
            'vehicle' => app(VehicleChecks::class)->summary($courier),
        ]]);
    }

    public function accept(Request $request, OrderAssignment $assignment, OrderDispatcher $dispatcher): OrderAssignmentResource
    {
        $assignment = $dispatcher->respond($request->user(), $assignment, true);

        return OrderAssignmentResource::make($assignment->load(['order' => fn ($q) => $q->with(OrderController::LIST_RELATIONS)]));
    }

    /**
     * Refus d'une mission, ou empêchement sur une mission acceptée pas encore commencée. Un ramassage du planning
     * passe au remplaçant, une livraison automatique à un autre livreur ; sinon la course revient à l'équipe, prévenue.
     */
    public function refuse(Request $request, OrderAssignment $assignment, OrderDispatcher $dispatcher, AutoAssigner $auto): OrderAssignmentResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motif']);
        $impediment = $assignment->status === AssignmentStatus::Accepted;

        $assignment = $dispatcher->respond($request->user(), $assignment, false, $data['reason']);

        $after = ($impediment ? 'empêchement de ' : 'refus de ').$request->user()->name;
        match ($assignment->type) {
            AssignmentType::Pickup => $auto->pickup($assignment->order, $after),
            AssignmentType::Delivery => $auto->delivery($assignment->order, $after),
            default => null,
        };

        return OrderAssignmentResource::make($assignment);
    }

    public function updateStatus(Request $request, CourierTracker $tracker): CourierResource
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');
        $courier->touchSeen();

        $data = $request->validate([
            'is_available' => ['sometimes', 'boolean'],
            'lat' => ['required_with:lng', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:lat', 'nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        if (array_key_exists('is_available', $data)) {
            // État des lieux du véhicule à faire avant de se mettre en service (si l'entreprise le veut)
            if ($data['is_available'] && ! $courier->is_available && app(VehicleChecks::class)->blocksService($courier)) {
                throw new BusinessRuleException('Faites d\'abord l\'état des lieux de votre véhicule.', 'vehicle');
            }
            $courier->is_available = $data['is_available'];
        }

        if (isset($data['lat'], $data['lng'])) {
            $courier->forceFill([
                'current_lat' => $data['lat'],
                'current_lng' => $data['lng'],
                'last_location_at' => now(),
            ]);
        }

        $changed = $courier->isDirty(['is_available', 'current_lat', 'current_lng', 'last_location_at']);
        $courier->save();

        // Historique des trajets, pendant le service uniquement
        if (isset($data['lat'], $data['lng']) && $courier->is_available) {
            $tracker->record($courier, (float) $data['lat'], (float) $data['lng'], $data['accuracy'] ?? null);
        }

        // Carte des livreurs en direct (personnel de l'entreprise)
        if ($changed) {
            CourierLocationUpdated::dispatch($courier);
        }

        return CourierResource::make($courier->load(['user', 'zones'])->loadCount('activeAssignments'));
    }
}
