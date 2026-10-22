<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OutboundMessage;
use App\Notifications\WeeklyReportReady;
use App\Services\Reports\WeeklyManagerReport;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Point de la semaine des administrateurs : envoyé le lundi à 7 h 30, une seule fois,
 * avec la comparaison à la semaine d'avant.
 */
class WeeklyManagerReportTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        // Mercredi 7 octobre 2026 : activité de la semaine du 5 au 11 octobre
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'UTC'));
        $this->buildWorld();
    }

    public function test_report_is_sent_on_monday_morning_once(): void
    {
        $delivered = $this->createOrder();
        $delivered->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'collected_amount' => 10000])->save();
        $this->createOrder();

        $reports = app(WeeklyManagerReport::class);

        // Lundi 12 octobre, 7 h : trop tôt
        $this->assertSame(0, $reports->sendDue(CarbonImmutable::parse('2026-10-12 07:00', 'UTC')));
        $this->assertSame(1, $reports->sendDue(CarbonImmutable::parse('2026-10-12 07:31', 'UTC')));
        // Déjà envoyé cette semaine
        $this->assertSame(0, $reports->sendDue(CarbonImmutable::parse('2026-10-13 09:00', 'UTC')));

        $message = OutboundMessage::where('template_name', 'point_semaine_gerant')->sole();
        $this->assertSame($this->admin->phone, $message->to);
        $fee = Order::first()->delivery_fee + Order::first()->surcharges_total;
        $this->assertStringContainsString('du 05/10 au 11/10', $message->body);
        $this->assertStringContainsString('2 courses créées (nouveau), 1 livrées, taux de livraison 100 %', $message->body);
        $this->assertStringContainsString('Chiffre d\'affaires : '.Money::format($fee), $message->body);
        $this->assertStringContainsString('/admin/statistiques?periode=lastWeek', $message->body);
        // Seulement les administrateurs, pas le dispatcheur
        $this->assertSame(0, OutboundMessage::where('to', $this->dispatcher->phone)->count());

        Notification::assertSentTo($this->admin, WeeklyReportReady::class, fn (WeeklyReportReady $n) => $n->kpis['created'] === 2);
        Notification::assertNotSentTo($this->dispatcher, WeeklyReportReady::class);
    }

    public function test_nothing_is_sent_without_activity_or_when_disabled(): void
    {
        $reports = app(WeeklyManagerReport::class);
        $this->assertSame(0, $reports->sendDue(CarbonImmutable::parse('2026-10-12 08:00', 'UTC')));

        $this->createOrder();
        $this->company->forceFill(['weekly_report' => false, 'weekly_report_sent_at' => null])->save();
        $this->assertSame(0, $reports->sendDue(CarbonImmutable::parse('2026-10-19 08:00', 'UTC')));
        $this->assertSame(0, OutboundMessage::count());
    }

    public function test_trend_wording(): void
    {
        $this->assertSame('+20 % vs semaine précédente', WeeklyManagerReport::trend(120, 100));
        $this->assertSame('-50 % vs semaine précédente', WeeklyManagerReport::trend(50, 100));
        $this->assertSame('stable', WeeklyManagerReport::trend(100, 100));
        $this->assertSame('nouveau', WeeklyManagerReport::trend(5, 0));
    }
}
