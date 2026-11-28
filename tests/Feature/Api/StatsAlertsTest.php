<?php

namespace Tests\Feature\Api;

use App\Mail\StatsAlertsMail;
use App\Mail\StatsReportMail;
use App\Models\BillingPlan;
use App\Models\ClientInvoice;
use App\Models\CourierRemittance;
use App\Notifications\StatsAlertRaised;
use App\Services\Reports\StatsAlerts;
use App\Services\Reports\StatsMailer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Statistiques › Outils › Alertes : un réglage par événement (actif, seuil, cloche, WhatsApp, e-mail), les alertes de
 * gestion (forfait, facture, contrat, objectif, caisse, lot) et l'envoi régulier d'un modèle d'export.
 */
class StatsAlertsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
        $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00', 'UTC'));
    }

    public function test_events_are_set_one_by_one_and_alert_on_their_channels(): void
    {
        Mail::fake();
        Notification::fake();
        Sanctum::actingAs($this->admin);

        // Réglage d'origine : les trois premières alertes actives, les nouvelles éteintes
        $events = collect($this->getJson('/api/v1/stats/alerts')->assertOk()->json('data.events'))->keyBy('key');
        $this->assertCount(9, $events);
        $this->assertSame([true, 80, true, false], [$events['zone_success']['active'], $events['zone_success']['threshold'], $events['zone_success']['bell'], $events['contract_ending']['active']]);

        $this->putJson('/api/v1/stats/alerts', ['recipients' => ['Direction@Exemple.ci'], 'min_volume' => 5, 'events' => [
            'zone_success' => ['active' => false],
            'contract_ending' => ['active' => true, 'threshold' => 45, 'email' => true],
            'invoice_overdue' => ['active' => true, 'email' => true],
            'cash_gap' => ['active' => true, 'threshold' => 10000, 'bell' => false, 'email' => true],
            'lot_late' => ['active' => true],
            'forfait_over' => ['active' => true],
            'objective_behind' => ['active' => true, 'threshold' => 10],
        ]])->assertOk()->assertJsonPath('data.recipients', ['direction@exemple.ci'])->assertJsonPath('data.min_volume', 5);
        // L'alerte d'origine éteinte ici l'est aussi dans son réglage d'origine, et inversement
        $this->assertNull($this->company->fresh()->stats_settings['alerts']['zone_success_min']);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['stats_settings' => ['alerts' => ['zone_success_min' => 70]]])->assertOk();
        $events = collect($this->getJson('/api/v1/stats/alerts')->json('data.events'))->keyBy('key');
        $this->assertSame([true, 70, true, 45, true], [$events['zone_success']['active'], $events['zone_success']['threshold'], $events['contract_ending']['active'], $events['contract_ending']['threshold'], $events['contract_ending']['email']]);

        // De quoi déclencher chaque alerte de gestion
        $contract = BillingPlan::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_contract', 'monthly_fee' => 31000,
            'included_quantity' => 1, 'overage_price' => 500, 'delivery_delay' => '24h', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-10']);
        foreach ([1, 2] as $i) {
            $this->createOrder()->forceFill(['activity' => 'distribution', 'billing_plan_id' => $contract->id, 'created_at' => '2026-12-05 09:00'])->saveQuietly();
        }
        ClientInvoice::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'activity' => 'distribution', 'number' => 'F-2',
            'period' => '2026-08-01', 'status' => 'issued', 'total' => 30000, 'amount_paid' => 10000, 'issued_at' => '2026-09-01', 'due_at' => '2026-09-15']);
        CourierRemittance::create(['company_id' => $this->company->id, 'courier_id' => $this->courierA->id, 'amount_expected' => 50000, 'amount_received' => 30000,
            'difference' => -20000, 'received_by' => $this->admin->id, 'received_at' => '2026-12-12 18:00']);
        $lot = BillingPlan::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'formula' => 'distribution_lot', 'lot_label' => 'Cartes de vœux',
            'lot_quantity' => 5, 'lot_price' => 10000, 'starts_on' => '2026-12-01', 'lot_due_on' => '2026-12-10', 'delivery_delay' => '48h']);
        $this->putJson('/api/v1/stats/direction/objectives', ['objectives' => ['ecommerce' => ['delivered' => 500]]])->assertOk();

        $alerts = collect(app(StatsAlerts::class)->send($this->company->fresh()))->keyBy('key');
        foreach (["contract:{$contract->id}", "invoice:{$this->merchant->id}", "cash:{$this->courierA->id}", "lot:{$lot->id}", "forfait:{$contract->id}:2026-12", 'objective:ecommerce:2026-12'] as $key) {
            $this->assertTrue($alerts->has($key), "alerte attendue : {$key}");
        }
        $this->assertStringContainsString('200 %', $alerts["forfait:{$contract->id}:2026-12"]['body']);
        $this->assertStringContainsString('20 000 F', $alerts["invoice:{$this->merchant->id}"]['body']);

        // Canaux : la cloche pour le contrat, pas pour l'écart de caisse ; un seul e-mail avec les trois alertes « e-mail »
        Notification::assertSentTo($this->admin, StatsAlertRaised::class, fn ($n) => $n->key === "contract:{$contract->id}");
        Notification::assertNotSentTo($this->admin, StatsAlertRaised::class, fn ($n) => $n->key === "cash:{$this->courierA->id}");
        Notification::assertNotSentTo($this->dispatcher, StatsAlertRaised::class);
        Mail::assertSent(StatsAlertsMail::class, 1);
        Mail::assertSent(StatsAlertsMail::class, fn (StatsAlertsMail $m) => $m->hasTo('direction@exemple.ci')
            && collect($m->alerts)->pluck('event')->sort()->values()->all() === ['cash_gap', 'contract_ending', 'invoice_overdue']);

        // Pas de répétition avant 7 jours ; réservé aux réglages
        $this->assertSame([], app(StatsAlerts::class)->send($this->company->fresh()));
        Sanctum::actingAs($this->dispatcher);
        $this->getJson('/api/v1/stats/alerts')->assertForbidden();
        $this->putJson('/api/v1/stats/alerts', ['events' => [], 'recipients' => []])->assertForbidden();
    }

    public function test_an_export_template_is_sent_on_a_schedule(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['stats_settings' => ['email' => ['recipients' => ['gerant@exemple.ci']]]])->assertOk();
        $id = $this->postJson('/api/v1/stats/export/templates', ['name' => 'Dossier banque', 'options' => ['sections' => ['summary'], 'format' => 'pdf', 'hide_amounts' => true]])
            ->assertOk()->json('data.templates.0.id');

        // Sans envoi programmé : rien ne part
        $this->assertSame(0, app(StatsMailer::class)->sendTemplates('monthly'));
        $this->putJson("/api/v1/stats/export/templates/{$id}/schedule", ['schedule' => 'monthly'])->assertOk()->assertJsonPath('data.templates.0.schedule', 'monthly');
        $this->putJson("/api/v1/stats/export/templates/{$id}/schedule", ['schedule' => 'daily'])->assertJsonValidationErrors('schedule');
        $this->putJson('/api/v1/stats/export/templates/inconnu/schedule', ['schedule' => 'weekly'])->assertNotFound();

        $this->assertSame(0, app(StatsMailer::class)->sendTemplates('weekly'));
        $this->assertSame(1, app(StatsMailer::class)->sendTemplates('monthly'));
        Mail::assertSent(StatsReportMail::class, fn (StatsReportMail $m) => $m->hasTo('gerant@exemple.ci') && $m->title === 'Dossier banque'
            && $m->filename === 'statistiques-2026-11-01-au-2026-11-30.pdf');
    }
}
