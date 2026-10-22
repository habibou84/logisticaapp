<?php

namespace App\Services\Onboarding;

use App\Enums\OrderStatus;
use App\Enums\PlatformFeature;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierRule;
use App\Models\Merchant;
use App\Models\MetaTemplate;
use App\Models\Order;
use App\Models\PayPlan;
use App\Models\PricingGrid;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\Zone;

/**
 * Parcours de mise en route : les étapes qui rendent l'application prête à travailler, vérifiées sur les vraies
 * données (rien n'est coché à la main). Essentielles : sans elles, on travaille mal ; conseillées : elles améliorent
 * le service et peuvent attendre (l'administrateur peut les remettre à plus tard).
 */
class SetupGuide
{
    public function __construct(private readonly TestOrder $test) {}

    public const DONE = 'done';

    public const PARTIAL = 'partial';

    public const TODO = 'todo';

    /**
     * @return array{steps: list<array<string, mixed>>, essentials: array{done: int, total: int}, recommended: array{done: int, total: int}, ready: bool}
     */
    public function for(Company $company): array
    {
        $skipped = $company->setup_skipped ?? [];
        $steps = array_values(array_filter([
            $this->company($company),
            $this->zones($company),
            $this->pricing($company),
            $this->orderRules($company),
            $this->couriers($company),
            $this->pay($company),
            $this->merchants($company),
            $this->firstDelivery($company),
            $this->messages($company),
            $this->team($company),
            $this->alerts($company),
            $this->automation($company),
            $this->integrations($company),
            $this->billing($company),
        ]));

        $steps = array_map(fn (array $s) => [...$s, 'skipped' => ! $s['essential'] && $s['status'] !== self::DONE && in_array($s['key'], $skipped, true)], $steps);
        $count = fn (bool $essential) => [
            'done' => count(array_filter($steps, fn ($s) => $s['essential'] === $essential && $s['status'] === self::DONE)),
            'total' => count(array_filter($steps, fn ($s) => $s['essential'] === $essential)),
        ];
        $essentials = $count(true);

        return [
            'steps' => $steps,
            'essentials' => $essentials,
            'recommended' => $count(false),
            'ready' => $essentials['done'] === $essentials['total'],
            'health' => $this->health($company, $steps),
        ];
    }

    /**
     * Bilan de santé : ce qui se dérègle après la mise en route (point rouge sur les Réglages pour les alertes).
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{level: string, text: string, to: string}>
     */
    public function health(Company $c, array $steps): array
    {
        $issues = [];
        $pricing = collect($steps)->firstWhere('key', 'pricing');
        if ($pricing && $pricing['status'] === self::PARTIAL) {
            $issues[] = ['level' => 'alert', 'text' => 'Tarifs : '.lcfirst($pricing['lines'][0]), 'to' => '/admin/tarifs'];
        }

        $account = WhatsAppAccount::forCompanyId($c->id);
        if ($account?->token_expires_at && $account->token_expires_at->lt(now()->addDays(14))) {
            $issues[] = ['level' => 'alert', 'text' => 'L\'accès WhatsApp expire le '.$account->token_expires_at->format('d/m/Y').' : reconnectez le numéro avant cette date.', 'to' => '/admin/whatsapp'];
        }
        if ($account && $account->status !== 'connected' && filled($account->last_error)) {
            $issues[] = ['level' => 'alert', 'text' => 'WhatsApp ne fonctionne plus : '.$account->last_error, 'to' => '/admin/whatsapp'];
        }
        $rejected = $account ? MetaTemplate::query()->where('whatsapp_account_id', $account->id)->where('status', 'rejected')->count() : 0;
        if ($rejected) {
            $issues[] = ['level' => 'warning', 'text' => "{$rejected} modèle(s) de message refusé(s) par Meta : ces messages ne partent pas.", 'to' => '/admin/whatsapp'];
        }

        if (($c->auto_delivery ?? 'off') !== 'off') {
            $noZone = Courier::withoutGlobalScopes()->where('company_id', $c->id)->whereHas('user', fn ($q) => $q->where('status', 'active'))->doesntHave('zones')->count();
            if ($noZone) {
                $issues[] = ['level' => 'warning', 'text' => "Livraison automatique : {$noZone} livreur(s) sans zone ne sont jamais choisis.", 'to' => '/admin/livreurs'];
            }
        }
        if (($c->auto_pickup ?? 'off') !== 'off' && ! CourierRule::withoutGlobalScopes()->where('company_id', $c->id)->where('type', CourierRule::PICKUP)->where('is_active', true)->exists()) {
            $issues[] = ['level' => 'warning', 'text' => 'Ramassage automatique en marche, mais aucune règle de ramassage au planning : rien n\'est confié.', 'to' => '/admin/planning'];
        }
        if ($c->automationPaused()) {
            $issues[] = ['level' => 'warning', 'text' => 'Assignation automatique suspendue depuis le '.$c->automation_paused_at->format('d/m/Y à H:i').'.', 'to' => '/admin/planning'];
        }

        $test = $this->test->current($c);
        if ($test && $test->created_at->lt(now()->subDay())) {
            $issues[] = ['level' => 'warning', 'text' => "La course d'essai {$test->tracking_code} n'est pas encore effacée.", 'to' => '/admin/mise-en-route'];
        }

        return $issues;
    }

