<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Cloche : les anciennes notifications sont supprimées chaque nuit.
 */
class PruneNotificationsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    public function test_old_notifications_are_pruned(): void
    {
        $this->buildWorld();
        $add = function (int $days, bool $read): string {
            DB::table('notifications')->insert([
                'id' => $id = (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => $this->admin->getMorphClass(),
                'notifiable_id' => $this->admin->id, 'data' => '{}', 'read_at' => $read ? now() : null,
                'created_at' => now()->subDays($days), 'updated_at' => now(),
            ]);

            return $id;
        };

        $kept = [$add(5, true), $add(40, false)];
        $add(40, true);
        $add(100, false);

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing($kept, DB::table('notifications')->pluck('id')->all());
    }
}
