<?php

namespace Tests\Feature\Api;

use App\Models\IncidentReason;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\ReturnSlip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Photos de documents : version traitée par le scanner, photo d'origine gardée pour l'équipe (réglage),
 * informations de la prise, envoi renouvelé après une coupure sans doublon, réglages de l'entreprise.
 */
class DocumentPhotoTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('local');
    }

    private function upload(Order $order, array $extra = [])
    {
        return $this->post("/api/v1/orders/{$order->id}/attachments", [
            'file' => UploadedFile::fake()->image('document.jpg', 1400, 2000),
            'file_original' => UploadedFile::fake()->image('original.jpg', 3000, 4000),
            'file_meta' => json_encode([
                'scanned' => true, 'mode' => 'color', 'width' => 1400, 'height' => 2000, 'overridden' => false,
                'quality' => ['blur' => 0.12, 'brightness' => 231, 'glare' => 0, 'warnings' => ['glare', 'inconnu']],
                'taken_at' => '2026-10-27T10:42:00+00:00', 'pirate' => 'x',
            ]),
            'type' => 'photo_delivery',
            ...$extra,
        ], ['Accept' => 'application/json']);
    }

    public function test_scanned_document_keeps_its_original_for_the_team_only(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->upload($order)->assertCreated();

        $attachment = OrderAttachment::where('order_id', $order->id)->sole();
        $this->assertNotNull($attachment->original_path);
        Storage::disk('local')->assertExists([$attachment->path, $attachment->original_path]);
        // Informations de la prise réduites aux clés connues
        $this->assertSame(['glare'], $attachment->meta['quality']['warnings']);
        $this->assertArrayNotHasKey('pirate', $attachment->meta);
        $this->assertSame('color', $attachment->meta['mode']);

        $proof = collect($this->getJson("/api/v1/orders/{$order->id}")->json('data.events'))->firstWhere('type', 'proof_added');
        $original = $proof['attachments'][0]['original_url'];
        $this->assertStringContainsString('original=1', $original);
        $this->assertTrue($proof['attachments'][0]['scan']['scanned']);
        $this->get($original)->assertOk();

        // Le client voit le document net, jamais l'original
        Sanctum::actingAs($this->merchantUser);
        $proof = collect($this->getJson("/api/v1/orders/{$order->id}")->json('data.events'))->firstWhere('type', 'proof_added');
        $this->assertNull($proof['attachments'][0]['original_url']);
        $this->get($proof['attachments'][0]['url'])->assertOk();
        $this->get($original)->assertNotFound();
    }

    public function test_original_is_not_kept_when_the_setting_is_off(): void
    {
        $this->company->update(['photo_settings' => ['keep_original' => false]]);
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->upload($order)->assertCreated();

        $this->assertNull(OrderAttachment::where('order_id', $order->id)->sole()->original_path);
        $this->assertCount(1, Storage::disk('local')->allFiles("orders/{$order->id}"));
    }

    public function test_photo_sent_again_after_a_network_cut_is_kept_once(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $first = $this->upload($order, ['client_ref' => 'abc-123'])->assertCreated()->json('data.id');
        $this->assertSame($first, $this->upload($order, ['client_ref' => 'abc-123'])->assertOk()->json('data.id'));
        $this->upload($order, ['client_ref' => 'autre'])->assertCreated();

        $this->assertSame(2, OrderAttachment::where('order_id', $order->id)->count());
    }

    public function test_large_phone_photos_are_accepted(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        // Photo de 9 Mo d'un téléphone récent (refusée avant à partir de 5 Mo)
        $this->post("/api/v1/orders/{$order->id}/attachments", [
            'file' => UploadedFile::fake()->image('photo.jpg')->size(9000), 'type' => 'photo_delivery',
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/v1/orders/{$order->id}/attachments", [
            'file' => UploadedFile::fake()->image('photo.jpg')->size(16000), 'type' => 'photo_delivery',
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
    }

    public function test_photo_settings_are_saved_cleaned_and_shared_with_the_app(): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['photo_settings' => [
            'scan' => ['receipts' => false], 'mode' => 'bw', 'quality_check' => 'warn', 'resolution' => 'high', 'keep_original' => true,
        ]])->assertOk()
            ->assertJsonPath('data.photo_settings.scan.receipts', false)
            ->assertJsonPath('data.photo_settings.scan.delivery_proof', true)
            ->assertJsonPath('data.photo_settings.mode', 'bw')
            ->assertJsonPath('data.photo_settings.resolution', 'high');
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['photo_settings' => ['mode' => 'sepia']])->assertJsonValidationErrors('photo_settings.mode');

        // L'application livreur reçoit les réglages avec l'entreprise
        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.company.photo_settings.quality_check', 'warn');

        Sanctum::actingAs($this->dispatcher);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['photo_settings' => ['mode' => 'color']])->assertForbidden();
    }

    public function test_return_handover_keeps_the_scanned_slip_and_its_original(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierA->id]);
        $this->move($order, $this->courierA->user, 'picked_up');
        $this->move($order, $this->courierA->user, 'out_for_delivery');
        $reason = IncidentReason::whereIn('applies_to', ['delivery', 'both'])->where('requires_date', false)->where('triggers_return', false)->orderBy('id')->first();
        $this->move($order, $this->courierA->user, 'delivery_failed', ['incident_reason_id' => $reason->id]);
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/decision", ['decision' => 'return'])->assertOk();
        $this->postJson("/api/v1/couriers/{$this->courierA->id}/parcels/receive", ['order_ids' => [$order->id]])->assertOk();
        $slipId = $this->postJson('/api/v1/return-slips', ['merchant_id' => $this->merchant->id, 'courier_id' => $this->courierA->id, 'order_ids' => [$order->id]])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($this->courierA->user);
        $this->post("/api/v1/return-slips/{$slipId}/hand-over", [
            'received_by_name' => 'Mariam',
            'photo' => UploadedFile::fake()->image('bon.jpg', 1400, 2000),
            'photo_original' => UploadedFile::fake()->image('original.jpg', 3000, 4000),
            'photo_meta' => json_encode(['scanned' => true, 'mode' => 'gray']),
        ], ['Accept' => 'application/json'])->assertOk();

        $slip = ReturnSlip::find($slipId);
        Storage::disk('local')->assertExists([$slip->photo_path, $slip->photo_original_path]);
        $this->assertSame('gray', $slip->photo_meta['mode']);

        Sanctum::actingAs($this->dispatcher);
        $url = $this->getJson("/api/v1/return-slips/{$slipId}")->json('data.photo_original_url');
        $this->get($url)->assertOk();
        Sanctum::actingAs($this->merchantUser);
        $this->assertNull($this->getJson("/api/v1/return-slips/{$slipId}")->json('data.photo_original_url'));
        $this->get($url)->assertNotFound();
    }

    private function move(Order $order, User $actor, string $status, array $extra = []): void
    {
        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => $status, ...$extra])->assertOk();
    }
}