    /** Clés des étapes conseillées, les seules qu'on peut remettre à plus tard */
    public function recommendedKeys(Company $company): array
    {
        return array_column(array_filter($this->for($company)['steps'], fn ($s) => ! $s['essential']), 'key');
    }

    private function company(Company $c): array
    {
        $missing = array_keys(array_filter([
            'le téléphone' => blank($c->phone),
            'l\'adresse' => blank($c->address),
            'le logo' => blank($c->logo_path),
        ]));

        return $this->step('company', 'Votre entreprise', true,
            'Le nom, le logo et le téléphone apparaissent sur les messages, le suivi des colis et l\'application des e-commerçants.',
            $missing === [] ? self::DONE : (count($missing) < 3 ? self::PARTIAL : self::TODO),
            $missing === [] ? ["{$c->name} · coordonnées et logo en place"] : ['Il manque '.$this->list($missing).'.'],
            '/admin/reglages/entreprise', 'Compléter l\'entreprise');
    }

    private function zones(Company $c): array
    {
        $zones = Zone::withoutGlobalScopes()->where('company_id', $c->id)->where('is_active', true)->get(['id', 'parent_id', 'name', 'is_shipping']);
        $local = $zones->where('is_shipping', false);
        $communes = $local->whereNull('parent_id');
        $lines = $local->isEmpty()
            ? ['Aucune zone pour le moment. Commencez par les communes que vous desservez.']
            : [$communes->count().' commune(s), '.($local->count() - $communes->count()).' quartier(s), '.$zones->where('is_shipping', true)->count().' zone(s) d\'expédition.'];

        return $this->step('zones', 'Les zones desservies', true,
            'Chaque course part d\'une zone et va vers une zone : sans zones, ni prix, ni livreur.',
            $local->isEmpty() ? self::TODO : self::DONE, $lines, '/admin/zones', 'Gérer les zones');
    }

