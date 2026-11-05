<?php

namespace Tests\Feature\Api;

use App\Enums\OrderEventType;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPaymentMethod;
use App\Models\OrderAttachment;
use App\Models\PhoneVerification;
use App\Models\Product;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Finance\MerchantPayouts;
use App\Services\Orders\OrderImporter;
use App\Services\Orders\OrderJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;
use ZipArchive;

/**
 * Audit de sécurité n° 2, lot 3 : points de gravité basse.
 */
class SecurityLot3Test extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** Point 22 : un inconnu ne bloque pas le numéro d'un autre depuis sa propre adresse IP */
    public function test_verification_requests_are_limited_per_number_and_ip(): void
    {
        $verifier = app(PhoneVerifier::class);
        for ($i = 0; $i < PhoneVerifier::MAX_STARTS_PER_HOUR; $i++) {
            $verifier->start($this->company, PhoneVerification::PASSWORD_RESET, '0700000001', null, null, '203.0.113.9');
        }
        try {
            $verifier->start($this->company, PhoneVerification::PASSWORD_RESET, '0700000001', null, null, '203.0.113.9');
            $this->fail('Limite par adresse IP non appliquée');
        } catch (BusinessRuleException) {
        }

        // Le titulaire, depuis son téléphone, peut encore demander son code
        [$verification] = $verifier->start($this->company, PhoneVerification::PASSWORD_RESET, '0700000001', null, null, '198.51.100.7');
        $this->assertNotNull($verification->id);
    }

    /** Point 24 : accepter une invitation ferme les autres sessions du compte */
    public function test_the_bot_never_reveals_the_number_of_a_code(): void
    {
        $verifier = app(PhoneVerifier::class);
        [$verification] = $verifier->start($this->company, PhoneVerification::PASSWORD_RESET, '0700000001', null, null, '203.0.113.9');

        $answer = $verifier->handleWhatsApp($this->company, '+2250511111111', "Code de vérification : {$verification->whatsapp_code}");
        $this->assertStringNotContainsString('07 00 00 00 01', (string) $answer);
        $this->assertStringNotContainsString('0700000001', (string) $answer);
    }

    /** Point 27 : un fichier Excel énorme une fois décompressé est refusé avant la lecture */
    public function test_a_zip_bomb_excel_file_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat('0', OrderImporter::MAX_UNZIPPED_BYTES + 1));
        $zip->close();

        $read = new ReflectionMethod(OrderImporter::class, 'read');
        try {
            $read->invoke(app(OrderImporter::class), $path, 'xlsx');
            $this->fail('Fichier piégé lu');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('trop volumineux', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    /** Point 29 : un paiement à l'unité refuse un moyen de paiement pas encore vérifié */
    public function test_a_single_payout_needs_a_verified_payment_method(): void
    {
        $this->merchant->paymentMethods()->create(['company_id' => $this->company->id, 'type' => 'wave', 'number' => '+2250709980885', 'status' => MerchantPaymentMethod::PENDING, 'is_preferred' => true]);
        MerchantLedgerEntry::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'type' => 'adjustment', 'amount' => 5000, 'description' => 'Test']);
        $payouts = app(MerchantPayouts::class);
        $payout = $payouts->create($this->admin, $this->merchant);

        $this->expectException(BusinessRuleException::class);
        $payouts->markPaid($this->admin, $payout, PaymentMethod::Wave, 'REF-1');
    }

    /** Point 31 : pièce jointe interne invisible pour l'e-commerçant ; photo d'un produit non publié seulement avec son lien */
    public function test_internal_attachments_and_unpublished_photos_stay_private(): void
    {
        Storage::fake('local');
        $order = $this->createOrder();
        $event = app(OrderJournal::class)->record($order, $this->dispatcher, OrderEventType::Note, ['note' => 'Interne', 'visible_to_merchant' => false]);
        Storage::disk('local')->put("orders/{$order->id}/x.jpg", 'image');
        $attachment = OrderAttachment::create(['order_id' => $order->id, 'event_id' => $event->id, 'type' => 'document', 'disk' => 'local', 'path' => "orders/{$order->id}/x.jpg", 'mime_type' => 'image/jpeg', 'uploaded_by' => $this->dispatcher->id]);

        Sanctum::actingAs($this->merchantUser);
        $this->get("/api/v1/orders/{$order->id}/attachments/{$attachment->id}")->assertNotFound();
        Sanctum::actingAs($this->dispatcher);
        $this->get("/api/v1/orders/{$order->id}/attachments/{$attachment->id}")->assertOk();

        Storage::disk('local')->put('products/p.jpg', 'image');
        $product = Product::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'sku' => 'P1', 'name' => 'Robe', 'price' => 1000, 'is_active' => true, 'shop_visible' => false]);
        $product->forceFill(['photo_path' => 'products/p.jpg'])->save();
        $this->get("/produits/{$product->id}/photo")->assertNotFound();
        $this->get($product->photoUrl())->assertOk();
        $product->update(['shop_visible' => true]);
        $this->get("/produits/{$product->id}/photo")->assertOk();
    }

    /** Point 32 : la session de l'équipe d'une entreprise dure 7 jours, celle d'un livreur 30 */
    public function test_team_sessions_are_shorter(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => $this->dispatcher->phone, 'password' => 'password'])->assertOk();
        $expires = PersonalAccessToken::query()->where('tokenable_id', $this->dispatcher->id)->latest('id')->value('expires_at');
        $this->assertTrue(now()->addDays(6)->lt($expires) && now()->addDays(8)->gt($expires));
    }
}
