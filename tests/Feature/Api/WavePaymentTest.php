<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Models\PlatformWavePayment;
use App\Models\User;
use App\Notifications\WavePaymentReceived;
use App\Notifications\WavePaymentToValidate;
use App\Services\Messaging\Gateways\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Factures payées par le lien marchand Wave : réglages de la console, « Payer avec Wave » et « J'ai payé »,
 * alertes (e-mail, SMS, cloche), validation depuis la console ou depuis le lien avec le code.
 */
class WavePaymentTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private User $root;

    private PlatformInvoice $invoice;

    /** SMS « envoyés » : [['to' => …, 'text' => …]] */
    private \ArrayObject $sms;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->buildWorld();
        $this->root = User::factory()->withRole(Role::SuperAdmin)->create(['company_id' => null]);
        $this->invoice = PlatformInvoice::create([
            'company_id' => $this->company->id, 'number' => 'FAC-202610-0001', 'period' => '2026-10', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'plan_name' => 'Essentiel', 'base_amount' => 25000, 'included_orders' => 100, 'orders_count' => 10, 'overage_orders' => 0, 'overage_per_order' => 0,
            'overage_amount' => 0, 'total' => 25000, 'amount_paid' => 0, 'status' => PlatformInvoice::OPEN, 'issued_at' => now()->toDateString(), 'due_at' => now()->addDays(10)->toDateString(),
        ]);
        $this->sms = $sent = new \ArrayObject;
        $this->app->bind(SmsGateway::class, fn () => new class($sent) implements SmsGateway
        {
            public function __construct(private \ArrayObject $sent) {}

            public function send(string $to, string $text): string
            {
                $this->sent->append(['to' => $to, 'text' => $text]);

                return 'sms-1';
            }
        });
    }

    private function configure(): void
    {
        Sanctum::actingAs($this->root);
        $this->putJson('/api/v1/console/wave', ['link' => 'pay.wave.com/m/M_ci_test/c/ci/?amount={montant}', 'validator_email' => 'paiements@jibiat.com',
            'validator_phone' => '0507016739', 'validator_pin' => '2468'])->assertOk()
            ->assertJsonPath('data.link', 'https://pay.wave.com/m/M_ci_test/c/ci/?amount={montant}')->assertJsonPath('data.pin_set', true)->assertJsonMissingPath('data.validator_pin');
    }

    public function test_paying_an_invoice_with_wave_and_validating_it_in_the_console(): void
    {
        Notification::fake();
        // Rien de réglé : pas de bouton Wave
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.wave', null);
        $this->postJson("/api/v1/subscription/invoices/{$this->invoice->id}/wave", ['payer_phone' => '0708091011'])->assertJsonValidationErrors('payer_phone');

        $this->configure();
        $qr = $this->post('/api/v1/console/wave/qr', ['image' => UploadedFile::fake()->image('qr.png', 300, 300)], ['Accept' => 'application/json'])->assertOk()->json('data.qr_url');
        $this->assertNotNull($qr);

        // L'entreprise : QR code, numéro Wave demandé, puis lien avec le montant ; e-mail au validateur et cloche de la console
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/subscription')->assertJsonPath('data.wave.qr_url', $qr);
        $this->postJson("/api/v1/subscription/invoices/{$this->invoice->id}/wave", [])->assertJsonValidationErrors(['payer_phone' => 'Le champ numéro Wave est obligatoire.']);
        $payment = $this->postJson("/api/v1/subscription/invoices/{$this->invoice->id}/wave", ['payer_phone' => '0708091011'])->assertCreated()
            ->assertJsonPath('data.link', 'https://pay.wave.com/m/M_ci_test/c/ci/?amount=25000')->assertJsonPath('data.amount', 25000)->json('data');
        Notification::assertSentOnDemand(WavePaymentToValidate::class, fn ($n, $c, AnonymousNotifiable $to) => $to->routes['mail'] === 'paiements@jibiat.com');
        Notification::assertSentTo($this->root, WavePaymentReceived::class);
        $this->assertCount(0, $this->sms);

        // « J'ai payé » : seconde alerte, une seule fois
        $this->postJson("/api/v1/subscription/wave-payments/{$payment['id']}/paid")->assertOk()->assertJsonPath('data.claimed_at', fn ($v) => $v !== null);
        $this->postJson("/api/v1/subscription/wave-payments/{$payment['id']}/paid")->assertOk();
        Notification::assertSentOnDemandTimes(WavePaymentToValidate::class, 2);
        $this->getJson('/api/v1/subscription')->assertJsonPath('data.wave_pending.0.id', $payment['id']);
        // Une autre entreprise ne voit pas ce paiement ; l'entreprise n'accède pas à la console
        $this->getJson('/api/v1/console/wave/payments')->assertForbidden();

        // Console : paiement à valider, puis reçu : la facture est payée
        Sanctum::actingAs($this->root);
        $this->getJson('/api/v1/console/wave/payments')->assertOk()->assertJsonPath('data.0.payer_phone', '07 08 09 10 11')
            ->assertJsonPath('data.0.invoice.number', 'FAC-202610-0001')->assertJsonPath('data.0.alerts.1.channel', 'mail');
        $this->postJson("/api/v1/console/wave/payments/{$payment['id']}/decide", ['decision' => 'received'])->assertOk()->assertJsonPath('data.status', 'received');
        $invoice = $this->invoice->fresh();
        $this->assertSame([PlatformInvoice::PAID, 25000], [$invoice->status, $invoice->amount_paid]);
        $this->assertSame('Wave 07 08 09 10 11', $invoice->payments()->sole()->reference);
        $this->getJson('/api/v1/console/wave/payments')->assertJsonCount(0, 'data');
    }

    public function test_sms_when_the_mail_fails_and_validation_with_the_code_from_the_link(): void
    {
        $this->configure();
        // Le serveur d'e-mail réglé dans la console ne répond pas : SMS à la place
        $this->putJson('/api/v1/console/wave/mail', ['host' => '127.0.0.1', 'port' => 1, 'username' => 'jibiat@gmail.com', 'password' => 'abcd efgh ijkl mnop'])
            ->assertOk()->assertJsonPath('data.mail.source', 'console')->assertJsonPath('data.mail.password_set', true)->assertJsonMissingPath('data.mail.password');
        $this->assertStringNotContainsString('abcd', (string) PlatformSetting::get('mail.password'));

        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/subscription/invoices/{$this->invoice->id}/wave", ['payer_phone' => '0708091011'])->assertCreated()->json('data.id');
        $this->assertCount(1, $this->sms);
        $this->assertSame('+2250507016739', $this->sms[0]['to']);
        $this->assertStringContainsString('25 000 F', $this->sms[0]['text']);
        $this->assertSame('journal', PlatformWavePayment::find($id)->alerts[0]['channel']);

        // Lien signé : sans signature refusé, mauvais code refusé, bon code : paiement enregistré
        preg_match('#(http\S+/paiements-wave/valider/\S+)#', $this->sms[0]['text'], $m);
        $url = $m[1];
        $this->assertTrue($this->get(strtok($url, '?'))->isClientError());
        $this->get($url)->assertOk()->assertSee('25 000 F')->assertSee('07 08 09 10 11');
        $this->post($url, ['code' => '0000', 'decision' => 'received'])->assertOk()->assertSee('Code incorrect.');
        $this->post($url, ['code' => '2468', 'decision' => 'received'])->assertOk()->assertSee('Paiement confirmé');
        $this->assertSame(PlatformInvoice::PAID, $this->invoice->fresh()->status);
        $this->assertNull($this->invoice->payments()->sole()->recorded_by);
    }

    public function test_test_alert_says_what_went_out(): void
    {
        config(['mail.default' => 'log']);
        $this->configure();
        $this->postJson('/api/v1/console/wave/test-alert')->assertOk()->assertJsonPath('data.mail.ok', false)->assertJsonPath('data.sms.ok', false)
            ->assertJsonPath('data.mail.detail', fn ($d) => str_contains($d, 'serveur d\'e-mail'));
        $this->getJson('/api/v1/console/wave')->assertJsonPath('data.mail.source', null)->assertJsonPath('data.sms_ready', false);
    }
}
