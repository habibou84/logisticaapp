<?php

namespace Tests\Feature\Api;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\CourierRating;
use App\Models\CourierRule;
use App\Models\Merchant;
use App\Models\MerchantCourierPreference;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Notifications\CourierRatingAlert;
use App\Services\Finance\PayBonuses;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderWorkflow;
use App\Services\Ratings\CourierScores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Notation des livreurs : clients, équipe, destinataires ; note finale pondérée ; réglages de l'entreprise.
 */
class CourierRatingTest extends TestCase
{
    use BuildsDeliveryWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelTo('2026-11-23 10:00:00');
        $this->courierA->update(['is_available' => true]);
    }

    /** Course livrée par Awa (livreuse A) */
    private function delivered(array $data = [], ?Merchant $merchant = null): Order
    {
        $order = $this->createOrder($data, null, $merchant);
        $order->forceFill(['status' => OrderStatus::PickedUp])->save();
        app(OrderDispatcher::class)->assign($this->dispatcher, $order, AssignmentType::Delivery, $this->courierA);
        $workflow = app(OrderWorkflow::class);
        $workflow->transition($this->dispatcher, $order->fresh(), OrderStatus::OutForDelivery);
        $workflow->transition($this->dispatcher, $order->fresh(), OrderStatus::Delivered, ['collected_amount' => $order->cod_amount]);

        return $order->fresh();
    }

    private function settings(array $values): void
    {
        $this->company->update(['rating_settings' => $values]);
        // Utilisateurs de test : l'entreprise est relue (comme à chaque vraie requête)
        foreach ([$this->merchantUser, $this->admin, $this->dispatcher, $this->courierA->user] as $user) {
            $user->unsetRelation('company');
        }
    }

    public function test_the_client_rates_a_finished_course(): void
    {
        Notification::fake();
        $order = $this->delivered();
        Sanctum::actingAs($this->merchantUser);

        $this->getJson("/api/v1/orders/{$order->id}/rating")->assertOk()
            ->assertJsonPath('data.can_rate', true)
            ->assertJsonPath('data.courier.id', $this->courierA->id);

        // Note basse : un motif est demandé, et les responsables sont prévenus
        $this->postJson("/api/v1/orders/{$order->id}/rating", ['stars' => 2])->assertUnprocessable()->assertJsonValidationErrors('reasons');
        $this->postJson("/api/v1/orders/{$order->id}/rating", ['stars' => 2, 'reasons' => ['late'], 'comment' => 'Deux heures de retard'])->assertCreated();
        Notification::assertSentTo($this->admin, CourierRatingAlert::class, fn ($n) => str_contains($n->body, 'En retard'));

        // Modifiable dans le délai : toujours une seule note
        $this->postJson("/api/v1/orders/{$order->id}/rating", ['stars' => 4, 'criteria' => ['politeness', 'care', 'unknown']])->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$order->id}/rating", ['stars' => 4, 'criteria' => ['politeness', 'care']])->assertCreated()
            ->assertJsonPath('data.criteria', ['politeness', 'care']);
        $this->assertSame(1, CourierRating::where('order_id', $order->id)->count());

        // Passé le délai (48 h par défaut) : plus possible
        $this->travel(49)->hours();
        $this->postJson("/api/v1/orders/{$order->id}/rating", ['stars' => 5])->assertUnprocessable();
        $this->getJson("/api/v1/orders/{$order->id}/rating")->assertJsonPath('data.can_rate', false)->assertJsonPath('data.rating.stars', 4);

        // Pas la course d'un autre client, pas une course en cours
        $other = $this->createOrder();
        $this->postJson("/api/v1/orders/{$other->id}/rating", ['stars' => 5])->assertUnprocessable();
    }

    public function test_courses_to_rate_and_disabled_rating(): void
    {
        $this->delivered();
        $rated = $this->delivered();
        Sanctum::actingAs($this->merchantUser);
        $this->postJson("/api/v1/orders/{$rated->id}/rating", ['stars' => 5])->assertCreated();
        $this->assertCount(1, $this->getJson('/api/v1/merchant/ratings/pending')->assertOk()->json('data'));

        $this->settings(['client_course' => false]);
        $this->assertCount(0, $this->getJson('/api/v1/merchant/ratings/pending')->json('data'));
        $this->postJson("/api/v1/orders/{$rated->id}/rating", ['stars' => 5])->assertUnprocessable();
    }

    public function test_final_score_mixes_sources_with_settings(): void
    {
        $scores = app(CourierScores::class);
        $this->assertTrue($scores->for($this->courierA)['new'], 'nouveau livreur : pas de note');

        // 3 courses livrées, notées 5 par le client : note automatique 5 ; clients pas encore comptés (5 notes minimum)
        Sanctum::actingAs($this->merchantUser);
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/orders/'.$this->delivered()->id.'/rating', ['stars' => 5])->assertCreated();
        }
        $score = $scores->for($this->courierA);
        $this->assertSame([5.0, false, 3], [$score['score'], $score['client']['counted'], $score['client']['count']]);

        // Équipe : évaluation à 2 ; minimum de notes de clients abaissé à 3
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/couriers/{$this->courierA->id}/ratings", ['stars' => 2, 'criteria' => ['cash' => 1, 'behaviour' => 3], 'comment' => 'Retards de versement'])->assertCreated();
        $this->settings(['min_client_ratings' => 3]);
        $score = $scores->for($this->courierA);
        // (5×40 + 5×35 + 2×25) / 100 = 4,25
        $this->assertSame(4.3, $score['score']);
        $this->assertSame(['cash' => 1.0, 'behaviour' => 3.0], $score['team']['criteria']);

        // Poids changés : l'équipe seule
        $this->settings(['min_client_ratings' => 3, 'weight_auto' => 0, 'weight_client' => 0, 'weight_team' => 100]);
        $this->assertSame(2.0, $scores->for($this->courierA)['score']);

        // Qualité des livreurs et fiche du livreur
        $this->getJson('/api/v1/courier-quality')->assertOk()->assertJsonPath('data.0.id', $this->courierA->id)->assertJsonPath('data.0.alert', true);
        $this->getJson("/api/v1/couriers/{$this->courierA->id}/score")->assertOk()->assertJsonCount(3, 'data.trend')->assertJsonCount(4, 'data.ratings');
    }

    public function test_an_unfair_rating_is_neutralized_and_incidents_weigh_less(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['status' => OrderStatus::PickedUp, 'attempts_count' => 1])->save();
        app(OrderDispatcher::class)->assign($this->dispatcher, $order, AssignmentType::Delivery, $this->courierA);
        app(OrderWorkflow::class)->transition($this->dispatcher, $order->fresh(), OrderStatus::OutForDelivery);
        app(OrderWorkflow::class)->transition($this->dispatcher, $order->fresh(), OrderStatus::Delivered, ['collected_amount' => $order->cod_amount]);
        Sanctum::actingAs($this->merchantUser);
        $id = $this->postJson("/api/v1/orders/{$order->id}/rating", ['stars' => 1, 'reasons' => ['other'], 'comment' => 'Trop cher'])->assertCreated()->json('data.id');
        $this->assertSame(0.5, CourierRating::find($id)->weight, 'tentative ratée avant : la note compte moitié');

        // Le dispatcher ne neutralise pas ; l'administrateur oui, et peut revenir dessus
        Sanctum::actingAs($this->dispatcher);
        $this->postJson("/api/v1/courier-ratings/{$id}/neutralize", ['reason' => 'Prix'])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/courier-ratings/{$id}/neutralize", ['reason' => 'Mécontent du prix, pas du livreur'])->assertOk();
        $this->assertSame(0, app(CourierScores::class)->for($this->courierA)['client']['count']);
        $this->postJson("/api/v1/courier-ratings/{$id}/restore")->assertOk();
        $this->assertSame(1, app(CourierScores::class)->for($this->courierA)['client']['count']);
    }

    public function test_the_courier_sees_his_score_anonymously(): void
    {
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/orders/'.$this->delivered()->id.'/rating', ['stars' => 5, 'comment' => 'Très aimable'])->assertCreated();

        Sanctum::actingAs($this->courierA->user);
        $this->getJson('/api/v1/courier/rating')->assertOk()
            ->assertJsonPath('data.comments.0.comment', 'Très aimable')
            ->assertJsonPath('data.comments.0.from', null);

        $this->settings(['comments_anonymous' => false]);
        $this->getJson('/api/v1/courier/rating')->assertJsonPath('data.comments.0.from', $this->merchant->business_name);
        $this->settings(['courier_sees_comments' => false]);
        $this->getJson('/api/v1/courier/rating')->assertJsonPath('data.comments', []);
        $this->settings(['courier_sees_score' => false]);
        $this->getJson('/api/v1/courier/rating')->assertJsonPath('data', null);
    }

    public function test_the_recipient_rates_from_the_tracking_page_when_allowed(): void
    {
        $order = $this->delivered();
        $this->getJson("/api/v1/tracking/{$order->tracking_code}")->assertJsonPath('data.rating_open', false);
        $this->postJson("/api/v1/tracking/{$order->tracking_code}/rating", ['stars' => 5, 'delivery_code' => $order->delivery_code])->assertUnprocessable();

        $this->settings(['recipient_rating' => true]);
        $this->getJson("/api/v1/tracking/{$order->tracking_code}")->assertJsonPath('data.rating_open', true);
        $this->postJson("/api/v1/tracking/{$order->tracking_code}/rating", ['stars' => 5, 'delivery_code' => '0000'])->assertUnprocessable()->assertJsonValidationErrors('delivery_code');
        $this->postJson("/api/v1/tracking/{$order->tracking_code}/rating", ['stars' => 5, 'delivery_code' => $order->delivery_code, 'comment' => 'Parfait'])->assertCreated();
        $this->postJson("/api/v1/tracking/{$order->tracking_code}/rating", ['stars' => 1, 'delivery_code' => $order->delivery_code])->assertUnprocessable();
        $this->assertSame('recipient', CourierRating::sole()->source);
    }

    public function test_mad_client_rates_its_courier_each_month(): void
    {
        $mad = Merchant::factory()->create(['company_id' => $this->company->id, 'business_name' => 'Ivoire Assurances']);
        $mad->syncActivities(['mad']);
        $client = $this->userWithRole(Role::MerchantOwner, ['merchant_id' => $mad->id]);
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/merchants/{$mad->id}/billing-plans", [
            'formula' => 'mad_contract', 'monthly_fee' => 300000, 'included_quantity' => 100, 'delivery_delay' => '24h', 'starts_on' => '2026-11-01', 'courier_ids' => [$this->courierA->id],
        ])->assertCreated();

        Sanctum::actingAs($client);
        $this->getJson('/api/v1/merchant/ratings/pending')->assertOk()->assertJsonPath('mad.0.courier_id', $this->courierA->id)->assertJsonPath('mad.0.rating', null);
        $this->postJson("/api/v1/merchant/couriers/{$this->courierA->id}/rating", ['stars' => 4, 'criteria' => ['presence']])->assertCreated();
        $this->postJson("/api/v1/merchant/couriers/{$this->courierA->id}/rating", ['stars' => 5])->assertCreated();
        $this->assertSame(1, CourierRating::where('scope', 'period')->count());
        $this->postJson("/api/v1/merchant/couriers/{$this->courierB->id}/rating", ['stars' => 5])->assertUnprocessable();

        // Mois suivant : une nouvelle note
        $this->travelTo('2026-12-02 10:00:00');
        $this->postJson("/api/v1/merchant/couriers/{$this->courierA->id}/rating", ['stars' => 3])->assertCreated();
        $this->assertSame(2, CourierRating::where('scope', 'period')->count());
    }

    public function test_client_preferences_need_the_setting(): void
    {
        $this->delivered();
        Sanctum::actingAs($this->merchantUser);
        $this->putJson("/api/v1/merchant/courier-preferences/{$this->courierA->id}", ['kind' => 'excluded'])->assertUnprocessable();

        $this->settings(['client_choice' => true]);
        $this->putJson("/api/v1/merchant/courier-preferences/{$this->courierA->id}", ['kind' => 'excluded'])->assertOk()->assertJsonPath('data.0.kind', 'excluded');
        // Un livreur qui n'a jamais fait de course du client : non
        $this->putJson("/api/v1/merchant/courier-preferences/{$this->courierB->id}", ['kind' => 'preferred'])->assertForbidden();
        $this->putJson("/api/v1/merchant/courier-preferences/{$this->courierA->id}", ['kind' => null])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_rating_settings_are_saved(): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['rating_settings' => ['weight_auto' => 50, 'recipient_rating' => true, 'unknown' => 1, 'client_mad_period' => 'weekly']])->assertOk()
            ->assertJsonPath('data.rating_settings.weight_auto', 50)
            ->assertJsonPath('data.rating_settings.weight_client', 35)
            ->assertJsonPath('data.rating_settings.client_mad_period', 'weekly');
        $this->assertArrayNotHasKey('unknown', $this->company->fresh()->rating_settings);
        $this->patchJson("/api/v1/companies/{$this->company->id}", ['rating_settings' => ['client_mad_period' => 'daily']])->assertUnprocessable();
    }

    public function test_excluded_and_preferred_couriers_in_auto_assignment(): void
    {
        $this->company->update(['auto_pickup' => 'on']);
        $this->courierB->update(['is_available' => true]);
        CourierRule::create(['company_id' => $this->company->id, 'type' => 'pickup', 'courier_id' => $this->courierA->id, 'merchant_ids' => [$this->merchant->id]]);
        MerchantCourierPreference::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'courier_id' => $this->courierA->id, 'kind' => 'excluded']);
        $confirm = function () {
            $order = $this->createOrder();
            app(OrderWorkflow::class)->transition($this->dispatcher, $order, OrderStatus::Confirmed);

            return $order->fresh();
        };

        // Choix des clients désactivé : le planning s'applique
        $this->assertSame($this->courierA->id, $confirm()->pickup_courier_id);

        // Activé : le livreur écarté ne reçoit plus les courses du client
        $this->settings(['client_choice' => true]);
        $this->assertNull($confirm()->pickup_courier_id);

        // Préféré disponible : prioritaire
        MerchantCourierPreference::create(['company_id' => $this->company->id, 'merchant_id' => $this->merchant->id, 'courier_id' => $this->courierB->id, 'kind' => 'preferred']);
        $order = $confirm();
        $this->assertSame([$this->courierB->id, 'preferred'], [$order->pickup_courier_id, $order->assignments()->sole()->source]);
    }

    public function test_rating_bonus_alerts_and_whatsapp_invite(): void
    {
        // Prime : note de la période sur 100
        $this->payPlan(['delivery' => 0], $this->courierA, ['pay_period' => 'monthly']);
        Sanctum::actingAs($this->merchantUser);
        $this->postJson('/api/v1/orders/'.$this->delivered()->id.'/rating', ['stars' => 2, 'reasons' => ['late']])->assertCreated();
        $measures = app(PayBonuses::class)->measure($this->courierA, now()->startOfMonth(), now());
        $this->assertSame(100, $measures['rating'], 'note automatique seule (moins de 5 notes de clients) : 5 / 5');

        // Alertes : seuil relevé, une par semaine au plus
        Notification::fake();
        $this->settings(['alert_below' => 5.1]);
        $this->artisan('couriers:rating-alerts')->expectsOutput('1 alerte(s) envoyée(s).');
        Notification::assertSentTo($this->admin, CourierRatingAlert::class, fn ($n) => $n->kind === 'courier_low_score');
        $this->artisan('couriers:rating-alerts')->expectsOutput('0 alerte(s) envoyée(s).');

        // Invitation WhatsApp au client après la livraison
        $this->merchant->update(['whatsapp_phone' => '0501020304']);
        $this->settings(['whatsapp_invite' => true]);
        $this->delivered();
        $message = OutboundMessage::where('template_name', 'course_a_noter')->sole();
        $this->assertStringContainsString(strtok($this->courierA->user->name, ' '), $message->body);
    }
}
