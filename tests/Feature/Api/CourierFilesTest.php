<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Photo du livreur et photo de sa pièce d'identité : envoyées par l'équipe, lues par l'équipe.
 */
class CourierFilesTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('local');
    }

    public function test_team_uploads_replaces_reads_and_removes_the_photo(): void
    {
        Sanctum::actingAs($this->admin);
        $url = "/api/v1/couriers/{$this->courierA->id}/files/photo";

        $this->postJson($url, ['file' => UploadedFile::fake()->image('p.jpg', 300, 300)])
            ->assertOk()
            ->assertJsonPath('data.has_photo', true)
            ->assertJsonPath('data.has_id_document', false);
        $first = $this->courierA->fresh()->photo_path;
        Storage::disk('local')->assertExists($first);

        // Nouvelle photo : l'ancienne est effacée
        $this->postJson($url, ['file' => UploadedFile::fake()->image('q.png', 300, 300)])->assertOk();
        Storage::disk('local')->assertMissing($first);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->deleteJson($url)->assertNoContent();
        $this->assertNull($this->courierA->fresh()->photo_path);
        $this->get($url)->assertNotFound();
    }

    public function test_id_document_and_number(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/couriers/{$this->courierA->id}/files/id-document", ['file' => UploadedFile::fake()->image('cni.jpg', 400, 300)])
            ->assertOk()->assertJsonPath('data.has_id_document', true);
        $this->patchJson("/api/v1/couriers/{$this->courierA->id}", ['id_number' => 'CI0012345'])
            ->assertOk()->assertJsonPath('data.id_number', 'CI0012345');
    }

    public function test_only_images_are_accepted(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/couriers/{$this->courierA->id}/files/photo", ['file' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')])
            ->assertUnprocessable();
        $this->postJson("/api/v1/couriers/{$this->courierA->id}/files/autre", ['file' => UploadedFile::fake()->image('p.jpg')])
            ->assertNotFound();
    }

    public function test_courier_and_merchant_cannot_change_or_read_them(): void
    {
        $url = "/api/v1/couriers/{$this->courierA->id}/files/photo";

        Sanctum::actingAs($this->courierB->user);
        $this->postJson($url, ['file' => UploadedFile::fake()->image('p.jpg', 300, 300)])->assertForbidden();
        $this->getJson($url)->assertForbidden();

        Sanctum::actingAs($this->merchantUser);
        $this->getJson($url)->assertForbidden();

        // Dispatcher : voit, ne change pas
        Sanctum::actingAs($this->userWithRole(Role::Dispatcher));
        $this->postJson($url, ['file' => UploadedFile::fake()->image('p.jpg', 300, 300)])->assertForbidden();
    }
}
