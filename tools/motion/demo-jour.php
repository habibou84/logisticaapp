<?php

/**
 * Une journée en cours chez « Livraison Express CI », pour filmer les écrans de l'entreprise de livraison :
 * planning des livreurs et assignation automatique en marche, courses du jour à toutes les étapes,
 * alertes (sans livreur, échecs à décider, colis gardé depuis hier), livreurs sur la carte, argent à verser.
 *
 * Base locale uniquement, après tools/videos/demo-data.php :
 *   php tools/motion/demo-jour.php
 *
 * Les courses passent par les vrais services : l'assignation automatique, les alertes et la caisse
 * réagissent comme en vrai. Aucun vrai message ne part (WhatsApp et SMS en journal).
 */

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierLocation;
use App\Models\CourierRule;
use App\Models\IncidentReason;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Models\Zone;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

foreach (['QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'WHATSAPP_DRIVER' => 'log', 'SMS_DRIVER' => 'log'] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment('production')) {
    exit("Journée de démonstration : base locale uniquement.\n");
}

Model::preventLazyLoading(false);
mt_srand(2110);

$company = Company::where('slug', 'livraison-express-ci')->firstOrFail();
$admin = User::where('phone', '+2250700000001')->firstOrFail();
$tz = $company->timezone ?: 'Africa/Abidjan';
$zones = Zone::forCompany($company->id)->whereNull('parent_id')->where('is_shipping', false)->get()->keyBy('name');
$couriers = Courier::withoutGlobalScopes()->where('company_id', $company->id)->with('user')->get()->keyBy(fn ($c) => explode(' ', $c->user->name)[0]);
$merchants = Merchant::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->get()->values();

// --- Planning : qui ramasse et qui livre où, avec un remplaçant ; automatismes en marche --------------
$company->forceFill(['auto_pickup' => Company::AUTO_ON, 'auto_delivery' => true, 'automation_paused_at' => null, 'daily_cutoff_time' => '14:00'])->save();
// Tous les livreurs en service ce matin
$couriers->each(fn (Courier $c) => $c->forceFill(['is_available' => true])->saveQuietly());
CourierRule::withoutGlobalScopes()->where('company_id', $company->id)->delete();
$ids = fn (array $names) => collect($names)->map(fn ($n) => $zones[$n]->id)->all();
foreach ([
    [CourierRule::PICKUP, 'Moussa', 'Serge', ['Plateau', 'Cocody']],
    [CourierRule::PICKUP, 'Serge', 'Moussa', ['Marcory', 'Treichville']],
    [CourierRule::DELIVERY, 'Yacouba', 'Koffi', ['Cocody', 'Bingerville', 'Plateau']],
    [CourierRule::DELIVERY, 'Koffi', 'Yacouba', ['Yopougon', 'Abobo', 'Adjamé']],
    [CourierRule::DELIVERY, 'Awa', 'Serge', ['Marcory', 'Koumassi', 'Port-Bouët', 'Treichville']],
] as [$type, $courier, $backup, $zoneNames]) {
    CourierRule::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'type' => $type, 'courier_id' => $couriers[$courier]->id, 'backup_courier_id' => $couriers[$backup]->id,
        'zone_ids' => $ids($zoneNames), 'merchant_ids' => [], 'days' => [], 'is_active' => true, 'created_by' => $admin->id,
    ]);
}

$orders = app(OrderService::class);
$dispatcher = app(OrderDispatcher::class);
$workflow = app(OrderWorkflow::class);
$names = ['Awa Koné', 'Kouadio N\'Guessan', 'Mariam Touré', 'Adjoua Kouamé', 'Yao Konan', 'Salimata Cissé', 'Christelle Aka', 'Drissa Sanogo',
    'Affoué Brou', 'Ange Kacou', 'Nadège Gnagne', 'Ismaël Fofana', 'Rokia Sylla', 'Patrice Zadi', 'Estelle Ahoua', 'Lamine Keïta'];
$items = [['2 robes', 15000], ['Chaussures', 22000], ['Pagne wax', 12000], ['Écouteurs', 9000], ['Crème visage', 7500], ['Parfum', 25000], ['Sac à main', 18000], ['Montre', 30000]];
$destinations = ['Cocody', 'Yopougon', 'Marcory', 'Plateau', 'Koumassi', 'Abobo', 'Treichville', 'Adjamé', 'Port-Bouët', 'Bingerville'];
$channels = ['dashboard', 'dashboard', 'whatsapp', 'ecommerce_app', 'ecommerce_app', 'import'];
$today = Carbon::now($tz)->startOfDay();

$new = function (Merchant $merchant, Carbon $at, ?string $destination = null) use ($orders, $workflow, $admin, $names, $items, $destinations, $zones, $channels) {
    Carbon::setTestNow($at);
    [$desc, $amount] = $items[array_rand($items)];
    $destination ??= $destinations[array_rand($destinations)];
    $order = $orders->create($admin, $merchant, [
        'recipient_name' => $names[array_rand($names)],
        'recipient_phone' => '07'.mt_rand(10000000, 99999999),
        'delivery_zone_id' => $zones[$destination]->id,
        'delivery_address' => $destination.', près du marché',
        'description' => $desc,
        'items_amount' => $amount,
        'fee_payer' => 'recipient',
    ], $channels[array_rand($channels)]);
    Carbon::setTestNow($at->copy()->addMinutes(3));
    // Validation : le planning confie aussitôt le ramassage
    $workflow->transition($admin, $order, OrderStatus::Confirmed);

    return $order->fresh();
};
$courierOf = fn (Order $order, AssignmentType $type) => $order->activeAssignment($type)?->courier ?? null;

