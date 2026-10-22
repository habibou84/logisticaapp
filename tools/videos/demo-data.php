<?php

/**
 * Données de démonstration pour les vidéos : un mois d'activité de « Livraison Express CI »
 * (e-commerçants, livreurs, courses livrées, échecs, versements à la caisse).
 *
 * Base locale uniquement (après `php artisan db:seed --class=DemoSeeder`) :
 *   QUEUE_CONNECTION=sync WHATSAPP_DRIVER=log php tools/videos/demo-data.php
 *
 * Les courses passent par les vrais services (création, assignation, statuts, caisse) avec
 * une horloge simulée, pour que statistiques et caisse soient cohérentes.
 */

use App\Enums\AssignmentType;
use App\Enums\MerchantStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Courier;
use App\Models\IncidentReason;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Zone;
use App\Services\Finance\CashDesk;
use App\Services\Finance\CourierCash;
use App\Services\Finance\MerchantPayouts;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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

// Script ponctuel : chargements paresseux autorisés
Model::preventLazyLoading(false);

$company = Company::where('slug', 'livraison-express-ci')->firstOrFail();

if (Merchant::withoutGlobalScopes()->where('company_id', $company->id)->where('phone', '+2250500000002')->exists()) {
    exit("Données de démonstration déjà présentes.\n");
}

$admin = User::where('phone', '+2250700000001')->firstOrFail();
$zones = Zone::forCompany($company->id)->whereNull('parent_id')->where('is_shipping', false)->get()->keyBy('name');
mt_srand(2026);

// --- E-commerçants -----------------------------------------------------------
$merchants = [Merchant::withoutGlobalScopes()->where('company_id', $company->id)->where('phone', '+2250500000001')->firstOrFail()];
foreach ([
    ['Mode Akwaba', 'Fatou Bamba', '0500000002', 'Plateau', 'Avenue Chardy'],
    ['Saveurs d\'Ivoire', 'Jean-Marc Yao', '0500000003', 'Marcory', 'Zone 4, rue du Canal'],
    ['Tech Store CI', 'Ibrahim Traoré', '0500000004', 'Treichville', 'Avenue 16, rue 21'],
    ['Beauté Divine', 'Aïcha Diallo', '0500000005', 'Cocody', 'Angré 8e tranche'],
] as [$business, $contact, $phone, $zone, $address]) {
    $merchant = Merchant::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'business_name' => $business, 'contact_name' => $contact,
        'phone' => '+225'.$phone, 'whatsapp_phone' => $phone,
        'pickup_zone_id' => $zones[$zone]->id, 'pickup_address' => $address, 'status' => MerchantStatus::Active,
    ]);
    $user = User::create(['company_id' => $company->id, 'merchant_id' => $merchant->id, 'name' => $contact, 'phone' => $phone, 'password' => 'password']);
    $user->syncRoles([Role::MerchantOwner->value]);
    $merchants[] = $merchant;
}

// --- Livreurs ----------------------------------------------------------------
foreach ([['Moussa Coulibaly', '0700000007'], ['Serge Kouassi', '0700000008'], ['Yacouba Ouattara', '0700000009']] as [$name, $phone]) {
    $user = User::create(['company_id' => $company->id, 'name' => $name, 'phone' => $phone, 'password' => 'password']);
    $user->syncRoles([Role::Courier->value]);
    $user->syncCourierProfile();
}
$couriers = Courier::withoutGlobalScopes()->where('company_id', $company->id)->with('user')->get()
    ->reject(fn ($c) => $c->user->phone === '+2250700000004')->values(); // Koffi garde ses missions pour la vidéo du livreur

// --- Un mois de courses ------------------------------------------------------
$names = ['Awa Koné', 'Kouadio N\'Guessan', 'Mariam Touré', 'Adjoua Kouamé', 'Yao Konan', 'Salimata Cissé', 'Christelle Aka', 'Drissa Sanogo',
    'Affoué Brou', 'Ange Kacou', 'Nadège Gnagne', 'Ismaël Fofana', 'Rokia Sylla', 'Patrice Zadi', 'Estelle Ahoua', 'Lamine Keïta'];
$items = [['2 robes', 15000], ['Chaussures', 22000], ['Pagne wax', 12000], ['Téléphone', 85000], ['Écouteurs', 9000], ['Crème visage', 7500],
    ['Parfum', 25000], ['Plat attiéké poisson', 4500], ['Sac à main', 18000], ['Montre', 30000], ['Perruque', 35000], ['Chemise', 8000]];
$destinations = ['Cocody', 'Yopougon', 'Marcory', 'Plateau', 'Koumassi', 'Abobo', 'Treichville', 'Adjamé', 'Port-Bouët', 'Bingerville'];
$streets = ['près du marché', 'face à la pharmacie', 'carrefour de la station', 'derrière l\'église', 'immeuble bleu, 2e étage', 'à côté de l\'école'];

