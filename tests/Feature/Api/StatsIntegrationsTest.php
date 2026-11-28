<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Mail\StatsReportMail;
use App\Models\ClientReport;
use App\Models\Order;
use App\Notifications\ClientReportReady;
use App\Services\Reports\ClientReports;
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
 * Intégrations des statistiques : export Excel, lien Google Sheets, point par e-mail, rapports mensuels des clients
 * validés par l'équipe, ponctualité dans l'espace client.
 */
class StatsIntegrationsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->buildWorld();
        $this->travelTo(CarbonImmutable::parse('2026-12-02 10:00', 'UTC'));
    }

    private function deliveredIn(string $at, array $attributes = []): Order
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::Delivered, 'created_at' => $at, 'delivered_at' => $at, 'delivery_scheduled_date' => substr($at, 0, 10), ...$attributes])->saveQuietly();

        return $order;
    }

    public function test_excel_export_has_the_sheets(): void
    {
        $this->deliveredIn('2026-11-10 10:00');
        Sanctum::actingAs($this->admin);
        $response = $this->get('/api/v1/stats/export?from=2026-11-01&to=2026-11-30')->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $workbook = $zip->getFromName('xl/workbook.xml');
        foreach (['Synthèse', 'Mois par mois', 'Rentabilité clients', 'Délais par client', 'Échecs'] as $name) {
            $this->assertStringContainsString($name, $workbook);
        }
        $this->getJson('/api/v1/stats/export?from=2026-11-01')->assertJsonValidationErrors('to');
    }

    public function test_advanced_export_sections_formats_and_privacy(): void
    {
        $this->deliveredIn('2026-11-10 10:00', ['delivery_courier_id' => $this->courierB->id]);
        Sanctum::actingAs($this->admin);
        $base = '/api/v1/stats/export?from=2026-11-01&to=2026-11-30';

        // Rubriques au choix : seules les feuilles demandées
        $file = $this->get($base.'&sections[]=zones&sections[]=orders')->assertOk()->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $workbook = $zip->getFromName('xl/workbook.xml');
        $this->assertStringContainsString('Rentabilité zones', $workbook);
        $this->assertStringContainsString('Liste des courses', $workbook);
        $this->assertStringNotContainsString('Synthèse', $workbook);

        // CSV : les rubriques l'une sous l'autre ; la course, son livreur et son montant
        $csv = file_get_contents($this->get($base.'&format=csv&sections[]=orders&sections[]=failures')->assertOk()->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertStringContainsString('Liste des courses', $csv);
        $this->assertStringContainsString('Code;"Créée le";Client', $csv);
        $this->assertStringContainsString($this->courierB->user->name, $csv);
        $this->assertStringContainsString('Montant facturé', $csv);

        // Montants masqués et livreurs anonymisés : ni montant, ni nom, ni rubrique à montants
        $csv = file_get_contents($this->get($base.'&format=csv&sections[]=orders&sections[]=zones&hide_amounts=1&anonymize=1')->assertOk()->baseResponse->getFile()->getPathname());
        $this->assertStringNotContainsString('Montant facturé', $csv);
        $this->assertStringNotContainsString('Tarif moyen', $csv);
        $this->assertStringNotContainsString($this->courierB->user->name, $csv);
        $this->assertStringContainsString('Livreur 1', $csv);

        // PDF mis en page
        $response = $this->get($base.'&format=pdf&sections[]=summary&sections[]=zones')->assertOk();
        $this->assertStringStartsWith('%PDF', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertStringEndsWith('.pdf', $response->baseResponse->getFile()->getFilename() ?: '.pdf');

        $this->getJson($base.'&format=doc')->assertJsonValidationErrors('format');
        $this->getJson($base.'&sections[]=inconnue')->assertJsonValidationErrors('sections.0');

        // Sans le droit caisse : pas de rubriques à montants proposées, pas de montants exportés
        Sanctum::actingAs($this->dispatcher);
        $options = $this->getJson('/api/v1/stats/export/options')->assertOk()->json('data');
        $this->assertFalse($options['can_amounts']);
        $this->assertNotContains('zones', array_column($options['sections'], 'key'));
        $csv = file_get_contents($this->get($base.'&format=csv&sections[]=orders&sections[]=zones')->assertOk()->baseResponse->getFile()->getPathname());
        $this->assertStringNotContainsString('Montant facturé', $csv);
    }

    public function test_export_templates_and_send_by_email(): void
    {
        Mail::fake();
        $this->deliveredIn('2026-11-10 10:00');
        Sanctum::actingAs($this->admin);
        $options = ['sections' => ['summary', 'zones', 'inconnue'], 'format' => 'pdf', 'activity' => 'ecommerce', 'hide_amounts' => true];

        $templates = $this->postJson('/api/v1/stats/export/templates', ['name' => 'Dossier banque', 'options' => $options])->assertOk()->json('data.templates');
        $this->assertSame(['Dossier banque', ['summary', 'zones'], 'pdf', 'ecommerce', true, false],
            [$templates[0]['name'], $templates[0]['sections'], $templates[0]['format'], $templates[0]['activity'], $templates[0]['hide_amounts'], $templates[0]['anonymize']]);
        // Même nom : remplacé, pas doublé
        $this->postJson('/api/v1/stats/export/templates', ['name' => 'dossier banque', 'options' => [...$options, 'format' => 'csv']])->assertOk()->assertJsonCount(1, 'data.templates');
        $this->getJson('/api/v1/stats/export/options')->assertOk()->assertJsonPath('data.templates.0.format', 'csv')->assertJsonPath('data.can_save', true);
        // Les autres réglages des statistiques n'effacent pas les modèles
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['stats_settings' => ['forfait_high' => 120]])->assertOk();
        $id = $this->getJson('/api/v1/stats/export/options')->json('data.templates.0.id');
        $this->assertNotNull($id);
        $this->deleteJson("/api/v1/stats/export/templates/{$id}")->assertOk()->assertJsonCount(0, 'data.templates');

        // Envoi par e-mail : le fichier en pièce jointe, au format demandé
        $this->postJson('/api/v1/stats/export/send', ['from' => '2026-11-01', 'to' => '2026-11-30', 'format' => 'csv', 'sections' => ['summary'], 'recipients' => ['direction@exemple.ci']])
            ->assertOk()->assertJsonPath('data.sent', 1);
        Mail::assertSent(StatsReportMail::class, fn ($mail) => $mail->hasTo('direction@exemple.ci') && str_ends_with($mail->filename, '.csv'));
        $this->postJson('/api/v1/stats/export/send', ['from' => '2026-11-01', 'to' => '2026-11-30', 'recipients' => ['pas-un-email']])->assertJsonValidationErrors('recipients.0');

        // Modèles : autorisation de direction ; envoi : réglages
        Sanctum::actingAs($this->dispatcher);
        $this->postJson('/api/v1/stats/export/templates', ['name' => 'X', 'options' => $options])->assertForbidden();
        $this->postJson('/api/v1/stats/export/send', ['from' => '2026-11-01', 'to' => '2026-11-30', 'recipients' => ['a@exemple.ci']])->assertForbidden();
    }

    public function test_google_sheets_link(): void
    {
        $this->deliveredIn('2026-11-25 10:00');
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/stats/feed-link')->assertOk()->assertJsonPath('data.enabled', false);
        $feeds = collect($this->postJson('/api/v1/stats/feed-link')->assertOk()->json('data.feeds'))->keyBy('key');
        $url = $feeds['clients']['url'];
        $this->assertStringStartsWith('=IMPORTDATA("', $feeds['clients']['formula']);

        $csv = $this->get(parse_url($url, PHP_URL_PATH).'?jours=30')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringStartsWith('client,livrees,revenus', $csv);
        $this->assertStringContainsString($this->merchant->business_name, $csv);
        $this->assertStringStartsWith('mois,creees,livrees', $this->get(str_replace('clients.csv', 'mois.csv', parse_url($url, PHP_URL_PATH)))->getContent());
        $this->get('/api/v1/stats-feed/'.str_repeat('x', 48).'/clients.csv')->assertNotFound();

        // Lien coupé
        $this->deleteJson('/api/v1/stats/feed-link')->assertOk()->assertJsonPath('data.enabled', false);
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();
        Sanctum::actingAs($this->dispatcher);
        if (! $this->dispatcher->can('settings.manage')) {
            $this->postJson('/api/v1/stats/feed-link')->assertForbidden();
        }
    }

    public function test_weekly_and_monthly_emails(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['stats_settings' => ['email' => ['recipients' => ['Gerant@Exemple.ci', 'pas-un-email'], 'weekly' => true]]])
            ->assertJsonValidationErrors('stats_settings.email.recipients.1');
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['stats_settings' => ['email' => ['recipients' => ['Gerant@Exemple.ci'], 'weekly' => true]]])
            ->assertOk()->assertJsonPath('data.stats_settings.email.recipients', ['gerant@exemple.ci']);

        $this->assertSame(1, app(StatsMailer::class)->sendAll('weekly'));
        $this->assertSame(0, app(StatsMailer::class)->sendAll('monthly'));
        Mail::assertSent(StatsReportMail::class, fn (StatsReportMail $m) => $m->hasTo('gerant@exemple.ci') && str_contains($m->filename, 'semaine') && $m->title === 'Point de la semaine');
        $this->postJson('/api/v1/stats/test-email', ['frequency' => 'monthly'])->assertOk();
        Mail::assertSent(StatsReportMail::class, fn (StatsReportMail $m) => str_contains($m->title, 'novembre 2026'));
    }

    public function test_client_reports_are_checked_by_the_team_before_sending(): void
    {
        Notification::fake();
        $this->deliveredIn('2026-11-10 10:00', ['activity' => 'distribution', 'shipment_type' => 'pli', 'shipment_quantity' => 3]);
        $this->deliveredIn('2026-11-12 10:00', ['activity' => 'ecommerce']);
        $this->assertSame(1, app(ClientReports::class)->generate($this->company, CarbonImmutable::parse('2026-11-01')));
        $report = ClientReport::query()->first();
        $this->assertSame(['draft', 1, 3, 100.0], [$report->status, $report->data['delivered'], $report->data['units_delivered'], (float) $report->data['on_time_rate']]);

        // Le client ne voit pas les brouillons
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/client-reports')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/client-reports/{$report->id}")->assertNotFound();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/client-reports?period=2026-11')->assertOk()->assertJsonPath('data.0.status', 'draft');
        $this->postJson("/api/v1/client-reports/{$report->id}/send", ['note' => 'Merci pour votre confiance.'])->assertOk()->assertJsonPath('data.status', 'sent');
        Notification::assertSentTo($this->merchantUser, ClientReportReady::class);
        $this->postJson("/api/v1/client-reports/{$report->id}/send")->assertStatus(422);
        // Recalcul : un rapport envoyé ne change plus
        $this->postJson('/api/v1/client-reports/generate', ['month' => '2026-11'])->assertOk()->assertJsonPath('data.count', 0);

        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/client-reports')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/client-reports/{$report->id}")->assertOk()->assertJsonPath('data.note', 'Merci pour votre confiance.')->assertJsonPath('data.data.units_delivered', 3);
        $this->postJson("/api/v1/client-reports/{$report->id}/send")->assertForbidden();

        // Espace client : ponctualité de la période
        $this->getJson('/api/v1/merchant/stats?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('data.punctuality.delivered', 2)->assertJsonPath('data.punctuality.on_time', 2);
    }
}
