<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\OrderProposal;
use App\Models\User;
use App\Notifications\OrderAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Événements des courses : report et retour du livreur validés par l'équipe ou le client avant de prendre effet,
 * relancés tant que personne n'a répondu ; événements « information » qui ne changent rien à la course.
 */
class OrderProposalTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function reason(string $code): int
    {
        return IncidentReason::where('code', $code)->value('id');
    }

    private function outForDelivery(): Order
    {
        $order = $this->createOrder();
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierB->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        $this->as($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();

        return $order->fresh();
    }

    private function courierPostpones(Order $order, string $date): void
    {
        $this->as($this->courierB->user)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => 'delivery_failed',
            'incident_reason_id' => $this->reason('postponed'),
            'rescheduled_to' => $date,
            'note' => 'Le client est au travail',
        ])->assertOk()->assertJsonPath('data.status', 'delivery_failed');
    }

    public function test_courier_postponement_waits_for_validation_and_the_team_approves_it(): void
    {
        Notification::fake();
        $order = $this->outForDelivery();
        $date = today()->addDays(2)->toDateString();
        $this->courierPostpones($order, $date);

        // Pas encore reporté : la course reste en échec, sans tentative comptée
        $order->refresh();
        $this->assertSame('delivery_failed', $order->status->value);
        $this->assertSame(0, $order->attempts_count);
        $proposal = OrderProposal::sole();
        $this->assertSame(['reschedule', 'pending', $date], [$proposal->kind, $proposal->status, $proposal->proposed_date->toDateString()]);
        // Le client est prévenu qu'il a à confirmer
        Notification::assertSentTo($this->merchantUser, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'proposal');

        // Fiche de la course et file « À valider »
        $this->as($this->dispatcher)->getJson("/api/v1/orders/{$order->id}")
            ->assertJsonPath('data.proposal.kind', 'reschedule')
            ->assertJsonPath('data.proposal.reason', 'Le destinataire demande à être livré un autre jour')
            ->assertJsonPath('data.proposal.note', 'Le client est au travail')
            ->assertJsonPath('data.proposal.can_decide', true);
        $this->assertSame([$order->id], collect($this->getJson('/api/v1/orders?queue=to_validate')->json('data'))->pluck('id')->all());

        // La remontée ne se marque pas « traitée » sans décision
        $event = $proposal->order_event_id;
        $this->postJson("/api/v1/field-reports/{$event}/handle")->assertUnprocessable();
        $this->assertSame('pending', $this->getJson('/api/v1/field-reports?kind=to_validate')->json('data.0.proposal.status'));

        $this->postJson("/api/v1/orders/{$order->id}/proposal/approve")->assertOk()
            ->assertJsonPath('data.status', 'rescheduled')
            ->assertJsonPath('data.delivery.scheduled_date', $date)
            ->assertJsonPath('data.proposal', null);
        $this->assertSame('approved', $proposal->fresh()->status);
        $this->assertSame('company', $proposal->fresh()->decided_by_type);
        // Remontée traitée en même temps
        $this->assertStringStartsWith('Report validé', $this->getJson('/api/v1/field-reports?state=handled')->json('data.0.review.comment'));
        $this->getJson('/api/v1/orders?queue=to_validate')->assertJsonCount(0, 'data');
    }

    public function test_merchant_can_approve_or_reject_and_a_rejection_leaves_the_order_failed(): void
    {
        $order = $this->outForDelivery();
        $this->courierPostpones($order, today()->addDay()->toDateString());

        // Le livreur ne valide pas lui-même
        $this->as($this->courierB->user)->postJson("/api/v1/orders/{$order->id}/proposal/approve")->assertForbidden();

        // Refus : un motif est demandé, la course reste en échec et l'équipe décide
        $this->as($this->merchantUser)->postJson("/api/v1/orders/{$order->id}/proposal/reject")->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/orders/{$order->id}/proposal/reject", ['note' => 'Le client n\'a jamais demandé de report'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivery_failed')
            ->assertJsonPath('data.proposal', null)
            ->assertJsonPath('data.needs_decision', true);
        $proposal = OrderProposal::sole();
        $this->assertSame(['rejected', 'merchant'], [$proposal->status, $proposal->decided_by_type]);
        $this->assertStringContainsString('refusé par le client', $order->events()->latest('id')->value('note'));
        // Refus du client : l'équipe doit encore décider, la remontée reste ouverte
        $this->as($this->dispatcher)->getJson('/api/v1/field-reports')->assertJsonPath('data.0.review', null);

        // Plus rien à valider
        $this->as($this->merchantUser)->postJson("/api/v1/orders/{$order->id}/proposal/approve")
            ->assertUnprocessable();

        // Un autre report proposé, validé par le client
        $other = $this->outForDelivery();
        $this->courierPostpones($other, today()->addDay()->toDateString());
        $this->as($this->merchantUser)->postJson("/api/v1/orders/{$other->id}/proposal/approve")->assertOk()
            ->assertJsonPath('data.status', 'rescheduled');
    }

    public function test_courier_refusal_returns_only_after_validation(): void
    {
        $order = $this->outForDelivery();
        $this->as($this->courierB->user)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => 'delivery_failed',
            'incident_reason_id' => $this->reason('refused'),
        ])->assertOk()
            ->assertJsonPath('data.status', 'delivery_failed')
            ->assertJsonPath('data.return_requested', false)
            ->assertJsonPath('data.attempts_count', 1);
        $this->assertSame('return', OrderProposal::sole()->kind);

        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/proposal/approve")->assertOk()
            ->assertJsonPath('data.return_requested', true)
            ->assertJsonPath('data.return_cause', 'refused');
    }

    public function test_another_decision_replaces_the_proposal_and_a_past_date_needs_a_new_one(): void
    {
        $order = $this->outForDelivery();
        $this->courierPostpones($order, today()->addDay()->toDateString());

        // Le jour proposé est passé : il faut une nouvelle date
        $this->travel(3)->days();
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/proposal/approve")
            ->assertUnprocessable()->assertJsonPath('message', 'La date proposée est passée : choisissez une nouvelle date.');
        $this->assertSame('pending', OrderProposal::sole()->status);

        // L'agence organise le retour : la proposition est remplacée
        $this->postJson("/api/v1/orders/{$order->id}/return-request")->assertOk();
        $this->assertSame('closed', OrderProposal::sole()->status);
    }

    public function test_pending_proposals_are_reminded_to_the_team_and_the_merchant_until_someone_answers(): void
    {
        $order = $this->outForDelivery();
        $this->courierPostpones($order, today()->addDays(3)->toDateString());
        Notification::fake();

        $this->artisan('field-reports:remind');
        Notification::assertNothingSentTo($this->dispatcher);

        $this->travel(61)->minutes();
        $this->artisan('field-reports:remind');
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'proposal_reminder');
        Notification::assertSentTo($this->merchantUser, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'proposal_reminder');
        $this->assertSame(1, OrderProposal::sole()->reminders);

        // Pas de nouvelle relance avant le délai suivant, puis une de plus
        $this->artisan('field-reports:remind');
        $this->assertSame(1, OrderProposal::sole()->reminders);
        $this->travel(61)->minutes();
        $this->artisan('field-reports:remind');
        $this->assertSame(2, OrderProposal::sole()->reminders);

        // Validé : plus de relance
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/proposal/approve")->assertOk();
        $this->travel(61)->minutes();
        $this->artisan('field-reports:remind');
        $this->assertSame(2, OrderProposal::sole()->reminders);
    }

    public function test_information_events_are_notes_that_change_nothing(): void
    {
        Notification::fake();
        $order = $this->outForDelivery();
        $this->as($this->courierB->user);

        // Heure précise : l'heure est demandée, puis enregistrée sur la course et affichée
        $this->postJson("/api/v1/orders/{$order->id}/notes", ['incident_reason_id' => $this->reason('i_time_slot')])
            ->assertJsonValidationErrors('time');
        $this->postJson("/api/v1/orders/{$order->id}/notes", ['incident_reason_id' => $this->reason('i_time_slot'), 'time' => '17:30'])->assertCreated();
        $this->postJson("/api/v1/orders/{$order->id}/notes", ['incident_reason_id' => $this->reason('i_call_back')])->assertCreated();
        // Précision demandée par l'événement (nom de la personne)
        $this->postJson("/api/v1/orders/{$order->id}/notes", ['incident_reason_id' => $this->reason('i_third_party')])
            ->assertJsonValidationErrors('note');

        $order->refresh();
        $this->assertSame('out_for_delivery', $order->status->value);
        $this->assertSame('17:30', $order->delivery_time);
        $event = $order->events()->where('incident_reason_id', $this->reason('i_time_slot'))->first();
        $this->assertSame(['note', 'À 17h30'], [$event->type->value, $event->note]);
        Notification::assertSentTo($this->dispatcher, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'note'
            && str_contains($n->body, 'Le destinataire veut être livré à une heure précise « À 17h30 »'));

        // Repère : gardé sur la course et dans le carnet du destinataire
        $this->postJson("/api/v1/orders/{$order->id}/notes", ['incident_reason_id' => $this->reason('i_landmark'), 'note' => 'Portail bleu après la pharmacie'])->assertCreated();
        $this->assertStringEndsWith('Portail bleu après la pharmacie', $order->fresh()->delivery_landmark);
        $this->assertSame($order->fresh()->delivery_landmark, $order->recipient->fresh()->landmark);

        // Consignes affichées sur la course (livreur, équipe, client)
        $this->as($this->merchantUser)->getJson("/api/v1/orders/{$order->id}")
            ->assertJsonPath('data.delivery.time', '17:30')
            ->assertJsonPath('data.instructions.0.label', 'Le destinataire veut être livré à une heure précise')
            ->assertJsonPath('data.instructions.0.note', 'À 17h30');

        // Un échec ne se signale pas comme une note, ni l'inverse
        $this->as($this->courierB->user);
        $this->postJson("/api/v1/orders/{$order->id}/notes", ['incident_reason_id' => $this->reason('unreachable')])->assertJsonValidationErrors('incident_reason_id');
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $this->reason('i_call_back')])
            ->assertJsonValidationErrors('incident_reason_id');
    }

    public function test_postponement_before_departure_keeps_the_mission_until_validated_with_the_time(): void
    {
        Notification::fake();
        $order = $this->createOrder();
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierB->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        $this->as($this->courierB->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $before = $order->fresh()->status->value;
        $date = today()->addDays(2)->toDateString();

        // Le client a appelé avant le départ : report proposé, rien ne change sur la course
        $this->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => 'delivery_failed', 'incident_reason_id' => $this->reason('postponed'),
            'rescheduled_to' => $date, 'delivery_time' => '10:00',
        ])->assertOk()->assertJsonPath('data.status', $before);
        $proposal = OrderProposal::sole();
        $this->assertSame(['reschedule', '10:00'], [$proposal->kind, $proposal->proposed_time]);
        $this->assertNotNull($order->fresh()->activeAssignment(AssignmentType::Delivery));
        Notification::assertSentTo($this->merchantUser, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'proposal' && str_contains($n->body, 'à 10h'));
        // La mission affiche la demande en attente
        $mission = collect($this->getJson('/api/v1/courier/missions')->json('data'))->firstWhere('order.id', $order->id);
        $this->assertSame('pending', $mission['order']['proposal']['status']);

        // Le client valide : la livraison prévue est retirée au livreur, la course est reportée à cette date et cette heure
        $this->as($this->merchantUser)->postJson("/api/v1/orders/{$order->id}/proposal/approve")->assertOk()
            ->assertJsonPath('data.status', 'rescheduled')
            ->assertJsonPath('data.delivery.scheduled_date', $date)
            ->assertJsonPath('data.delivery.time', '10:00');
        $this->assertNull($order->fresh()->activeAssignment(AssignmentType::Delivery));
        Notification::assertSentTo($this->courierB->user, OrderAlert::class, fn (OrderAlert $n) => $n->kind === 'mission_removed');
    }

    public function test_courier_departure_replaces_a_proposal_made_before(): void
    {
        $order = $this->createOrder();
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierB->id])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id])->assertOk();
        $this->as($this->courierB->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $this->reason('recipient_cancelled')])->assertOk();
        $this->assertSame('return', OrderProposal::sole()->kind);
        // Un échec simple (sans validation) ne se fait qu'une fois parti
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivery_failed', 'incident_reason_id' => $this->reason('unreachable')])->assertUnprocessable();

        // Le client a changé d'avis : le livreur part livrer
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->assertSame('closed', OrderProposal::sole()->status);
    }

    public function test_reason_settings_expose_effect_and_validation(): void
    {
        $this->as($this->admin);
        $postponed = collect($this->getJson('/api/v1/incident-reason-settings')->json('data'))->firstWhere('code', 'postponed');
        $this->assertSame(['status', true], [$postponed['effect'], $postponed['needs_validation']]);

        $this->patchJson("/api/v1/incident-reason-settings/{$postponed['id']}", ['needs_validation' => false])
            ->assertOk()->assertJsonPath('data.needs_validation', false);
        $this->postJson('/api/v1/incident-reason-settings', ['label' => 'Client au marché', 'applies_to' => 'delivery', 'family' => 'info', 'effect' => 'note'])
            ->assertCreated()->assertJsonPath('data.effect', 'note');
    }
}