// Colis récupéré hier par Koffi, encore chez lui : « gardé depuis plus de 24 h »
$kept = $new($merchants[2], $today->copy()->subDay()->setTime(9, 10), 'Yopougon');
Carbon::setTestNow($today->copy()->subDay()->setTime(10, 0));
$pickupCourier = $courierOf($kept, AssignmentType::Pickup) ?? $couriers['Serge'];
$workflow->transition($pickupCourier->user, $kept->fresh(), OrderStatus::PickedUp);
$kept = $kept->fresh();
if (! $kept->activeAssignment(AssignmentType::Delivery)) {
    $dispatcher->assign($admin, $kept, AssignmentType::Delivery, $couriers['Koffi']);
}
Carbon::setTestNow($today->copy()->subDay()->setTime(11, 0));
$workflow->transition($couriers['Koffi']->user, $kept->fresh(), OrderStatus::OutForDelivery);
Carbon::setTestNow($today->copy()->subDay()->setTime(12, 0));
$workflow->transition($couriers['Koffi']->user, $kept->fresh(), OrderStatus::DeliveryFailed, [
    'incident_reason_id' => IncidentReason::availableTo($company->id)->where('label', 'Destinataire absent')->value('id'),
]);

// Courses du jour : livrées, en route, ramassées, à ramasser (confiées par le planning), en échec
$plan = array_merge(array_fill(0, 14, 'delivered'), array_fill(0, 5, 'out'), array_fill(0, 3, 'picked'), array_fill(0, 6, 'pickup'), ['failed', 'failed']);
foreach ($plan as $i => $state) {
    $merchant = $merchants[$i % 4 === 0 ? 0 : 1 + ($i % ($merchants->count() - 1))];
    $at = $today->copy()->setTime(7, 30)->addMinutes($i * 9);
    $order = $new($merchant, $at);
    if ($state === 'pickup') {
        continue;
    }
    $pickup = $courierOf($order, AssignmentType::Pickup) ?? $couriers['Moussa'];
    Carbon::setTestNow($at->copy()->addMinutes(45));
    $workflow->transition($pickup->user, $order->fresh(), OrderStatus::PickedUp);
    if ($state === 'picked') {
        continue;
    }
    $order = $order->fresh();
    $delivery = $courierOf($order, AssignmentType::Delivery);
    if (! $delivery) {
        $delivery = $couriers['Awa'];
        $dispatcher->assign($admin, $order, AssignmentType::Delivery, $delivery);
    }
    Carbon::setTestNow($at->copy()->addMinutes(70));
    $workflow->transition($delivery->user, $order->fresh(), OrderStatus::OutForDelivery);
    if ($state === 'out') {
        continue;
    }
    Carbon::setTestNow($at->copy()->addMinutes(100 + mt_rand(0, 40)));
    $order = $order->fresh();
    if ($state === 'delivered') {
        $workflow->transition($delivery->user, $order, OrderStatus::Delivered, [
            'collected_amount' => $order->cod_amount,
            'payment_method' => ['cash', 'cash', 'wave', 'orange_money'][mt_rand(0, 3)],
        ]);
    } else {
        $workflow->transition($delivery->user, $order, OrderStatus::DeliveryFailed, [
            'incident_reason_id' => IncidentReason::availableTo($company->id)->whereIn('label', ['Destinataire injoignable', 'Le client n\'a pas l\'argent'])->inRandomOrder()->value('id'),
        ]);
    }
}

// Deux courses d'une zone que personne ne ramasse au planning : « sans livreur »
$abobo = Merchant::withoutGlobalScopes()->firstOrCreate(
    ['company_id' => $company->id, 'phone' => '+2250500000009'],
    ['business_name' => 'Marché Abobo Shop', 'contact_name' => 'Kadi Bamba', 'whatsapp_phone' => '0500000009', 'pickup_zone_id' => $zones['Abobo']->id, 'pickup_address' => 'Gare d\'Abobo', 'status' => 'active'],
);
$new($abobo, Carbon::now($tz)->subMinutes(75));
$new($abobo, Carbon::now($tz)->subMinutes(50));

Carbon::setTestNow();

// Livreurs sur la carte (positions des dernières minutes)
foreach ([
    'Moussa' => [5.3600, -3.9900], 'Serge' => [5.2990, -3.9850], 'Yacouba' => [5.3720, -3.9560],
    'Koffi' => [5.3450, -4.0750], 'Awa' => [5.2880, -3.9520],
] as $courier => [$lat, $lng]) {
    CourierLocation::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'courier_id' => $couriers[$courier]->id,
        'lat' => $lat, 'lng' => $lng, 'accuracy' => 15, 'recorded_at' => now()->subMinutes(mt_rand(1, 6)),
    ]);
    $couriers[$courier]->forceFill(['current_lat' => $lat, 'current_lng' => $lng, 'last_location_at' => now()->subMinutes(mt_rand(1, 6)), 'last_seen_at' => now()->subMinutes(mt_rand(1, 4))])->saveQuietly();
}

// Les veilleurs passent à 13 h 15, à l'approche de l'heure limite : courses sans livreur, ramassages pas
// commencés, colis gardés, livreurs disponibles
Carbon::setTestNow($today->copy()->setTime(13, 15));
foreach (['orders:unassigned', 'assignments:watch', 'parcels:overdue', 'couriers:available', 'parcels:kept-deliveries'] as $command) {
    Artisan::call($command);
}
Carbon::setTestNow();

echo Order::withoutGlobalScopes()->where('company_id', $company->id)->where('created_at', '>=', $today)->count()." courses aujourd'hui.\n";
