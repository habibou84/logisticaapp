<?php

namespace App\Console\Commands;

use App\Enums\Activity;
use App\Enums\AssignmentType;
use App\Enums\BillingFormula;
use App\Enums\FeePayer;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\VehicleType;
use App\Events\CourierLocationUpdated;
use App\Events\OrderChanged;
use App\Exceptions\BusinessRuleException;
use App\Jobs\RetryWebPush;
use App\Jobs\SendOutboundMessage;
use App\Jobs\SendWebhook;
use App\Models\BillingPlan;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\Courier;
use App\Models\DistributionRate;
use App\Models\IncidentReason;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OutboundMessage;
use App\Models\PayPlan;
use App\Models\PricingGrid;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use App\Models\Zone;
use App\Services\CompanyProvisioner;
use App\Services\Finance\CashDesk;
use App\Services\Finance\ClientInvoicing;
use App\Services\Finance\MerchantPayouts;
use App\Services\Messaging\OrderMessages;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderWorkflow;
use App\Services\Webhooks\WebhookDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Données fictives pour la préproduction : clients, livreurs, équipe et un historique de courses
 * (créées, ramassées, livrées, en échec, retournées, annulées) passé par les vrais services, donc avec
 * prix, journal, encaissements, versements des livreurs et reversements aux clients cohérents.
 *
 * Aucun message ne part pendant la génération (WhatsApp, SMS, notifications, webhooks).
 * Refusé en production. Relançable : les jours qui ont déjà des courses fictives sont sautés,
 * donc la relancer chaque matin ajoute seulement les courses du jour.
 */
class PreprodData extends Command
{
    /** Marque des comptes fictifs : téléphones en +225 07 99 … et références de course en DEMO- */
    private const PHONE_PREFIX = '0799';

    private const REFERENCE_PREFIX = 'DEMO-';

    private const GRID_NAME = 'Clients fictifs (préproduction)';

    protected $signature = 'app:preprod-data
        {--days=30 : Nombre de jours d\'historique (aujourd\'hui compris)}
        {--per-day=18 : Courses par jour, environ}
        {--password=Preprod2026 : Mot de passe des comptes fictifs}
        {--seed= : Graine du hasard (mêmes données à chaque lancement)}';

    protected $description = 'Remplit la préproduction de données fictives (clients, livreurs, courses, caisse) ; refusé en production';

    private Company $company;

    private User $admin;

    /** @var Collection<int, Courier> */
    private Collection $couriers;

    /** @var Collection<int, Merchant> */
    private Collection $merchants;

    /** @var Collection<string, Zone> */
    private Collection $zones;

    /** @var Collection<string, Zone> */
    private Collection $pickupZones;

    private PricingGrid $grid;

    /** @var array<string, IncidentReason|null> motif d'échec de livraison, par activité */
    private array $failReasons = [];

    /**
     * Clients facturés au mois (distribution, MAD) : client, activité, livreurs dédiés (MAD).
     *
     * @var list<array{merchant: Merchant, activity: Activity, couriers: Collection<int, Courier>}>
     */
    private array $billed = [];

    /** @var array<string, int> erreur → nombre de courses */
    private array $errors = [];

    /** @var array<string, int> */
    private array $stats = [];

    /** Heure au lancement : la génération ne place jamais rien après */
    private Carbon $clock;

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusé : APP_ENV=production. Ces données fictives sont réservées à la préproduction et aux postes de développement.');

            return self::FAILURE;
        }

        $company = Company::query()->orderBy('id')->first();
        if ($company === null) {
            $this->error('Aucune entreprise : lancez d\'abord php artisan app:install.');

            return self::FAILURE;
        }
        $this->company = $company;

        $admin = User::query()->where('company_id', $company->id)->role(Role::Admin->value)->orderBy('id')->first();
        if ($admin === null) {
            $this->error('Aucun administrateur dans l\'entreprise : lancez d\'abord php artisan app:install.');

            return self::FAILURE;
        }
        $this->admin = $admin;

        if ($this->option('seed') !== null) {
            mt_srand((int) $this->option('seed'));
        }

        $this->mute();
        $before = Carbon::getTestNow();
        $this->clock = now()->copy();
        $firstMessageId = (int) OutboundMessage::query()->max('id');

        try {
            $days = max(1, (int) $this->option('days'));
            $this->setUp($days);
            $this->history($days, max(1, (int) $this->option('per-day')));
            $this->invoices($days);
        } finally {
            Carbon::setTestNow($before);
            // Filet de sécurité : un message préparé malgré tout (avis de reversement…) n'est jamais parti
            // (envoi bloqué dans mute()) ; il est retiré du journal des messages
            OutboundMessage::query()->where('id', '>', $firstMessageId)->where('to', 'like', '+225'.self::PHONE_PREFIX.'%')->delete();
        }

        $this->report();

