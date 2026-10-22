<?php

use App\Models\CourierLocation;
use App\Models\IdempotencyKey;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('reports:send')->everyFiveMinutes()->withoutOverlapping();
// Point de la semaine des administrateurs : vérifié toutes les 15 minutes, envoyé le lundi à 7 h 30 (heure de l'entreprise)
Schedule::command('reports:weekly')->everyFifteenMinutes()->withoutOverlapping();
// Domaines personnalisés en attente : activés dès qu'ils répondent en HTTPS
Schedule::command('domains:check')->everyTenMinutes()->withoutOverlapping();
// Fiches de paie des livreurs à la fin de chaque période (prêtes à payer le matin)
Schedule::command('payroll:close')->dailyAt('00:20')->withoutOverlapping();
// Position des marchands d'après les ramassages des livreurs (rattrapage)
Schedule::command('merchants:locate')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('storage:bill')->monthlyOn(1, '01:10')->withoutOverlapping();
// Abonnements des entreprises : factures du mois écoulé, puis rappels d'impayés chaque matin
Schedule::command('billing:invoice')->monthlyOn(1, '01:30')->withoutOverlapping();
Schedule::command('billing:remind')->dailyAt('08:05')->withoutOverlapping();
// Accès WhatsApp du bouton « Connecter WhatsApp » (60 jours) : relais par la plateforme ou alerte avant l'échéance
Schedule::command('whatsapp:tokens')->dailyAt('08:15')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class, WebhookDelivery::class, CourierLocation::class]])->daily();
// Cloche : anciennes notifications supprimées chaque nuit
Schedule::command('notifications:prune')->dailyAt('03:40')->withoutOverlapping();
Schedule::command('field-reports:remind')->everyMinute()->withoutOverlapping();
Schedule::command('parcels:overdue')->everyFifteenMinutes()->withoutOverlapping();
// Courses sans livreur de ramassage ou de livraison au-delà du délai de l'entreprise
Schedule::command('orders:unassigned')->everyMinute()->withoutOverlapping();
// Livreurs libres ou bientôt libres avec des ramassages près d'eux : mission ramassage possible
Schedule::command('couriers:available')->everyMinute()->withoutOverlapping();
// Colis gardés par le livreur après une mission ramassage : leur livraison lui est confiée le jour prévu
// Planning des livreurs : rattrapage de l'assignation automatique, message du matin
Schedule::command('assignments:auto')->everyFiveMinutes()->withoutOverlapping();
// Livreur injoignable, ramassages pas commencés avant l'heure limite
Schedule::command('assignments:watch')->everyMinute()->withoutOverlapping();
Schedule::command('couriers:planning-digest')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('parcels:kept-deliveries')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('orders:due-today')->dailyAt('06:50')->withoutOverlapping();
