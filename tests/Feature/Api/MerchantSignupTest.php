<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\Merchant;
use App\Models\OutboundMessage;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Notifications\MerchantSignedUp;
use App\Services\Messaging\Gateways\SmsGateway;
use App\Services\WhatsApp\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class MerchantSignupTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const PHONE = '+2250712345678';

    /** @var list<array{to: string, text: string}> */
    private array $sms = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();

        // SMS capturés au lieu d'être envoyés
        $this->app->instance(SmsGateway::class, new class($this->sms) implements SmsGateway
        {
            public function __construct(private array &$sent) {}

            public function send(string $to, string $text): string
            {
                $this->sent[] = ['to' => $to, 'text' => $text];

                return 'test';
            }
        });
    }

    private function signupData(array $overrides = []): array
    {
        return [
            'business_name' => 'Boutique Awa',
            'contact_name' => 'Awa Koné',
            'phone' => '07 12 34 56 78',
            'email' => 'awa@example.ci',
            'pickup_zone_id' => $this->cocody->id,
            'pickup_address' => 'Riviera 2, rue des Jardins',
            'password' => 'motdepasse',
            'password_confirmation' => 'motdepasse',
            ...$overrides,
        ];
    }

    private function useWhatsApp(): void
    {
        config(['messaging.whatsapp.driver' => 'meta']);
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(10)]]]));
        WhatsAppAccount::create([
            'company_id' => $this->company->id, 'owner_type' => 'company', 'phone_number_id' => '1098765',
            'access_token' => 'tok', 'display_phone' => '0700000099',
        ]);
    }

    /**
     * Message WhatsApp reçu par l'entreprise ; renvoie la réponse du bot.
     */
    private function whatsapp(string $from, string $text): string
    {
        $lastId = (int) OutboundMessage::max('id');
        app(Conversation::class)->handle($this->company->fresh(), $from, 'text', $text);

        return OutboundMessage::where('id', '>', $lastId)->orderBy('id')->value('body');
    }

    private function smsCode(): string
    {
        preg_match('/^(\d{6})/', end($this->sms)['text'], $m);

        return $m[1];
    }

    public function test_options_list_pickup_zones_and_can_be_closed(): void
    {
        $this->getJson('/api/v1/signup')->assertOk()
            ->assertJsonPath('data.open', true)
            ->assertJsonFragment(['name' => 'Cocody']);
        $this->getJson('/api/v1/branding')->assertJsonPath('data.signup_open', true);

        $this->company->update(['merchant_signup' => false]);

        $this->getJson('/api/v1/signup')->assertJsonPath('data.open', false)->assertJsonPath('data.zones', []);
        $this->postJson('/api/v1/signup', $this->signupData())->assertUnprocessable()
            ->assertJsonPath('message', 'Les inscriptions en ligne sont fermées. Contactez-nous pour ouvrir un compte.');
    }

    public function test_whatsapp_message_from_the_number_creates_the_active_account(): void
    {
        Notification::fake();
        $this->useWhatsApp();

        $response = $this->postJson('/api/v1/signup', $this->signupData())->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.phone', '07 12 34 56 78')
            ->assertJsonPath('data.whatsapp.number', '07 00 00 00 99');
        $token = $response->json('token');
        $text = $response->json('data.whatsapp.text');
        $this->assertStringStartsWith('https://wa.me/2250700000099?text=Code%20de%20v', $response->json('data.whatsapp.link'));

        // Rien n'est créé avant la vérification
        $this->assertFalse(Merchant::where('phone', self::PHONE)->exists());
        $this->getJson("/api/v1/verifications/{$token}")->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('session');

        // Le même message envoyé depuis un autre numéro ne vaut rien
        $this->assertStringContainsString('demandé pour le numéro 07 12 34 56 78', $this->whatsapp('+2250511111111', $text));
        $this->assertFalse(Merchant::where('phone', self::PHONE)->exists());

        $reply = $this->whatsapp(self::PHONE, $text);
        $this->assertStringContainsString('Numéro vérifié', $reply);
        $this->assertStringContainsString('Boutique Awa', $reply);

        $merchant = Merchant::where('phone', self::PHONE)->firstOrFail();
        $this->assertSame('signup', $merchant->source);
        $this->assertTrue($merchant->isActive());
        $this->assertSame($this->cocody->id, $merchant->pickup_zone_id);
        $user = User::where('phone', self::PHONE)->firstOrFail();
        $this->assertTrue($user->hasRole(Role::MerchantOwner->value));
        $this->assertSame($merchant->id, $user->merchant_id);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertTrue(Hash::check('motdepasse', $user->password));
        $this->assertNull(PhoneVerification::first()->payload['password_hash']);
        Notification::assertSentTo($this->admin, MerchantSignedUp::class);
        Notification::assertNotSentTo($this->merchantUser, MerchantSignedUp::class);

        // La page qui attendait reçoit la session, une seule fois
        $session = $this->getJson("/api/v1/verifications/{$token}")->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('session.user.merchant.business_name', 'Boutique Awa');
        $this->getJson("/api/v1/verifications/{$token}")->assertJsonMissingPath('session');

        $this->withToken($session->json('session.token'))->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.phone', self::PHONE);

        // Le marchand est désormais reconnu par le bot
        $this->assertStringContainsString('Boutique Awa', $this->whatsapp(self::PHONE, 'Bonjour'));
    }

    public function test_sms_code_verifies_the_number(): void
    {
        $token = $this->postJson('/api/v1/signup', $this->signupData())->assertCreated()
            ->assertJsonPath('data.whatsapp', null)
            ->assertJsonPath('data.sms.available', true)
            ->json('token');

        $this->postJson("/api/v1/verifications/{$token}/code", ['code' => '000000'])->assertUnprocessable()
            ->assertJsonPath('message', "Demandez d'abord un code par SMS.");

        $this->postJson("/api/v1/verifications/{$token}/sms")->assertOk()->assertJsonPath('data.sms.sent', true);
        $this->assertSame(self::PHONE, $this->sms[0]['to']);
        $this->assertStringContainsString('pour créer votre compte', $this->sms[0]['text']);

        // Pas de nouveau SMS tout de suite
        $this->postJson("/api/v1/verifications/{$token}/sms")->assertUnprocessable();

        $wrong = $this->smsCode() === '111111' ? '222222' : '111111';
        $this->postJson("/api/v1/verifications/{$token}/code", ['code' => $wrong])->assertUnprocessable()
            ->assertJsonPath('message', 'Code incorrect. Encore 4 essai(s).');
        $this->assertFalse(User::where('phone', self::PHONE)->exists());

        $this->postJson("/api/v1/verifications/{$token}/code", ['code' => $this->smsCode()])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.verified_via', 'sms')
            ->assertJsonStructure(['session' => ['token', 'user']]);

        $this->assertTrue(Merchant::where('phone', self::PHONE)->where('source', 'signup')->exists());
    }

    public function test_sms_code_is_locked_after_five_wrong_attempts(): void
    {
        $token = $this->postJson('/api/v1/signup', $this->signupData())->json('token');
        $this->postJson("/api/v1/verifications/{$token}/sms")->assertOk();
        $wrong = $this->smsCode() === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/v1/verifications/{$token}/code", ['code' => $wrong])->assertUnprocessable();
        }

        $this->postJson("/api/v1/verifications/{$token}/code", ['code' => $this->smsCode()])->assertUnprocessable()
            ->assertJsonPath('message', "Trop d'essais. Demandez un nouveau code.");
        $this->assertFalse(User::where('phone', self::PHONE)->exists());
    }

    public function test_demo_mode_shows_the_sms_code(): void
    {
        $this->app->forgetInstance(SmsGateway::class);
        $token = $this->postJson('/api/v1/signup', $this->signupData())->assertJsonPath('data.demo', true)->json('token');

        $code = $this->postJson("/api/v1/verifications/{$token}/sms")->assertOk()->json('demo_code');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->postJson("/api/v1/verifications/{$token}/code", ['code' => $code])->assertJsonPath('data.status', 'completed');
    }

    public function test_signup_is_refused_for_known_numbers_and_sms_only_goes_to_local_mobiles(): void
    {
        $this->postJson('/api/v1/signup', $this->signupData(['phone' => $this->merchantUser->phone]))->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/signup', $this->signupData(['email' => $this->admin->email]))->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/signup', $this->signupData(['pickup_zone_id' => null, 'password_confirmation' => 'autre']))
            ->assertJsonValidationErrors(['pickup_zone_id', 'password']);

        // Autre adresse IP : les essais précédents ont atteint la limite par minute
        $token = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/v1/signup', $this->signupData(['phone' => '+33612345678']))->assertCreated()
            ->assertJsonPath('data.sms.available', false)->json('token');
        $this->postJson("/api/v1/verifications/{$token}/sms")->assertUnprocessable();
        $this->assertSame([], $this->sms);
    }

    public function test_a_number_cannot_request_endless_verifications(): void
    {
        for ($i = 0; $i < 5; $i++) {
            PhoneVerification::create([
                'company_id' => $this->company->id, 'purpose' => 'signup', 'phone' => self::PHONE,
                'token_hash' => hash('sha256', (string) $i), 'whatsapp_code' => '12345'.$i, 'expires_at' => now()->addMinutes(30),
            ]);
        }

        $this->postJson('/api/v1/signup', $this->signupData())->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_forgotten_password_is_reset_after_verification(): void
    {
        $this->useWhatsApp();
        $oldToken = $this->merchantUser->createToken('ancien')->plainTextToken;

        $response = $this->postJson('/api/v1/password/forgot', ['phone' => $this->merchantUser->phone])->assertCreated()
            ->assertJsonPath('data.purpose', 'password_reset');
        $token = $response->json('token');

        // Pas encore vérifié
        $this->postJson('/api/v1/password/reset', ['token' => $token, 'password' => 'nouveaupass', 'password_confirmation' => 'nouveaupass'])
            ->assertUnprocessable();

        $reply = $this->whatsapp($this->merchantUser->phone, $response->json('data.whatsapp.text'));
        $this->assertStringContainsString('nouveau mot de passe', $reply);
        $this->getJson("/api/v1/verifications/{$token}")->assertJsonPath('data.status', 'verified');

        $this->postJson('/api/v1/password/reset', ['token' => $token, 'password' => 'nouveaupass', 'password_confirmation' => 'nouveaupass'])
            ->assertOk()->assertJsonStructure(['token', 'user']);

        $this->assertTrue(Hash::check('nouveaupass', $this->merchantUser->fresh()->password));
        $this->assertSame(1, $this->merchantUser->tokens()->count());
        $this->assertNotSame(explode('|', $oldToken)[0], (string) $this->merchantUser->tokens()->first()->id);

        // Une seule fois
        $this->postJson('/api/v1/password/reset', ['token' => $token, 'password' => 'autrepass1', 'password_confirmation' => 'autrepass1'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['login' => $this->merchantUser->phone, 'password' => 'nouveaupass'])->assertOk();
    }

    public function test_forgotten_password_by_sms_and_unknown_numbers_are_not_revealed(): void
    {
        $staff = $this->dispatcher;
        $token = $this->postJson('/api/v1/password/forgot', ['phone' => $staff->phone])->assertCreated()->json('token');
        $this->postJson("/api/v1/verifications/{$token}/sms")->assertOk();
        $this->assertStringContainsString('pour changer votre mot de passe', $this->sms[0]['text']);
        $this->postJson("/api/v1/verifications/{$token}/code", ['code' => $this->smsCode()])->assertJsonPath('data.status', 'verified');
        $this->postJson('/api/v1/password/reset', ['token' => $token, 'password' => 'nouveaupass', 'password_confirmation' => 'nouveaupass'])
            ->assertOk()->assertJsonPath('user.id', $staff->id);

        // Numéro inconnu : même réponse, aucun SMS envoyé
        $unknown = $this->postJson('/api/v1/password/forgot', ['phone' => '0711223344'])->assertCreated()
            ->assertJsonPath('data.sms.available', true)->json('token');
        $this->postJson("/api/v1/verifications/{$unknown}/sms")->assertOk()->assertJsonPath('data.sms.sent', true);
        $this->assertCount(1, $this->sms);
    }
}
