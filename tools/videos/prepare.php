<?php

/**
 * Prépare la base locale juste avant un enregistrement (après demo-data.php).
 *
 *   php tools/videos/prepare.php courier   missions du jour de Koffi (0700000004)
 *   php tools/videos/prepare.php az        Koffi sans mission ni argent en main (vidéo « de A à Z »)
 *   php tools/videos/prepare.php order     une course en attente de validation
 */

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use App\Models\Zone;
use App\Services\Finance\CashDesk;
use App\Services\Finance\CourierCash;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;

// Base locale : files d'attente synchrones, ni diffusion temps réel ni vrais messages WhatsApp
foreach (['QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'WHATSAPP_DRIVER' => 'log', 'SMS_DRIVER' => 'log'] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment('production')) {
    exit("Données de démonstration : base locale uniquement.\n");
}
Model::preventLazyLoading(false);

$company = Company::where('slug', 'livraison-express-ci')->firstOrFail();
$admin = User::where('phone', '+2250700000001')->firstOrFail();
$zones = Zone::forCompany($company->id)->whereNull('parent_id')->get()->keyBy('name');
$koffi = Courier::withoutGlobalScopes()->whereHas('user', fn ($q) => $q->where('phone', '+2250700000004'))->with('user')->firstOrFail();
$boutique = Merchant::withoutGlobalScopes()->where('company_id', $company->id)->where('phone', '+2250500000001')->firstOrFail();

$orders = app(OrderService::class);
$dispatcher = app(OrderDispatcher::class);
$workflow = app(OrderWorkflow::class);

$create = fn (Merchant $merchant, string $name, string $zone, string $address, string $desc, int $amount) => $orders->create($admin, $merchant, [
    'recipient_name' => $name,
    'recipient_phone' => '07'.random_int(10000000, 99999999),
    'delivery_zone_id' => $zones[$zone]->id,
    'delivery_address' => $address,
    'description' => $desc,
    'items_amount' => $amount,
    'fee_payer' => 'recipient',
]);

// Koffi sans mission en cours, sans colis ni argent en main (journée filmée de zéro)
$freshKoffi = function () use ($koffi, $admin) {
    // Missions précédentes de Koffi terminées hors caméra : sa liste ne montre que la journée filmée
    Order::withoutGlobalScopes()->whereIn('status', [OrderStatus::PickupAssigned, OrderStatus::DeliveryAssigned, OrderStatus::OutForDelivery, OrderStatus::PickupInProgress])
        ->whereHas('assignments', fn ($q) => $q->where('courier_id', $koffi->id)->whereIn('status', ['assigned', 'accepted', 'in_progress']))
        ->get()->each(fn (Order $o) => $o->forceFill(['status' => OrderStatus::Cancelled])->save());
    OrderAssignment::withoutGlobalScopes()->where('courier_id', $koffi->id)->whereIn('status', ['assigned', 'accepted', 'in_progress'])
        ->update(['status' => 'cancelled', 'completed_at' => now()]);
    // Anciens colis en échec de Koffi rendus au dépôt
    Order::withoutGlobalScopes()->where('held_by_courier_id', $koffi->id)
        ->update(['status' => OrderStatus::Returned->value, 'held_by_courier_id' => null, 'held_since' => null]);
    // Caisse de Koffi remise à zéro : le montant encaissé apparaît pendant la vidéo
    $due = app(CourierCash::class)->balance($koffi)['due'];
    if ($due > 0) {
        app(CashDesk::class)->remit($admin, $koffi, $due);
    }
    $koffi->user->unreadNotifications()->update(['read_at' => now()]);
};

switch ($argv[1] ?? '') {
    case 'courier':
        $freshKoffi();
        foreach ([
            ['Awa Koné', 'Yopougon', 'Siporex, près du marché', '2 robes', 15000],
            ['Yao Konan', 'Marcory', 'Zone 4, rue du Canal', 'Chaussures', 22000],
            ['Christelle Aka', 'Cocody', 'Angré, face à la pharmacie', 'Parfum', 25000],
        ] as $i => [$name, $zone, $address, $desc, $amount]) {
            $order = $create($boutique, $name, $zone, $address, $desc, $amount);
            $workflow->transition($admin, $order, OrderStatus::Confirmed);
            $dispatcher->assign($admin, $order->fresh(), AssignmentType::Pickup, $koffi);
            $dispatcher->assign($admin, $order->fresh(), AssignmentType::Delivery, $koffi);
        }
        $koffi->user->unreadNotifications()->update(['read_at' => now()]);
        echo "Missions de Koffi prêtes.\n";
        break;

    case 'az':
        $freshKoffi();
        echo "Koffi prêt pour la vidéo de A à Z.\n";
        break;

    case 'order':
        $order = $create($boutique, 'Mariam Touré', 'Marcory', 'Zone 4, rue du Canal, immeuble bleu', 'Sac à main', 18000);
        echo $order->id.' '.$order->tracking_code."\n";
        break;

    default:
        exit("Usage : php tools/videos/prepare.php courier|az|order\n");
}
