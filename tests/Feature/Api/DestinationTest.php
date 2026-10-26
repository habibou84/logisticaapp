<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\BillingPlan;
use App\Models\Destination;
use App\Models\Order;
use App\Models\OrderAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Annuaire des destinations : import, suggestions, correction des numéros, enrichissement par les courses,
 * position GPS confirmée par le livreur.
 */
class DestinationTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const SQL = <<<'SQL'
        INSERT INTO `base_clients` (`id`, `nom`, `ville`, `commune`, `adresse`, `contact`, `created_at`, `updated_at`, `secteur`, `whatsapp`, `email`) VALUES
        (1, 'ITWA(IMPERIAL TOBACCO WEST AFRICA)', 'ABJDJAN', 'COCODY', 'COCODY NORD QUARTIER GENDARMERIE', '22489820 / 22489890', '2023-06-20 08:53:22', '2023-06-20 08:53:22', NULL, NULL, NULL),
        (2, 'MDHLP(MINISTERE DES DROITS DE L\'HOMME)', 'Abjdjan', 'PLATEAU', 'Cité administrative tour B', '27-20-32-07-58 / 27-20-25-00-85', '2023-06-20 08:53:22', '2023-06-20 08:53:22', NULL, NULL, NULL),
        (3, 'NAI(NOUVELLE AFRIQUE INDUSTRIE)', 'ABIDAJN', 'YOP', 'Zone industrielle', ' 27 23 46 61 74/ 07 48 20 86 66', '2023-06-20 08:53:22', '2023-06-20 08:53:22', 'INDUSTRIES', NULL, 'contact@nai.ci'),
        (4, 'NAI(NOUVELLE AFRIQUE INDUSTRIE)', 'ABJDJAN', 'YOPOUGON', NULL, '0101010101', NULL, NULL, NULL, NULL, NULL),
        (5, 'CLINIQUE DES GRACES', 'Bouaké', '', 'Quartier Commerce', NULL, NULL, NULL, NULL, NULL, NULL),
        (6, 'URC', 'Abjdjan', 'II PLATEAUX', 'Rue des jardins', '709799319', NULL, NULL, NULL, NULL, NULL);
        SQL;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-10 10:00:00');
        foreach ([$this->cocody, $this->yopougon, $this->plateau] as $zone) {
            $zone->update(['in_forfait' => true]);
        }
        $this->zone('Bouaké')->update(['city' => 'Bouaké']);
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/v1/merchants/{$this->merchant->id}/billing-plans", [
            'formula' => 'distribution_contract', 'monthly_fee' => 50000, 'included_quantity' => 100, 'overage_price' => 800,
            'delivery_delay' => '48h', 'cheque_price' => 1500, 'extra_kg_price' => 300, 'starts_on' => '2026-11-01',
        ])->assertCreated()->json('data.id');
        $this->merchant->syncActivities([BillingPlan::findOrFail($id)->activity]);
    }

    private function import(bool $dryRun): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->post('/api/v1/destinations/import', ['file' => UploadedFile::fake()->createWithContent('base_clients.sql', self::SQL), 'dry_run' => $dryRun ? '1' : '0'], ['Accept' => 'application/json']);
    }

    private function order(array $data = []): TestResponse
    {
        Sanctum::actingAs($this->merchantUser);

        return $this->postJson('/api/v1/orders', ['activity' => 'distribution', 'shipment_type' => 'pli', ...$data]);
    }

    private function itwa(): Destination
    {
        return Destination::where('acronym', 'ITWA')->firstOrFail();
    }

    public function test_the_old_base_is_imported_cleaned_and_doubtful_numbers_kept_as_they_are(): void
    {
        // Essai : rien n'est enregistré, le rapport dit ce qui serait fait
        $this->import(true)->assertOk()
            ->assertJsonPath('data.rows', 6)->assertJsonPath('data.created', 5)->assertJsonPath('data.merged', 1)
            ->assertJsonPath('data.phones_check', 2)->assertJsonPath('data.no_phone', 1)->assertJsonPath('data.cities.ABIDAJN → Abidjan', 1);
        $this->assertSame(0, Destination::count());

        $this->import(false)->assertOk()->assertJsonPath('data.created', 5);
        $itwa = $this->itwa();
        // Sigle et nom, ville, commune ; anciens numéros gardés tels quels, « à vérifier », sans correction appliquée
        $this->assertSame(['IMPERIAL TOBACCO WEST AFRICA', 'Abidjan', $this->cocody->id, null, true, '22489820 / 22489890'],
            [$itwa->name, $itwa->city, $itwa->zone_id, $itwa->phone, $itwa->phone_check, $itwa->phones_raw]);
        $mdhlp = Destination::where('acronym', 'MDHLP')->first();
        $this->assertSame(['+2252720320758', '+2252720250085', false, $this->plateau->id], [$mdhlp->phone, $mdhlp->phone2, $mdhlp->phone_check, $mdhlp->zone_id]);
        // Doublon regroupé : les numéros des deux fiches
        $nai = Destination::where('acronym', 'NAI')->first();
        $this->assertSame([$this->yopougon->id, ['+2252723466174', '+2250748208666', '+2250101010101'], 'INDUSTRIES', 'contact@nai.ci'], [$nai->zone_id, $nai->phones(), $nai->sector, $nai->email]);
        // Ville de l'intérieur, quartier, 0 oublié : à vérifier
        $this->assertSame('Bouaké', Destination::where('name', 'CLINIQUE DES GRACES')->value('city'));
        $urc = Destination::where('name', 'URC')->first();
        $this->assertSame([$this->cocody->id, 'II Plateaux', true], [$urc->zone_id, $urc->district, $urc->phone_check]);

        // Correction proposée affichée, jamais appliquée seule
        $this->getJson("/api/v1/destinations/{$itwa->id}")->assertOk()
            ->assertJsonPath('data.doubtful.0', ['raw' => '22489820', 'suggestion' => '27 22 48 98 20']);
        $this->getJson('/api/v1/destinations?filtre=check')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('counts.no_location', 5);

        // Second import : fiches déjà là, rien en double
        $this->import(false)->assertOk()->assertJsonPath('data.already', 6)->assertJsonPath('data.created', 0);
        $this->assertSame(5, Destination::count());
    }

    public function test_suggestions_fill_the_course_and_a_good_number_corrects_the_directory(): void
    {
        $this->import(false)->assertOk();
        $itwa = $this->itwa();

        // Par sigle, mot de l'adresse ou numéro ; le client les voit (réglage)
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/destinations/suggest?q=itwa')->assertOk()->assertJsonPath('data.0.label', 'ITWA · IMPERIAL TOBACCO WEST AFRICA');
        $this->getJson('/api/v1/destinations/suggest?q=gendarmerie')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/destinations/suggest?q=0748208666')->assertOk()->assertJsonPath('data.0.acronym', 'NAI');

        // Destination sans numéro vérifié : le bon numéro est demandé
        $this->order(['destination_id' => $itwa->id])->assertUnprocessable()->assertJsonValidationErrors('recipient_phone');
        // Saisi une fois : la course part, l'annuaire est corrigé
        $order = $this->order(['destination_id' => $itwa->id, 'recipient_phone' => '27 22 48 98 20'])->assertCreated()
            ->assertJsonPath('data.recipient.name', 'ITWA · IMPERIAL TOBACCO WEST AFRICA')
            ->assertJsonPath('data.delivery.zone_id', $this->cocody->id)
            ->assertJsonPath('data.destination.id', $itwa->id);
        $itwa->refresh();
        $this->assertSame(['+2252722489820', false], [$itwa->phone, $itwa->phone_check]);
        // La fois suivante, la fiche se remplit toute seule
        $this->order(['destination_id' => $itwa->id])->assertCreated()->assertJsonPath('data.recipient.phone', '+2252722489820');

        // Déjà livrée pour ce client : en tête des suggestions
        $this->getJson('/api/v1/destinations/suggest?q=industrie')->assertOk()->assertJsonPath('data.0.mine', 0);
        $this->getJson('/api/v1/destinations/suggest?q=imperial')->assertOk()->assertJsonPath('data.0.mine', 2);

        // L'administrateur réserve les suggestions à l'équipe
        $this->company->update(['destination_settings' => ['clients_see' => false]]);
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/destinations/suggest?q=itwa')->assertForbidden();
    }

    public function test_new_destinations_typed_on_a_course_enrich_the_directory(): void
    {
        $base = ['recipient_name' => 'Banque Atlantique', 'recipient_phone' => '0707070707', 'delivery_zone_id' => $this->plateau->id, 'delivery_address' => 'Avenue Chardy'];
        $id = $this->order($base)->assertCreated()->json('data.id');
        $bank = Destination::where('name', 'Banque Atlantique')->firstOrFail();
        $this->assertSame(['order', 'active', '+2250707070707', $this->plateau->id], [$bank->source, $bank->status, $bank->phone, $bank->zone_id]);
        $this->assertSame($bank->id, Order::find($id)->destination_id);

        // Même numéro : reliée à la même destination, pas de doublon ; un nouveau numéro s'ajoute
        $this->order([...$base, 'recipient_name' => 'B. Atlantique'])->assertCreated();
        $this->order([...$base, 'recipient_phone' => '0505050505'])->assertCreated();
        $this->assertSame(1, Destination::count());
        $this->assertSame(['+2250707070707', '+2250505050505'], $bank->fresh()->phones());

        // À valider par l'équipe : invisible des clients jusque-là
        $this->company->update(['destination_settings' => ['auto_add' => 'pending']]);
        $this->order([...$base, 'recipient_name' => 'Cabinet Kouassi', 'recipient_phone' => '0101020304'])->assertCreated();
        $this->assertSame('pending', Destination::where('name', 'Cabinet Kouassi')->value('status'));
        $this->assertSame(1, $this->admin->notifications()->get()->where('data.kind', 'destination_pending')->count());
        $this->getJson('/api/v1/destinations/suggest?q=kouassi')->assertOk()->assertJsonCount(0, 'data');
        // Jamais
        $this->company->update(['destination_settings' => ['auto_add' => 'off']]);
        $this->order([...$base, 'recipient_name' => 'Notaire Yao', 'recipient_phone' => '0101020305'])->assertCreated();
        $this->assertSame(0, Destination::where('name', 'Notaire Yao')->count());
    }

    public function test_the_courier_confirms_the_position_on_the_spot(): void
    {
        // Accusé de réception sur l'étiquette (et sa photo) : pas l'objet de ce test
        $this->company->update(['activity_options' => ['distribution' => ['receipt_label' => false]]]);
        $this->import(false)->assertOk();
        $nai = Destination::where('acronym', 'NAI')->first();
        $make = function () use ($nai) {
            $order = Order::find($this->order(['destination_id' => $nai->id])->assertCreated()->json('data.id'));
            OrderAssignment::create(['order_id' => $order->id, 'courier_id' => $this->courierA->id, 'type' => 'delivery', 'status' => 'in_progress', 'assigned_at' => now()]);
            $order->forceFill(['status' => OrderStatus::OutForDelivery, 'delivery_courier_id' => $this->courierA->id])->save();

            return $order;
        };
        $order = $make();
        Sanctum::actingAs($this->courierA->user);

        // Trop imprécise : refusée
        $this->postJson("/api/v1/orders/{$order->id}/locate", ['lat' => 5.35, 'lng' => -4.08, 'accuracy' => 300])->assertUnprocessable()->assertJsonValidationErrors('accuracy');
        // Première position : enregistrée
        $this->postJson("/api/v1/orders/{$order->id}/locate", ['lat' => 5.3500000, 'lng' => -4.0800000, 'accuracy' => 25])->assertOk()->assertJsonPath('data.outcome', 'replaced');
        $nai->refresh();
        $this->assertSame([5.35, -4.08, 'courier', 25], [$nai->lat, $nai->lng, $nai->location_source, $nai->location_accuracy]);
        // À côté et plus précise : remplace ; à côté et moins précise : confirme
        $this->postJson("/api/v1/orders/{$order->id}/locate", ['lat' => 5.3502, 'lng' => -4.0801, 'accuracy' => 8])->assertOk()->assertJsonPath('data.outcome', 'replaced');
        $this->postJson("/api/v1/orders/{$order->id}/locate", ['lat' => 5.3501, 'lng' => -4.0801, 'accuracy' => 40])->assertOk()->assertJsonPath('data.outcome', 'kept');
        // Loin de la position connue : gardée pour l'équipe, qui est prévenue
        $this->postJson("/api/v1/orders/{$order->id}/locate", ['lat' => 5.36, 'lng' => -4.09, 'accuracy' => 10])->assertOk()->assertJsonPath('data.outcome', 'conflict');
        $this->assertTrue($nai->fresh()->location_conflict);
        $this->assertSame(5.3502, $nai->fresh()->lat);
        $this->assertSame(1, $this->dispatcher->notifications()->get()->where('data.kind', 'destination_conflict')->count());
        // L'équipe tranche avec la position relevée
        Sanctum::actingAs($this->admin);
        $conflict = $nai->locations()->where('outcome', 'conflict')->value('id');
        $this->postJson("/api/v1/destinations/{$nai->id}/location", ['location_id' => $conflict])->assertOk()
            ->assertJsonPath('data.lat', 5.36)->assertJsonPath('data.location_conflict', false)->assertJsonPath('data.location_source', 'staff');

        // Course suivante : la position confirmée est donnée au livreur
        $next = $make();
        $this->assertSame([5.36, -4.09], [$next->delivery_lat, $next->delivery_lng]);
        $this->getJson("/api/v1/orders/{$next->id}")->assertJsonPath('data.destination.has_location', true);

        // À la livraison, avec la case « enregistrer la position »
        Sanctum::actingAs($this->courierA->user);
        $this->postJson("/api/v1/orders/{$next->id}/status", ['status' => 'delivered', 'received_by_name' => 'Accueil', 'locate' => true, 'lat' => 5.3601, 'lng' => -4.0901, 'accuracy' => 5])->assertOk();
        $nai->refresh();
        $this->assertSame([5.3601, 1], [$nai->lat, $nai->deliveries_count]);
        // Un autre livreur ne peut pas toucher à cette course
        Sanctum::actingAs($this->courierB->user);
        $this->postJson("/api/v1/orders/{$next->id}/locate", ['lat' => 5.36, 'lng' => -4.09, 'accuracy' => 5])->assertForbidden();
    }

    public function test_the_position_can_be_required_for_addresses_without_one(): void
    {
        // Accusé de réception sur l'étiquette (et sa photo) : pas l'objet de ce test
        $this->company->update(['activity_options' => ['distribution' => ['receipt_label' => false]]]);
        $this->import(false)->assertOk();
        $urc = Destination::where('name', 'URC')->first();
        $this->company->update(['destination_settings' => ['courier_locate' => 'required']]);
        $order = Order::find($this->order(['destination_id' => $urc->id, 'recipient_phone' => '0709799319'])->assertCreated()->json('data.id'));
        $order->forceFill(['status' => OrderStatus::OutForDelivery])->save();
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'received_by_name' => 'Accueil'])->assertUnprocessable()->assertJsonValidationErrors('locate');
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'received_by_name' => 'Accueil', 'locate' => true, 'lat' => 5.33, 'lng' => -4.0, 'accuracy' => 12])->assertOk();
        $this->assertNotNull($urc->fresh()->lat);
        // Le bon numéro saisi a aussi corrigé la fiche
        $this->assertSame(['+2250709799319', false], [$urc->fresh()->phone, $urc->fresh()->phone_check]);
    }

    public function test_the_team_corrects_and_merges_duplicates(): void
    {
        $this->import(false)->assertOk();
        $itwa = $this->itwa();
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/destinations/{$itwa->id}", ['phone' => '22489820'])->assertUnprocessable();
        $this->putJson("/api/v1/destinations/{$itwa->id}", ['phone' => '27 22 48 98 20', 'phone_check' => false, 'landmark' => 'Voir SITAB'])->assertOk()
            ->assertJsonPath('data.phones.0', '27 22 48 98 20')->assertJsonPath('data.phone_check', false);

        $copy = Destination::create(['company_id' => $this->company->id, 'name' => 'Imperial Tobacco', 'phone' => '+2250707000001', 'lat' => 5.3, 'lng' => -4.0, 'deliveries_count' => 3]);
        $this->postJson("/api/v1/destinations/{$itwa->id}/merge", ['ids' => [$copy->id]])->assertOk()
            ->assertJsonPath('data.deliveries_count', 3)->assertJsonPath('data.lat', 5.3);
        $this->assertSame(['+2252722489820', '+2250707000001'], $itwa->fresh()->phones());
        $this->assertNull(Destination::find($copy->id));

        // Réservé à l'équipe
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/destinations')->assertForbidden();
    }
}