    /**
     * Toutes les paires de communes ont-elles un prix dans la grille par défaut ? (un quartier prend le prix de sa commune)
     */
    private function pricing(Company $c): array
    {
        $grid = PricingGrid::withoutGlobalScopes()->where('company_id', $c->id)->where('is_default', true)->first();
        $communes = Zone::withoutGlobalScopes()->where('company_id', $c->id)->where('is_active', true)
            ->where('is_shipping', false)->whereNull('parent_id')->orderBy('name')->get(['id', 'name']);

        if ($grid === null) {
            return $this->step('pricing', 'Les tarifs', true, 'Le prix de chaque course se calcule à partir de la grille de tarifs.',
                self::TODO, ['Aucune grille de tarifs par défaut.'], '/admin/tarifs', 'Créer la grille de tarifs');
        }

        $rules = PricingRule::query()->where('pricing_grid_id', $grid->id)->get(['origin_zone_id', 'destination_zone_id', 'is_symmetric']);
        $priced = [];
        foreach ($rules as $r) {
            $priced["{$r->origin_zone_id}-{$r->destination_zone_id}"] = true;
            if ($r->is_symmetric) {
                $priced["{$r->destination_zone_id}-{$r->origin_zone_id}"] = true;
            }
        }
        $missing = [];
        foreach ($communes as $a) {
            foreach ($communes as $b) {
                if (! isset($priced["{$a->id}-{$b->id}"])) {
                    $missing[] = $a->id === $b->id ? "à l'intérieur de {$a->name}" : "de {$a->name} vers {$b->name}";
                }
            }
        }
        $total = $communes->count() ** 2;

        return $this->step('pricing', 'Les tarifs', true,
            'Le prix de chaque course se calcule à partir de la grille de tarifs : un trajet sans prix bloque la création de la course.',
            $missing === [] && $total > 0 ? self::DONE : ($rules->isEmpty() ? self::TODO : self::PARTIAL),
            $missing === []
                ? ($total ? ["Grille « {$grid->name} » : les {$total} trajets entre communes ont un prix."] : ['Ajoutez d\'abord des zones.'])
                : [count($missing).' trajet(s) sans prix sur '.$total.', par exemple '.implode(', ', array_slice($missing, 0, 3)).'.'],
            '/admin/tarifs', 'Compléter les tarifs');
    }

    private function orderRules(Company $c): array
    {
        $lines = [
            'Validation des courses : '.($c->validation_mode === Company::VALIDATION_AUTO ? 'automatique' : ($c->validation_mode === Company::VALIDATION_MANUAL ? 'par l\'équipe' : 'avec règles')).'.',
            $c->daily_cutoff_time ? "Heure limite du jour : {$c->daily_cutoff_time}." : 'Pas encore d\'heure limite du jour : les courses tardives ne sont pas signalées.',
            "{$c->default_max_attempts} tentative(s) de livraison au plus.",
        ];

        return $this->step('order_rules', 'Les règles des courses', true,
            'Elles décident quand une course est validée, jusqu\'à quelle heure on livre le jour même et combien de fois on essaie.',
            $c->daily_cutoff_time ? self::DONE : self::PARTIAL, $lines, '/admin/reglages/courses', 'Régler les courses');
    }