$orders = app(OrderService::class);
$dispatcher = app(OrderDispatcher::class);
$workflow = app(OrderWorkflow::class);
$tz = $company->timezone ?: 'Africa/Abidjan';
$count = 0;
$today = Carbon::now($tz)->startOfDay();

for ($day = 30; $day >= 0; $day--) {
    $date = $today->copy()->subDays($day);
    // Reversement aux e-commerçants tous les trois jours (sauf les derniers : reversement à faire en vidéo)
    if ($day % 3 === 0 && $day > 2 && $day < 30) {
        Carbon::setTestNow($date->copy()->setTime(16, 0));
        foreach ($merchants as $merchant) {
            if (app(MerchantPayouts::class)->eligibleEntries($merchant)->exists()) {
                $payout = app(MerchantPayouts::class)->create($admin, $merchant);
                app(MerchantPayouts::class)->markPaid($admin, $payout, [PaymentMethod::Wave, PaymentMethod::OrangeMoney, PaymentMethod::Cash][mt_rand(0, 2)]);
            }
        }
    }

    // Activité croissante sur le mois, plus calme le dimanche
    $perDay = (int) round((6 + (30 - $day) * 0.35 + mt_rand(0, 4)) * ($date->isSunday() ? 0.4 : 1));

    for ($i = 0; $i < $perDay; $i++) {
        $at = $date->copy()->setTime(8, 0)->addMinutes(mt_rand(0, 300));
        Carbon::setTestNow($at);
        [$desc, $amount] = $items[array_rand($items)];
        $merchant = $merchants[mt_rand(0, 9) < 4 ? 0 : mt_rand(1, count($merchants) - 1)];
        $courier = $couriers[array_rand($couriers->all())];
        $destination = $destinations[array_rand($destinations)];

        $order = $orders->create($admin, $merchant, [
            'recipient_name' => $names[array_rand($names)],
            'recipient_phone' => '07'.mt_rand(10000000, 99999999),
            'delivery_zone_id' => $zones[$destination]->id,
            'delivery_address' => $destination.', '.$streets[array_rand($streets)],
            'description' => $desc,
            'items_amount' => $amount,
            'fee_payer' => mt_rand(0, 2) ? 'recipient' : 'merchant',
        ], 'dashboard');

        Carbon::setTestNow($at->addMinutes(mt_rand(5, 25)));
        $workflow->transition($admin, $order, OrderStatus::Confirmed);
        $dispatcher->assign($admin, $order->fresh(), AssignmentType::Pickup, $courier);
        Carbon::setTestNow($at->addMinutes(mt_rand(30, 80)));
        $workflow->transition($courier->user, $order->fresh(), OrderStatus::PickedUp);
        $dispatcher->assign($admin, $order->fresh(), AssignmentType::Delivery, $courier);
        Carbon::setTestNow($at->addMinutes(mt_rand(10, 30)));
        $workflow->transition($courier->user, $order->fresh(), OrderStatus::OutForDelivery);
        Carbon::setTestNow($at->addMinutes(mt_rand(20, 70)));

        $order = $order->fresh();
        if (mt_rand(1, 100) <= 93) {
            $workflow->transition($courier->user, $order, OrderStatus::Delivered, [
                'collected_amount' => $order->cod_amount,
                'payment_method' => ['cash', 'cash', 'cash', 'wave', 'orange_money'][mt_rand(0, 4)],
            ]);
        } else {
            $workflow->transition($courier->user, $order, OrderStatus::DeliveryFailed, [
                'incident_reason_id' => IncidentReason::availableTo($company->id)->whereIn('label', ['Destinataire injoignable', 'Destinataire absent', 'Le client n\'a pas l\'argent'])->inRandomOrder()->value('id'),
            ]);
            // Rapporté au dépôt le soir puis rendu au marchand
            Carbon::setTestNow($date->copy()->setTime(18, 30));
            $workflow->transition($admin, $order->fresh(), OrderStatus::AtHub);
            Carbon::setTestNow($date->copy()->addDay()->setTime(10, 0));
            $dispatcher->assign($admin, $order->fresh(), AssignmentType::Return, $courier);
            $workflow->transition($courier->user, $order->fresh(), OrderStatus::Returned);
        }
        $count++;
    }

    // Le soir, chaque livreur verse ce qu'il a encaissé (sauf hier et aujourd'hui : point à faire en vidéo)
    if ($day > 1) {
        Carbon::setTestNow($date->copy()->setTime(19, 0));
        foreach ($couriers as $courier) {
            $due = app(CourierCash::class)->balance($courier->fresh());
            $amount = $due['due'];
            if ($amount > 0) {
                app(CashDesk::class)->remit($admin, $courier->fresh(), $amount);
            }
        }
    }
}

Carbon::setTestNow();
echo "{$count} courses de démonstration créées.\n";
