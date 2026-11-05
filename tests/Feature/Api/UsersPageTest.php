<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserAccountEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Page Utilisateurs (reprise de Jibiat, adaptée) : onglets et compteurs, déconnexion de tous les appareils,
 * historique du compte (dont « Voir comme ce compte »), lien de connexion par WhatsApp.
 */
class UsersPageTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function ids(string $query): array
    {
        return collect($this->getJson('/api/v1/users?'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_tabs_and_counters(): void
    {
        $this->admin->forceFill(['last_login_at' => now()])->save();
        $this->dispatcher->forceFill(['last_login_at' => now()->subDays(40)])->save();
        $this->merchantUser->forceFill(['last_login_at' => now()])->save();
        $this->courierA->user->forceFill(['last_login_at' => now()])->save();
        $this->courierB->user->forceFill(['last_login_at' => now(), 'status' => 'suspended'])->save();
        Sanctum::actingAs($this->admin);

        $team = User::query()->whereNull('merchant_id')->whereDoesntHave('roles', fn ($q) => $q->where('name', 'courier'))->pluck('id')->sort()->values()->all();
        $summary = $this->getJson('/api/v1/users/summary')->assertOk()->json('data');
        $this->assertSame([count($team), 2, 1], [$summary['team'], $summary['couriers'], $summary['suspended']]);
        $this->assertSame(['total' => 2, 'active' => 1, 'suspended' => 1], $summary['roles']['courier']);
        $this->assertSame(30, $summary['inactive_days']);

        $this->assertSame($team, $this->ids('scope=team'));
        $this->assertSame([$this->merchantUser->id], array_values(array_intersect($this->ids('scope=merchants'), [$this->merchantUser->id])));
        // Inactif depuis 40 jours : à vérifier ; jamais connecté aussi, sauf s'il est suspendu
        $this->assertContains($this->dispatcher->id, $this->ids('scope=inactive'));
        $this->assertNotContains($this->admin->id, $this->ids('scope=inactive'));
        $this->assertNotContains($this->courierB->user_id, $this->ids('scope=inactive'));
        $this->assertSame([$this->courierB->user_id], $this->ids('scope=suspended'));

        $row = collect($this->getJson('/api/v1/users?scope=couriers')->json('data'))->firstWhere('id', $this->courierA->user_id);
        $this->assertSame($this->courierA->id, $row['courier_id']);
        $merchant = collect($this->getJson('/api/v1/users?scope=merchants')->json('data'))->firstWhere('id', $this->merchantUser->id);
        $this->assertSame($this->merchant->business_name, $merchant['merchant_name']);

        // Équipe d'une activité : comptes limités à elle et comptes qui voient tout
        $this->dispatcher->forceFill(['activities' => ['mad']])->save();
        $this->assertNotContains($this->dispatcher->id, $this->ids('scope=team&activity=distribution'));
        $this->assertContains($this->dispatcher->id, $this->ids('scope=team&activity=mad'));
    }

    public function test_history_logout_and_seen_as(): void
    {
        $this->dispatcher->createToken('téléphone');
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/users', ['name' => 'Yao Serge', 'phone' => '0798765432', 'role' => 'cashier'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/users/{$id}", ['role' => 'dispatcher'])->assertOk();
        $this->patchJson("/api/v1/users/{$id}", ['activities' => ['distribution']])->assertOk();
        $this->patchJson("/api/v1/users/{$id}", ['status' => 'suspended'])->assertOk();
        $this->patchJson("/api/v1/users/{$id}", ['status' => 'active', 'name' => 'Yao Serge K.', 'password' => 'nouveau-secret'])->assertOk();
        $this->getJson("/api/v1/users/{$id}/access")->assertOk();

        $history = $this->getJson("/api/v1/users/{$id}/history")->assertOk()->json('data');
        $texts = array_column($history, 'text');
        $this->assertSame('« Voir comme ce compte » utilisé', $texts[0]);
        foreach (['Nom changé : Yao Serge → Yao Serge K.', 'Accès modifiés : mot de passe', 'Compte réactivé', 'Compte suspendu', 'Périmètre changé : Distribution (avant : aucun)', 'Compte créé (Caissier)'] as $text) {
            $this->assertContains($text, $texts);
        }
        $this->assertTrue(collect($texts)->contains(fn ($t) => str_starts_with($t, 'Rôle changé : Caissier → ')));
        $this->assertSame($this->admin->name, $history[0]['by']);

        $this->postJson("/api/v1/users/{$this->dispatcher->id}/logout")->assertOk();
        $this->assertSame(0, $this->dispatcher->tokens()->count());
        $this->assertTrue(UserAccountEvent::where('user_id', $this->dispatcher->id)->where('action', UserAccountEvent::LOGGED_OUT)->exists());
        $this->postJson("/api/v1/users/{$this->admin->id}/logout")->assertUnprocessable();

        // Sans droit de gérer les comptes : pas de déconnexion
        Sanctum::actingAs($this->dispatcher);
        if (! $this->dispatcher->can('users.manage')) {
            $this->postJson("/api/v1/users/{$id}/logout")->assertForbidden();
        }
    }

    public function test_whatsapp_login_link(): void
    {
        Sanctum::actingAs($this->admin);
        // Sans mot de passe : le compte se connecte par le lien
        $id = $this->postJson('/api/v1/users', ['name' => 'Paul Kouassi', 'phone' => '0798765400', 'role' => 'hub_agent'])->assertCreated()->json('data.id');
        $link = $this->postJson("/api/v1/users/{$id}/invitation")->assertCreated()->json('data');
        $this->assertStringContainsString('wa.me/', $link['whatsapp_link']);
        $token = basename($link['url']);
        $this->postJson("/api/v1/users/{$this->merchantUser->id}/invitation")->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.name', 'Paul Kouassi');
        $this->postJson("/api/v1/invitations/{$token}/accept", ['password' => 'secret-123', 'password_confirmation' => 'secret-123'])
            ->assertOk()->assertJsonStructure(['token', 'user']);
        // Le lien ne sert qu'une fois ; le mot de passe choisi marche
        $this->getJson("/api/v1/invitations/{$token}")->assertNotFound();
        $this->postJson('/api/v1/auth/login', ['login' => '0798765400', 'password' => 'secret-123', 'space' => 'staff'])->assertOk();
        $this->assertSame(
            ['Lien de connexion ouvert : compte connecté', 'Lien de connexion préparé (WhatsApp)'],
            UserAccountEvent::where('user_id', $id)->whereIn('action', ['invitation_sent', 'invitation_used'])->orderByDesc('id')->get()->map->text()->all()
        );
    }
}
