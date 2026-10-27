<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\OrderAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Signature dessinée par le livreur : seule une vraie image PNG est gardée avec la course.
 */
class SignatureTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('local');
        Sanctum::actingAs($this->dispatcher);
    }

    private function deliver(string $signature)
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::OutForDelivery])->save();

        return [$order, $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'collected_amount' => 0, 'signature' => $signature])];
    }

    public function test_a_drawn_signature_is_kept_with_the_course(): void
    {
        $png = UploadedFile::fake()->image('s.png', 350, 160)->getContent();
        [$order, $response] = $this->deliver('data:image/png;base64,'.base64_encode($png));
        $response->assertOk();
        $attachment = OrderAttachment::where('order_id', $order->id)->where('type', 'signature_delivery')->sole();
        $this->assertSame($png, Storage::disk('local')->get($attachment->path));
    }

    public function test_anything_but_a_png_image_is_refused(): void
    {
        foreach ([
            'page web' => base64_encode('<html><script>alert(1)</script></html>'),
            'en-tête PNG seul' => base64_encode("\x89PNG\r\n\x1a\nnimportequoi"),
            'image JPEG' => base64_encode(UploadedFile::fake()->image('s.jpg')->getContent()),
        ] as $what => $data) {
            [$order, $response] = $this->deliver('data:image/png;base64,'.$data);
            $response->assertUnprocessable()->assertJsonValidationErrors('signature');
            $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status, $what);
            $this->assertSame(0, OrderAttachment::where('order_id', $order->id)->count(), $what);
        }
    }
}