        return self::SUCCESS;
    }

    /**
     * Rien ne sort pendant la génération : ni WhatsApp ou SMS aux numéros fictifs, ni notifications à l'équipe,
     * ni webhooks vers Jibiat Marchand, ni diffusion temps réel.
     */
    private function mute(): void
    {
        Notification::fake();
        Event::fake([OrderChanged::class, CourierLocationUpdated::class]);
        Queue::fake([SendOutboundMessage::class, SendWebhook::class, RetryWebPush::class]);

        app()->instance(OrderMessages::class, new class extends OrderMessages
        {
            public function __construct() {}

            public function handle(Order $order, OrderEvent $event, ?User $actor): void {}

            public function confirmedForDate(Order $order, string $reason, bool $postponed = false, bool $received = false): void {}
        });

        app()->instance(WebhookDispatcher::class, new class extends WebhookDispatcher
        {
            public function __construct() {}

            public function onOrderEvent(Order $order, OrderEvent $event): void {}

            public function onPayoutPaid(MerchantPayout $payout): void {}

            public function onLowStock(Product $product, int $available): void {}

            public function dispatch(int $companyId, int $merchantId, string $event, array $data): void {}
        });
    }

    // ───────────────────────────── Référentiel ─────────────────────────────

    private function setUp(int $days): void
    {
        $this->zones = $this->deliveryZones();
        if ($this->zones->isEmpty()) {
            app(CompanyProvisioner::class)->provision($this->company);
            $this->zones = $this->deliveryZones();
        }
        $this->pickupZones = $this->zones->filter(fn (Zone $z) => $z->is_pickup);
        if ($this->pickupZones->isEmpty()) {
            $this->pickupZones = $this->zones;
        }

        $this->grid = $this->pricing();
        $this->payPlan();
        $this->team();
        $this->couriers = $this->couriers();
        $this->merchants = $this->merchants();
        $this->billed = $this->billedClients($this->clock->copy()->subDays($days)->startOfMonth());

        $reasons = IncidentReason::availableTo($this->company->id)->get();
        foreach (Activity::cases() as $activity) {
            $this->failReasons[$activity->value] = $reasons->first(fn (IncidentReason $r) => $r->appliesTo('delivery')
                && $r->forActivity($activity)
                && $r->counts_as_attempt && ! $r->requires_note && ! $r->requires_date && $r->photo !== 'required');
        }
    }

    /**
     * Clients de distribution et de MAD, avec leur formule. Réglages du contrat : nom de la personne qui reçoit
     * comme preuve (sans photo ni signature, que la génération ne peut pas fournir) et aucun message au
     * destinataire. MAD : un livreur dédié par contrat, du lundi au samedi.
     *
     * @return list<array{merchant: Merchant, activity: Activity, couriers: Collection<int, Courier>}>
     */
    private function billedClients(Carbon $startsOn): array
    {
        $options = ['proof_name' => true, 'proof_signature' => false, 'proof_photo' => false, 'receipt_label' => false, 'notify_recipient' => false];
        $mad = [...$options, 'mad_exclusive' => true, 'mad_days' => [1, 2, 3, 4, 5, 6], 'mad_from' => '07:30', 'mad_to' => '19:00'];
        // [enseigne, contact, commune, adresse, repère, lat, lng, n° (téléphone, compte client si vrai), formule, contrat, livreur MAD]
        $clients = [
            ['Assurances Éburnéa', 'Corinne Aké', 'Plateau', 'Avenue Chardy, immeuble Le Méridien', 'Accueil au rez-de-chaussée', 5.3240, -4.0170, [11, true],
                BillingFormula::DistributionContract, ['monthly_fee' => 150000, 'included_quantity' => 300, 'overage_price' => 700, 'delivery_delay' => '48h', 'cheque_price' => 1500, 'extra_kg_price' => 300, 'options' => $options], null],
            ['Cabinet Kouamé & Associés', 'Me Rodrigue Kouamé', 'Cocody', 'II Plateaux, rue des Jardins', 'Villa 12, portail gris', 5.3700, -3.9950, [12, false],
                BillingFormula::DistributionUnit, ['delivery_delay' => '24h', 'cheque_price' => 0, 'extra_kg_price' => 300, 'options' => $options], null],
            ['Pharma Lagune Distribution', 'Dr Awa Diomandé', 'Koumassi', 'Zone industrielle, rue des Entrepôts', 'Entrepôt bleu, quai 3', 5.2990, -3.9620, [13, true],
                BillingFormula::MadContract, ['monthly_fee' => 350000, 'included_quantity' => 600, 'overage_price' => 500, 'delivery_delay' => '24h', 'options' => $mad], ['Ismaël Bakayoko', 9]],
            ['Restaurant Chez Tantie Rose', 'Rose Gnahoré', 'Marcory', 'Zone 4, rue Pierre et Marie Curie', 'Face à la boulangerie', 5.2930, -3.9850, [14, false],
                BillingFormula::MadContract, ['monthly_fee' => 200000, 'included_quantity' => 300, 'overage_price' => 500, 'delivery_delay' => '24h', 'options' => $mad], ['Yannick Kipré', 10]],
        ];

        return collect($clients)->map(function (array $client) use ($startsOn) {
            [$name, $contact, $commune, $address, $landmark, $lat, $lng, [$n, $hasAccount], $formula, $terms, $courier] = $client;
            $phone = '+225'.self::PHONE_PREFIX.sprintf('0002%02d', $n);
            $zone = $this->pickupZones->get($commune) ?? $this->pickupZones->values()->get($n % $this->pickupZones->count());
            $activity = $formula->activity();

            $merchant = Merchant::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $this->company->id, 'phone' => $phone],
                [
                    'business_name' => $name, 'contact_name' => $contact, 'whatsapp_phone' => $phone,
                    'pickup_address' => $address, 'pickup_landmark' => $landmark, 'pickup_lat' => $lat, 'pickup_lng' => $lng,
                    'default_fee_payer' => FeePayer::Merchant, 'notes' => 'Client fictif (données de préproduction)',
                ],
            );
            $merchant->forceFill(['pickup_zone_id' => $zone->id, 'pricing_grid_id' => $this->grid->id, 'status' => 'active'])->save();
            $merchant->syncActivities([$activity]);
            if ($hasAccount) {
                $this->account($contact, sprintf('000002%02d', $n), Role::MerchantOwner, $merchant);
            }

            $plan = BillingPlan::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('activity', $activity->value)->first()
                ?? BillingPlan::create([
                    'monthly_fee' => 0, 'included_quantity' => 0, 'overage_price' => 0, 'cheque_price' => 0, 'extra_kg_price' => 0,
                    ...$terms,
                    'company_id' => $this->company->id, 'merchant_id' => $merchant->id,
                    'formula' => $formula, 'activity' => $activity, 'starts_on' => $startsOn->toDateString(),
                    'notes' => 'Contrat fictif (données de préproduction)',
                ]);
            if ($plan->starts_on->greaterThan($startsOn)) {
                $plan->update(['starts_on' => $startsOn->toDateString()]);
            }

            // À l'unité : prix propres au client (la grille commune de l'entreprise n'est pas touchée)
            if ($formula === BillingFormula::DistributionUnit) {
                foreach (['24h' => [1500, 2500, 2000], '48h' => [1200, 2000, 1500], '72h' => [1000, 1700, 1200]] as $delay => [$pli, $colis, $cheque]) {
                    foreach (['pli' => $pli, 'colis' => $colis, 'cheque' => $cheque] as $type => $price) {
                        DistributionRate::withoutGlobalScopes()->firstOrCreate(
                            ['company_id' => $this->company->id, 'merchant_id' => $merchant->id, 'delivery_delay' => $delay, 'shipment_type' => $type],
                            ['price' => $price],
                        );
                    }
                }
            }

            $couriers = collect();
            if ($courier !== null) {
                [$courierName, $courierN] = $courier;
                $user = $this->account($courierName, sprintf('000001%02d', $courierN), Role::Courier);
                $mad = Courier::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
                $mad->update(['vehicle_type' => VehicleType::Moto, 'is_available' => true, 'current_lat' => $lat, 'current_lng' => $lng, 'last_location_at' => now()]);
                $plan->couriers()->syncWithoutDetaching([$mad->id]);
                $couriers->push($mad->load('user'));
            }

            return ['merchant' => $merchant, 'activity' => $activity, 'couriers' => $couriers];
        })->all();
    }

    /**
     * Communes (ou villes) actives où l'on livre, hors gares d'expédition ; celles d'Abidjan d'abord,
     * puisque les adresses fictives y sont.
     *
     * @return Collection<string, Zone>
     */
    private function deliveryZones(): Collection
    {
        $zones = Zone::forCompany($this->company->id)->whereNull('parent_id')->where('is_shipping', false)
            ->where('is_active', true)->orderBy('sort_order')->get();
        $abidjan = $zones->filter(fn (Zone $z) => $z->city === 'Abidjan');

        return ($abidjan->isNotEmpty() ? $abidjan : $zones)->keyBy('name');
    }

    /**
     * Grille à part pour les clients fictifs : les tarifs réglés de l'entreprise ne sont pas touchés, et chaque
     * course fictive a un prix (1 000 F dans la même commune, 1 500 F entre communes).
     */
    private function pricing(): PricingGrid
    {
        $grid = PricingGrid::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->company->id, 'name' => self::GRID_NAME],
            ['is_default' => false, 'base_price' => 1500],
        );
        $grid->update(['base_price' => 1500]);

        foreach ($this->zones as $zone) {
            PricingRule::query()->firstOrCreate(
                ['pricing_grid_id' => $grid->id, 'origin_zone_id' => $zone->id, 'destination_zone_id' => $zone->id],
                ['price' => 1000, 'is_symmetric' => true],
            );
        }

        return $grid;
    }

    private function payPlan(): void
    {
        $plan = PayPlan::defaultFor($this->company->id);

        if ($plan && ! $plan->rules()->exists()) {
            foreach (['pickup' => 300, 'delivery' => 500, 'failed_attempt' => 200, 'return' => 400] as $event => $amount) {
                $plan->rules()->create(['event' => $event, 'calc' => 'fixed', 'amount' => $amount]);
            }
        }
    }

    private function team(): void
    {
        $this->account('Fatou Bamba', '00000001', Role::Dispatcher);
        $this->account('Serge Kouadio', '00000002', Role::Cashier);
        $this->account('Ibrahim Touré', '00000003', Role::HubAgent);
    }

    /**
     * @return Collection<int, Courier>
     */
    private function couriers(): Collection
    {
        $people = [
            ['Koffi Yao', [5.3598, -3.9875]], ['Adama Coulibaly', [5.3364, -4.0267]], ['Moussa Diabaté', [5.4170, -4.0200]],
            ['Jean-Marc N\'Guessan', [5.3005, -3.9960]], ['Awa Traoré', [5.3236, -4.0187]], ['Didier Kouamé', [5.3450, -4.0750]],
            ['Salif Ouattara', [5.2900, -3.9500]], ['Brice Aka', [5.3700, -3.9300]],
        ];

        return collect($people)->map(function (array $person, int $i) {
            [$name, [$lat, $lng]] = $person;
            $user = $this->account($name, sprintf('000001%02d', $i + 1), Role::Courier);
            $courier = Courier::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
            $courier->update([
                'vehicle_type' => $i === 7 ? VehicleType::Voiture : VehicleType::Moto,
                'vehicle_plate' => sprintf('%04d %s 01', 1200 + $i * 37, ['AB', 'CD', 'EF', 'GH', 'JK', 'LM', 'NP', 'QR'][$i]),
                'is_available' => true,
                'current_lat' => $lat, 'current_lng' => $lng, 'last_location_at' => now(),
            ]);

            return $courier->load('user');
        });
    }

    /**
     * @return Collection<int, Merchant>
     */
    private function merchants(): Collection
    {
        // [enseigne, contact, commune, adresse, repère, lat, lng, compte client ?, frais payés par]
        $shops = [
            ['Boutique Chic Abidjan', 'Mariam Koné', 'Cocody', 'Riviera 2, rue des Jardins', 'Face pharmacie des Jardins', 5.3631, -3.9735, true, FeePayer::Merchant],
            ['Wax & Pagnes Treichville', 'Aminata Sylla', 'Treichville', 'Avenue 12, rue 21', 'Près du marché de Belleville', 5.2980, -4.0070, true, FeePayer::Recipient],
            ['Électro Plus Adjamé', 'Hamed Cissé', 'Adjamé', 'Boulevard Nangui Abrogoua', 'Forum des marchés, 1er étage', 5.3530, -4.0230, false, FeePayer::Merchant],
            ['Bio Saveurs', 'Christelle Ehui', 'Marcory', 'Zone 4, rue du Docteur Blanchard', 'À côté de la boulangerie Pâtisserie Abidjanaise', 5.2950, -3.9800, true, FeePayer::Merchant],
            ['Pharmacie de la Paix', 'Dr Yao Konan', 'Yopougon', 'Siporex, carrefour Sideci', 'Face à la station Total', 5.3330, -4.0750, false, FeePayer::Merchant],
            ['Librairie Le Savoir', 'Paul Gnagne', 'Plateau', 'Avenue Franchet d\'Esperey', 'Immeuble Nour Al Hayat', 5.3220, -4.0180, false, FeePayer::Recipient],
            ['Cosmétiques Belle Peau', 'Nadia Bakayoko', 'Cocody', 'Angré 8e tranche', 'Derrière le château d\'eau', 5.3990, -3.9930, false, FeePayer::Recipient],
            ['Tech Store CI', 'Arnaud Kacou', 'Plateau', 'Rue du Commerce', 'Immeuble CCIA, rez-de-chaussée', 5.3195, -4.0205, false, FeePayer::Merchant],
            ['Saveurs du Nord', 'Issouf Sanogo', 'Abobo', 'Abobo Gare, rue de la Mairie', 'En face de la mairie', 5.4170, -4.0200, false, FeePayer::Merchant],
            ['Bébé Confort', 'Sandrine Amon', 'Koumassi', 'Remblais, boulevard du 7 Décembre', 'Près de l\'église Sainte-Bernadette', 5.2970, -3.9500, false, FeePayer::Recipient],
        ];

        return collect($shops)->map(function (array $shop, int $i) {
            [$name, $contact, $commune, $address, $landmark, $lat, $lng, $hasAccount, $feePayer] = $shop;
            $phone = '+225'.self::PHONE_PREFIX.sprintf('0002%02d', $i + 1);
            $zone = $this->pickupZones->get($commune) ?? $this->pickupZones->values()->get($i % $this->pickupZones->count());

            $merchant = Merchant::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $this->company->id, 'phone' => $phone],
                [
                    'business_name' => $name, 'contact_name' => $contact, 'whatsapp_phone' => $phone,
                    'pickup_address' => $address, 'pickup_landmark' => $landmark,
                    'pickup_lat' => $lat, 'pickup_lng' => $lng, 'default_fee_payer' => $feePayer,
                    'notes' => 'Client fictif (données de préproduction)',
                ],
            );
            // Aussi pour un client fictif déjà créé : zone où l'on ramasse, grille fictive, compte actif
            $merchant->forceFill(['pickup_zone_id' => $zone->id, 'pricing_grid_id' => $this->grid->id, 'status' => 'active'])->save();

            if ($hasAccount) {
                $this->account($contact, sprintf('000002%02d', $i + 1), Role::MerchantOwner, $merchant);
            }

            return $merchant;
        });
    }

    private function account(string $name, string $suffix, Role $role, ?Merchant $merchant = null): User
    {
        $phone = '+225'.self::PHONE_PREFIX.substr($suffix, 2);
        $user = User::query()->where('phone', $phone)->first();

        if ($user === null) {
            $user = User::create([
                'company_id' => $this->company->id,
                'merchant_id' => $merchant?->id,
                'name' => $name,
                'phone' => $phone,
                'password' => (string) $this->option('password'),
            ]);
            $user->syncRoles([$role->value]);
            $this->count('comptes créés');
        }
        $user->syncCourierProfile();

        return $user;
    }

    // ───────────────────────────── Historique ─────────────────────────────

    private function history(int $days, int $perDay): void
    {
        $today = $this->clock->copy()->startOfDay();
        $bar = $this->output->createProgressBar($days);
        $bar->start();

        for ($ago = $days - 1; $ago >= 0; $ago--) {
            $day = $today->copy()->subDays($ago);
            $bar->advance();

            // Dimanche : peu d'e-commerce, ni distribution ni MAD ; activité déjà remplie ce jour-là : sautée
            // (relance sans doublons, et une relance ajoute une activité nouvelle aux jours déjà remplis)
            if (! $this->dayDone($day, Activity::Ecommerce)) {
                $count = $day->isSunday() ? intdiv($perDay, 4) : max(1, $perDay + mt_rand(-3, 3));
                for ($n = 0; $n < $count; $n++) {
                    $this->order($day, $ago);
                }
            }

            foreach ([Activity::Distribution, Activity::Mad] as $activity) {
                if ($day->isSunday() || $this->dayDone($day, $activity)) {
                    continue;
                }
                $clients = array_values(array_filter($this->billed, fn (array $b) => $b['activity'] === $activity));
                $count = max(2, intdiv($perDay, 3) + mt_rand(-2, 2));
                for ($n = 0; $n < $count && $clients !== []; $n++) {
                    $this->order($day, $ago, $this->pick($clients));
                }
            }

            if ($ago > 0) {
                $this->closeDay($day);
            }
        }

        $bar->finish();
        $this->newLine(2);
    }

    private function dayDone(Carbon $day, Activity $activity): bool
    {
        return Order::withoutGlobalScopes()->where('company_id', $this->company->id)->where('activity', $activity->value)
            ->where('merchant_reference', 'like', self::REFERENCE_PREFIX.$day->format('ymd').'%')->exists();
    }

    /**
     * Une course : créée dans la matinée, puis menée jusqu'à son état final (jours passés)
     * ou jusqu'à une étape de la journée en cours (aujourd'hui).
     */
    /**
     * @param  array{merchant: Merchant, activity: Activity, couriers: Collection<int, Courier>}|null  $billed  client facturé au mois
     */
    private function order(Carbon $day, int $ago, ?array $billed = null): void
    {
        $at = $day->copy()->setTime(mt_rand(7, 13), mt_rand(0, 59));
        // Aujourd'hui : rien dans le futur
        if ($ago === 0 && $at->greaterThan($this->clock)) {
            $at = $this->clock->copy()->subMinutes(mt_rand(30, 180))->max($day->copy()->setTime(0, 5));
        }
        $this->at($at);

        $target = $ago === 0 ? $this->todayTarget() : $this->pastTarget();
        $merchant = $billed['merchant'] ?? $this->merchants->random();
        $data = $billed ? $this->billedData($billed['activity'], $day) : $this->orderData($merchant, $day);

        try {
            $order = app(OrderService::class)->create($this->admin, $merchant, $data, 'dashboard');
            $this->count('courses '.($billed ? $billed['activity']->label() : Activity::Ecommerce->label()));
            $this->drive($order, $target, $billed['couriers'] ?? null);
            $this->count('→ '.$order->fresh()->status->label());
        } catch (Throwable $e) {
            $this->count('courses en erreur');
            $message = $e->getMessage() !== '' ? $e->getMessage() : class_basename($e);
            $this->errors[$message] = ($this->errors[$message] ?? 0) + 1;
            if ($this->output->isVerbose()) {
                $this->warn($message);
            }
        }
    }

    /** État final d'une course des jours passés */
    private function pastTarget(): string
    {
        $r = mt_rand(1, 100);

        return match (true) {
            $r <= 76 => 'delivered',
            $r <= 82 => 'delivered_second_try',
            $r <= 88 => 'returned',
            $r <= 93 => 'cancelled',
            $r <= 95 => 'rejected',
            default => 'rescheduled',
        };
    }

    /** Étape atteinte par une course du jour */
    private function todayTarget(): string
    {
        return collect([
            'pending' => 3, 'confirmed' => 3, 'pickup_assigned' => 3, 'picked_up' => 2, 'at_hub' => 1,
            'out_for_delivery' => 4, 'delivered' => 5, 'failed' => 1,
        ])->flatMap(fn (int $weight, string $step) => array_fill(0, $weight, $step))->random();
    }

    /**
     * @return array<string, mixed>
     */
    private function orderData(Merchant $merchant, Carbon $day): array
    {
        [$commune, $address, $landmark] = $this->pick(self::ADDRESSES);
        $zone = $this->zones->get($commune) ?? $this->zones->random();
        $item = $this->pick(self::ITEMS);
        $quantity = mt_rand(1, 10) > 8 ? 2 : 1;
        // Une course sur dix est déjà payée par le destinataire : rien à encaisser
        $amount = mt_rand(1, 10) === 1 ? 0 : $item[1] * $quantity;

        return [
            'merchant_reference' => self::REFERENCE_PREFIX.$day->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3))),
            'recipient_name' => $this->pick(self::FIRST_NAMES).' '.$this->pick(self::LAST_NAMES),
            'recipient_phone' => '+225'.self::PHONE_PREFIX.sprintf('%06d', mt_rand(100000, 999999)),
            'delivery_zone_id' => $zone->id,
            'delivery_address' => $address,
            'delivery_landmark' => $landmark,
            'description' => ($quantity > 1 ? "{$quantity} × " : '').$item[0],
            'package_size' => $item[2],
            'is_fragile' => $item[3],
            'is_express' => mt_rand(1, 12) === 1,
            'items_amount' => $amount,
            'fee_payer' => $amount === 0 ? FeePayer::Merchant->value : $merchant->default_fee_payer->value,
            // Le client fictif n'est pas prévenu (les tâches planifiées de la préproduction non plus)
            'notify_merchant' => false,
        ];
    }

    /**
     * Distribution : plis, colis et chèques pour des entreprises et administrations (fictives), sans encaissement.
     * MAD : livraisons faites pour le client par son livreur dédié, sans encaissement non plus.
     *
     * @return array<string, mixed>
     */
    private function billedData(Activity $activity, Carbon $day): array
    {
        [$commune, $address, $landmark] = $this->pick(self::ADDRESSES);
        $zone = $this->zones->get($commune) ?? $this->zones->random();
        $common = [
            'activity' => $activity->value,
            'merchant_reference' => self::REFERENCE_PREFIX.$day->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3))),
            'recipient_phone' => '+225'.self::PHONE_PREFIX.sprintf('%06d', mt_rand(100000, 999999)),
            'delivery_zone_id' => $zone->id,
            'delivery_address' => $address,
            'delivery_landmark' => $landmark,
            'items_amount' => 0,
            'fee_payer' => FeePayer::Merchant->value,
            'notify_merchant' => false,
        ];

        if ($activity === Activity::Mad) {
            return [...$common,
                'recipient_name' => $this->pick(self::FIRST_NAMES).' '.$this->pick(self::LAST_NAMES),
                'description' => $this->pick(self::MAD_ITEMS),
                'shipment_type' => 'colis',
                'package_size' => 'S',
            ];
        }

        [$type, $label] = $this->pick(self::DISTRIBUTION_ITEMS);
        $quantity = $type === 'colis' ? 1 : $this->pick([1, 1, 1, 2, 3, 5]);

        return [...$common,
            'recipient_name' => $this->pick(self::OFFICES).' — '.$this->pick(self::ORGANIZATIONS),
            'description' => ($quantity > 1 ? "{$quantity} × " : '').$label,
            'shipment_type' => $type,
            'shipment_quantity' => $quantity,
            'weight_kg' => $type === 'colis' && mt_rand(1, 4) === 1 ? mt_rand(55, 90) / 10 : null,
            'package_size' => $type === 'colis' ? 'M' : 'S',
        ];
    }

    /**
     * @param  Collection<int, Courier>|null  $dedicated  livreurs du contrat MAD : eux seuls font ses courses
     */
    private function drive(Order $order, string $target, ?Collection $dedicated = null): void
    {
        $order = $order->fresh();
        $fleet = $dedicated !== null && $dedicated->isNotEmpty() ? $dedicated : $this->couriers;
        $pickupCourier = $fleet->random();
        $deliveryCourier = mt_rand(1, 3) === 1 ? $fleet->random() : $pickupCourier;

        if ($target === 'pending') {
            return;
        }
        if (in_array($target, ['cancelled', 'rejected'], true)) {
            if ($order->status === OrderStatus::Pending) {
                $to = $target === 'rejected' ? OrderStatus::Rejected : OrderStatus::Cancelled;
                $this->move($order, $to, ['cancel_reason' => $target === 'rejected' ? 'Zone ou colis non pris en charge' : 'Le destinataire a annulé sa commande']);
            } else {
                $this->move($order, OrderStatus::Cancelled, ['cancel_reason' => 'Le destinataire a annulé sa commande']);
            }

            return;
        }

        if ($order->status === OrderStatus::Pending) {
            $this->move($order, OrderStatus::Confirmed, [], 10);
        }
        if ($target === 'confirmed') {
            return;
        }

        if ($order->fresh()->status === OrderStatus::Confirmed) {
            $this->assign($order, AssignmentType::Pickup, $pickupCourier, 15);
        }
        if ($target === 'pickup_assigned') {
            return;
        }

        $this->move($order, OrderStatus::PickedUp, [], mt_rand(30, 90), $pickupCourier);
        if ($target === 'picked_up') {
            return;
        }

        // Une partie des colis passe par le dépôt avant la livraison (pas en MAD : le livreur est chez le client)
        if ($target === 'at_hub' || ($order->activity !== Activity::Mad && mt_rand(1, 4) === 1)) {
            $this->move($order, OrderStatus::AtHub, [], mt_rand(20, 60), $pickupCourier);
            if ($target === 'at_hub') {
                return;
            }
        }

        $this->assign($order, AssignmentType::Delivery, $deliveryCourier, 10);
        $this->move($order, OrderStatus::OutForDelivery, [], mt_rand(10, 40), $deliveryCourier);
        if ($target === 'out_for_delivery') {
            return;
        }

        if ($target !== 'delivered') {
            if ($target === 'rescheduled') {
                $this->move($order, OrderStatus::Rescheduled, [
                    'rescheduled_to' => today()->addDays(mt_rand(1, 3))->toDateString(),
                    'note' => 'Le destinataire demande une livraison un autre jour',
                ], mt_rand(30, 120), $deliveryCourier);

                return;
            }

            $this->failDelivery($order, $deliveryCourier);
            if ($target === 'failed') {
                return;
            }

            if ($target === 'returned') {
                $this->assign($order, AssignmentType::Return, $deliveryCourier, mt_rand(60, 180));
                $this->move($order, OrderStatus::Returning, [], 30, $deliveryCourier);
                $this->move($order, OrderStatus::Returned, [], mt_rand(30, 90), $deliveryCourier);

                return;
            }

            // Deuxième tentative, le lendemain matin
            $this->at(now()->addDay()->setTime(mt_rand(9, 11), mt_rand(0, 59)));
            $this->assign($order, AssignmentType::Delivery, $deliveryCourier, 5);
            $this->move($order, OrderStatus::OutForDelivery, [], 20, $deliveryCourier);
        }

        $this->deliver($order, $deliveryCourier);
    }

    private function failDelivery(Order $order, Courier $courier): void
    {
        $reason = $this->failReasons[$order->activity->value] ?? null;
        $context = $reason
            ? ['incident_reason_id' => $reason->id, 'note' => 'Destinataire absent ou injoignable']
            : ['incident_reason_id' => null];
        $this->move($order, OrderStatus::DeliveryFailed, $context, mt_rand(20, 90), $courier);
    }

    private function deliver(Order $order, Courier $courier): void
    {
        $order = $order->fresh();
        $cod = (int) $order->cod_amount;
        // Paiement mobile pour une partie des encaissements
        $method = $cod > 0 && mt_rand(1, 5) === 1 ? $this->pick([PaymentMethod::Wave, PaymentMethod::OrangeMoney]) : PaymentMethod::Cash;

        $this->move($order, OrderStatus::Delivered, [
            'delivery_code' => $order->delivery_code,
            'collected_amount' => $cod,
            'payment_method' => $method->value,
            'received_by_name' => $order->recipient_name,
        ], mt_rand(15, 60), $courier);
    }

    /**
     * Étape faite par le livreur quand c'est la sienne (journal réaliste), sinon par l'administrateur.
     *
     * @param  array<string, mixed>  $context
     */
    private function move(Order $order, OrderStatus $to, array $context = [], int $minutes = 0, ?Courier $courier = null): void
    {
        $this->later($minutes);
        $workflow = app(OrderWorkflow::class);

        if ($courier !== null) {
            try {
                $workflow->transition($courier->user, $order->fresh(), $to, $context);

                return;
            } catch (AuthorizationException|BusinessRuleException) {
                // Étape réservée à l'équipe (ou règle de jour de livraison) : faite par l'administrateur
            }
        }

        $workflow->transition($this->admin, $order->fresh(), $to, $context);
    }

    private function assign(Order $order, AssignmentType $type, Courier $courier, int $minutes = 0): void
    {
        $this->later($minutes);
        $order = $order->fresh();
        // Déjà confiée automatiquement (planning, livreur prévu chez ce client) : on garde
        if ($order->activeAssignment($type) !== null) {
            return;
        }
        app(OrderDispatcher::class)->assign($this->admin, $order, $type, $courier, 'planning');
    }

    /** Avance l'horloge de la génération sans jamais dépasser l'heure du lancement */
    private function later(int $minutes): void
    {
        $this->at(now()->addMinutes($minutes));
    }

    /** Place l'horloge de la génération, jamais après l'heure du lancement */
    private function at(Carbon $moment): void
    {
        Carbon::setTestNow($moment->copy()->min($this->clock)->copy());
    }

    /**
     * Fin de journée : chaque livreur verse ce qu'il a encaissé (parfois avec un petit manque),
     * et le vendredi les clients reçoivent leur reversement.
     */
    private function closeDay(Carbon $day): void
    {
        $this->at($day->copy()->setTime(19, mt_rand(0, 45)));
        $desk = app(CashDesk::class);

        foreach ($this->couriers as $courier) {
            // Seulement les encaissements de ce jour-là (une relance peut remplir des jours plus anciens)
            $collections = CashCollection::query()->where('courier_id', $courier->id)->inCourierHands()
                ->where('collected_at', '<=', now())->get(['id', 'amount_collected']);
            $held = (int) $collections->sum('amount_collected');
            if ($held <= 0) {
                continue;
            }
            $short = mt_rand(1, 15) === 1 ? min($held, 500 * mt_rand(1, 4)) : 0;

            try {
                $desk->remit($this->admin, $courier, $held - $short, $collections->modelKeys(), $short > 0 ? 'Manque constaté au versement' : null);
                $this->count('versements des livreurs');
            } catch (Throwable) {
                $this->count('versements en erreur');
            }
        }

        if (! $day->isFriday()) {
            return;
        }

        $payouts = app(MerchantPayouts::class);
        foreach ($this->merchants as $merchant) {
            try {
                $payout = $payouts->create($this->admin, $merchant, 'Reversement de la semaine', null, $day->toDateString());
                $payouts->markPaid($this->admin, $payout, PaymentMethod::Wave, 'DEMO'.strtoupper(bin2hex(random_bytes(4))));
                $this->count('reversements aux clients');
            } catch (Throwable) {
                // Rien à reverser cette semaine
            }
        }
    }

    /**
     * Factures du mois des clients de distribution et de MAD pour les mois terminés de l'historique (brouillons,
     * comme le fait billing:invoice le 1er du mois) : à relire, émettre et encaisser depuis Facturation.
     */
    private function invoices(int $days): void
    {
        Carbon::setTestNow($this->clock->copy());
        $invoicing = app(ClientInvoicing::class);
        $month = CarbonImmutable::parse($this->clock->copy()->subDays($days)->startOfMonth());
        $current = CarbonImmutable::parse($this->clock->copy()->startOfMonth());

        for (; $month->lessThan($current); $month = $month->addMonth()) {
            try {
                $count = $invoicing->generate($this->company, $month, [Activity::Distribution->value, Activity::Mad->value]);
                $this->stats['factures du mois (brouillons)'] = ($this->stats['factures du mois (brouillons)'] ?? 0) + $count;
            } catch (Throwable $e) {
                $this->errors['Factures : '.$e->getMessage()] = 1;
            }
        }
    }

    /**
     * Élément tiré au hasard (mt_rand, que --seed rend reproductible). Pas de fake() : Faker est une
     * dépendance de développement, absente des images Docker de production et de préproduction.
     *
     * @template T
     *
     * @param  list<T>  $list
     * @return T
     */
    private function pick(array $list): mixed
    {
        return $list[mt_rand(0, count($list) - 1)];
    }

    private function count(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }

    private function report(): void
    {
        ksort($this->stats);
        $this->table(['Élément', 'Nombre'], collect($this->stats)->map(fn ($n, $k) => [$k, $n])->values());

        if ($this->errors !== []) {
            arsort($this->errors);
            $this->warn('Erreurs les plus fréquentes :');
            $this->table(['Erreur', 'Nombre'], collect($this->errors)->take(8)->map(fn ($n, $m) => [mb_strimwidth($m, 0, 110, '…'), $n])->values());
        }
        $this->info('Comptes fictifs : téléphones en +225 07 99 …, mot de passe « '.$this->option('password').' ».');
        $this->line('  Dispatcher 07 99 00 00 01 · Caissier 07 99 00 00 02 · Entrepôt 07 99 00 00 03');
        $this->line('  Livreurs 07 99 00 01 01 à 07 99 00 01 08 · Livreurs MAD 07 99 00 01 09 et 10');
        $this->line('  Clients e-commerce 07 99 00 02 01, 02 et 04 · Distribution 07 99 00 02 11 · MAD 07 99 00 02 13');
    }

    // ───────────────────────────── Listes ─────────────────────────────

    private const FIRST_NAMES = [
        'Aïcha', 'Mamadou', 'Kouassi', 'Fatoumata', 'Yves', 'Mariam', 'Ange', 'Sékou', 'Grâce', 'Lassina', 'Estelle',
        'Bakary', 'Rokia', 'Hervé', 'Aya', 'Drissa', 'Prisca', 'Karim', 'Affoué', 'Wilfried', 'Nafissatou', 'Cédric',
    ];

    private const LAST_NAMES = [
        'Koné', 'Traoré', 'Kouassi', 'Ouattara', 'Yao', 'Bamba', 'Diallo', 'Kouadio', 'Coulibaly', 'N\'Dri', 'Soro',
        'Gbagbo', 'Konaté', 'Assi', 'Tanoh', 'Fofana', 'Dosso', 'Brou', 'Zadi', 'Méité',
    ];

    /** [commune, adresse, repère] */
    private const ADDRESSES = [
        ['Cocody', 'Riviera Palmeraie, rue Ministre', 'Près de la pharmacie Palmeraie'],
        ['Cocody', 'Angré, Star 12', 'Derrière la station Shell'],
        ['Cocody', 'II Plateaux Vallon, rue des Écoles', 'Face au supermarché Prosuma'],
        ['Cocody', 'Riviera 3, cité Synacass-CI', 'Portail bleu après le maquis'],
        ['Yopougon', 'Niangon Nord, cité verte', 'Près de l\'école primaire'],
        ['Yopougon', 'Maroc, rue Princesse', 'À côté du kiosque Orange Money'],
        ['Yopougon', 'Selmer, carrefour Siporex', 'Immeuble jaune, 2e étage'],
        ['Marcory', 'Zone 4, rue Paul Langevin', 'Face au restaurant Le Grand Large'],
        ['Marcory', 'Résidentiel, boulevard VGE', 'Près du centre commercial Cap Sud'],
        ['Koumassi', 'Grand Campement', 'Près du marché'],
        ['Koumassi', 'Sicogi, rue 6', 'Derrière la mosquée'],
        ['Treichville', 'Avenue 16, rue 12', 'Près du Palais des Sports'],
        ['Plateau', 'Boulevard de la République', 'Tour Postel 2001, accueil'],
        ['Plateau', 'Rue Lecœur', 'Immeuble Les Harmonies, 3e étage'],
        ['Adjamé', 'Williamsville, rue des Jardins', 'Près de la gare de Bingerville'],
        ['Abobo', 'Abobo Baoulé', 'Carrefour Samaké, après la boutique'],
        ['Abobo', 'PK 18, cité Agripac', 'Maison au portail vert'],
        ['Port-Bouët', 'Gonzagueville, rue de la Plage', 'Près de l\'hôtel Coucoué'],
        ['Port-Bouët', 'Vridi Cité', 'Face au terminal à conteneurs'],
        ['Attécoubé', 'Santé 3', 'Près du centre de santé'],
        ['Bingerville', 'Cité Feh Kessé', 'Après le lycée'],
        ['Songon', 'Songon Agban', 'Près du carrefour principal'],
        ['Anyama', 'Anyama Zossonkoi', 'Près de la grande mosquée'],
    ];

    /** Distribution : [type d'envoi, contenu] */
    private const DISTRIBUTION_ITEMS = [
        ['pli', 'Courrier recommandé'], ['pli', 'Contrat à signer'], ['pli', 'Facture et relevé de compte'],
        ['pli', 'Convocation'], ['pli', 'Attestation d\'assurance'], ['pli', 'Dossier de sinistre'],
        ['colis', 'Cartons d\'archives'], ['colis', 'Fournitures de bureau'], ['colis', 'Brochures commerciales'],
        ['cheque', 'Chèque de règlement'],
    ];

    /** Distribution : services destinataires */
    private const OFFICES = [
        'Direction générale', 'Service comptabilité', 'Ressources humaines', 'Service juridique', 'Secrétariat',
        'Service courrier', 'Direction commerciale', 'Service des achats',
    ];

    /** Distribution : entreprises et établissements (fictifs) */
    private const ORGANIZATIONS = [
        'Groupe Akwaba Services', 'Imprimerie du Lagon', 'Clinique Sainte-Grâce', 'Transports Bénié', 'Agence Immobilière Horizon',
        'Cabinet d\'expertise Yao', 'Lycée privé La Réussite', 'Société Agro-Savane', 'Bureau d\'études Ivoire Conseil',
        'Hôtel Les Cocotiers', 'Mutuelle des Enseignants du Lagon', 'Laboratoire Bio-Santé Abidjan',
    ];

    /** MAD : livraisons faites pour le client */
    private const MAD_ITEMS = [
        'Médicaments (commande d\'officine)', 'Carton de produits pharmaceutiques', 'Commande de repas',
        'Plateau traiteur', 'Boissons et desserts', 'Réassort de pharmacie',
    ];

    /** [article, prix unitaire, taille, fragile] */
    private const ITEMS = [
        ['Robe wax taille M', 15000, 'S', false], ['Sac à main en cuir', 25000, 'M', false],
        ['Paire de sandales', 12000, 'S', false], ['Téléphone Android', 85000, 'S', true],
        ['Écouteurs sans fil', 18000, 'S', true], ['Mixeur électrique', 32000, 'M', true],
        ['Lot de crèmes hydratantes', 9500, 'S', true], ['Pagne Vlisco 6 yards', 28000, 'S', false],
        ['Livres scolaires (lot)', 22000, 'M', false], ['Panier de fruits bio', 7500, 'M', true],
        ['Médicaments sur ordonnance', 6000, 'S', false], ['Poussette bébé', 65000, 'L', false],
        ['Chaussures de sport', 30000, 'M', false], ['Montre connectée', 40000, 'S', true],
        ['Parfum 100 ml', 35000, 'S', true], ['Attiéké et poisson (commande traiteur)', 5000, 'S', false],
    ];
}