    private function couriers(Company $c): array
    {
        $couriers = Courier::withoutGlobalScopes()->where('company_id', $c->id)
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))->withCount('zones')->get();
        $connected = $couriers->filter(fn (Courier $k) => $k->last_seen_at || $k->last_location_at)->count();
        $withZones = $couriers->where('zones_count', '>', 0)->count();

        $status = match (true) {
            $couriers->isEmpty() => self::TODO,
            $connected === 0 || $withZones === 0 => self::PARTIAL,
            default => self::DONE,
        };
        $lines = $couriers->isEmpty() ? ['Aucun livreur pour le moment.'] : array_values(array_filter([
            $couriers->count().' livreur(s), dont '.$connected.' déjà connecté(s) à son application.',
            $withZones < $couriers->count() ? ($couriers->count() - $withZones).' livreur(s) sans zone : indiquez ses zones sur sa fiche.' : null,
            $connected === 0 ? 'Demandez à un livreur d\'ouvrir son application et d\'allumer « disponible ».' : null,
        ]));

        return $this->step('couriers', 'Les livreurs', true,
            'Les livreurs reçoivent leurs missions dans leur application : chacun doit avoir son compte, son véhicule et ses zones.',
            $status, $lines, '/admin/livreurs', 'Gérer les livreurs');
    }

    private function pay(Company $c): array
    {
        $plan = PayPlan::defaultFor($c->id);
        $rules = $plan ? $plan->rules()->count() : 0;

        return $this->step('pay', 'La paie des livreurs', true,
            'Le plan de paie par défaut calcule les gains des livreurs dès leur première course.',
            $plan && $rules ? self::DONE : ($plan ? self::PARTIAL : self::TODO),
            [$plan ? "Plan par défaut : « {$plan->name} »".($rules ? '.' : ', sans montant pour le moment.') : 'Aucun plan de paie par défaut.'],
            '/admin/paie-livreurs', 'Régler la paie');
    }

    private function merchants(Company $c): array
    {
        $merchants = Merchant::withoutGlobalScopes()->where('company_id', $c->id)->where('status', 'active')->where('is_test', false)->get(['id', 'pickup_zone_id', 'pickup_address']);
        $ready = $merchants->filter(fn (Merchant $m) => $m->pickup_zone_id && filled($m->pickup_address))->count();

        return $this->step('merchants', 'Les e-commerçants', true,
            'Les courses viennent des e-commerçants : chacun a son compte, sa zone et son adresse de ramassage.',
            $ready > 0 ? self::DONE : ($merchants->isEmpty() ? self::TODO : self::PARTIAL),
            array_values(array_filter([
                $merchants->isEmpty() ? 'Aucun e-commerçant pour le moment.' : $merchants->count().' e-commerçant(s) actif(s), dont '.$ready.' avec zone et adresse de ramassage.',
                'Inscriptions en ligne '.($c->merchant_signup ? 'ouvertes.' : 'fermées : vous créez vous-même les comptes.'),
            ])),
            '/admin/marchands', 'Gérer les e-commerçants');
    }

    private function firstDelivery(Company $c): array
    {
        $delivered = $c->setup_test_delivered_at !== null
            || Order::withoutGlobalScopes()->where('company_id', $c->id)->where('is_test', false)->where('status', OrderStatus::Delivered->value)->exists();
        $test = $this->test->progress($c);

        $lines = match (true) {
            $test !== null && $test['delivered'] => ["Course d'essai {$test['tracking_code']} livrée : effacez-la pour ne pas fausser la caisse ni les statistiques."],
            $test !== null => ["Course d'essai {$test['tracking_code']} en cours : {$test['status_label']}."],
            $delivered => ['Au moins une course a été livrée.'],
            default => ['Lancez une course d\'essai : une « Boutique d\'essai » vous envoie un colis. Vous recevez les messages du destinataire, un livreur la ramasse et la livre avec le code.'],
        };

        return [
            ...$this->step('first_delivery', 'Une course d\'essai, du début à la fin', true,
                'Une course faite de bout en bout (création, ramassage, livraison avec le code, argent encaissé) vérifie que tout s\'enchaîne.',
                $delivered || ($test['delivered'] ?? false) ? self::DONE : ($test ? self::PARTIAL : self::TODO),
                $lines,
                $test ? "/admin/courses/{$test['order_id']}" : '/admin/courses/nouvelle', $test ? 'Ouvrir la course d\'essai' : 'Créer une course'),
            'test' => $test,
        ];
    }

    private function messages(Company $c): array
    {
        $account = WhatsAppAccount::forCompanyId($c->id);
        $connected = $account?->status === 'connected';
        $approved = $account ? MetaTemplate::query()->where('whatsapp_account_id', $account->id)->where('status', 'approved')->count() : 0;
        $templates = $account ? MetaTemplate::query()->where('whatsapp_account_id', $account->id)->count() : 0;

        return $this->step('messages', 'Les messages aux clients', false,
            'WhatsApp prévient le destinataire (livreur en route, code de livraison) et l\'e-commerçant à chaque étape.',
            $connected && $templates > 0 && $approved === $templates ? self::DONE : ($connected ? self::PARTIAL : self::TODO),
            [$connected ? "WhatsApp connecté ({$account->display_phone}) · modèles approuvés par Meta : {$approved} sur {$templates}." : 'WhatsApp n\'est pas encore connecté : les messages ne partent pas.'],
            '/admin/whatsapp', 'Connecter WhatsApp');
    }

    private function team(Company $c): array
    {
        $office = User::forCompany($c->id)->where('status', 'active')->role([Role::Dispatcher->value, Role::Cashier->value])->count();

        return $this->step('team', 'L\'équipe du bureau', false,
            'Un compte pour le dispatch et un pour la caisse : chacun voit ce qui le concerne, et chaque action porte un nom.',
            $office > 0 ? self::DONE : self::TODO,
            [$office > 0 ? "{$office} compte(s) dispatch ou caisse." : 'Seul l\'administrateur travaille pour le moment.'],
            '/admin/utilisateurs', 'Ajouter l\'équipe');
    }

    private function alerts(Company $c): array
    {
        $on = $c->pickup_assign_alert_minutes > 0 && $c->delivery_assign_alert_minutes > 0;

        return $this->step('alerts', 'Les alertes', false,
            'Le dispatch est prévenu des courses restées sans livreur et des colis gardés trop longtemps.',
            $on ? self::DONE : self::PARTIAL,
            ['Courses sans livreur : alerte après '.($c->pickup_assign_alert_minutes ?: 'jamais').($c->pickup_assign_alert_minutes ? ' min' : '').' au ramassage, '.($c->delivery_assign_alert_minutes ?: 'jamais').($c->delivery_assign_alert_minutes ? ' min' : '').' à la livraison.'],
            '/admin/reglages/alertes', 'Régler les alertes');
    }

    private function automation(Company $c): array
    {
        $rules = CourierRule::withoutGlobalScopes()->where('company_id', $c->id)->where('is_active', true)->count();
        $auto = ($c->auto_pickup ?? 'off') !== 'off' || ($c->auto_delivery ?? 'off') !== 'off';

        return $this->step('automation', 'L\'assignation automatique', false,
            'Le planning dit qui ramasse et qui livre où ; les courses peuvent ensuite partir seules. Commencez par le mode « à l\'essai ».',
            $rules > 0 && $auto ? self::DONE : ($rules > 0 || $auto ? self::PARTIAL : self::TODO),
            [($rules ? "{$rules} règle(s) au planning" : 'Aucune règle au planning').' · automatismes '.($auto ? 'en marche' : 'désactivés').'.'],
            '/admin/planning', 'Ouvrir le planning');
    }

    private function integrations(Company $c): ?array
    {
        if (! $c->hasFeature(PlatformFeature::Api)) {
            return null;
        }
        $keys = ApiKey::withoutGlobalScopes()->where('company_id', $c->id)->count();

        return $this->step('integrations', 'Les boutiques en ligne', false,
            'Une boutique en ligne, comme Jibiat Marchand, envoie ses courses toute seule avec une clé d\'accès.',
            $keys > 0 ? self::DONE : self::TODO,
            [$keys > 0 ? "{$keys} clé(s) d'accès créée(s)." : 'Aucune boutique reliée pour le moment.'],
            '/admin/integrations', 'Relier une boutique');
    }

    private function billing(Company $c): ?array
    {
        if ($c->plan_id === null) {
            return null;
        }
        $plan = $c->loadMissing('plan')->plan;

        return $this->step('billing', 'L\'abonnement', false,
            'Votre formule décide des modules disponibles et du nombre de courses par mois.',
            self::DONE, ['Formule '.($plan?->name ?? '—').'.'], '/admin/abonnement', 'Voir l\'abonnement');
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private function step(string $key, string $title, bool $essential, string $why, string $status, array $lines, string $to, string $action): array
    {
        return compact('key', 'title', 'essential', 'why', 'status', 'lines') + ['action' => ['to' => $to, 'label' => $action]];
    }

    /** « le téléphone, l'adresse et le logo » */
    private function list(array $items): string
    {
        return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)).' et '.end($items) : $items[0];
    }
}
