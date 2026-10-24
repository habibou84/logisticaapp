<?php

namespace App\Providers;

use App\Services\Messaging\Gateways\AutoWhatsAppGateway;
use App\Services\Messaging\Gateways\LogSmsGateway;
use App\Services\Messaging\Gateways\LogWhatsAppGateway;
use App\Services\Messaging\Gateways\MetaCloudGateway;
use App\Services\Messaging\Gateways\SmsGateway;
use App\Services\Messaging\Gateways\TwilioSmsGateway;
use App\Services\Messaging\Gateways\WhatsAppGateway;
use App\Services\Orders\ActivityOptions;
use App\Services\WhatsApp\ClaudeOrderParser;
use App\Services\WhatsApp\HeuristicOrderParser;
use App\Services\WhatsApp\OrderMessageParser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Réglages distribution / MAD : lus une fois par requête (ou tâche)
        $this->app->scoped(ActivityOptions::class);
        // WhatsApp : réel pour les numéros connectés (auto), forcé (meta) ou simulé partout (log)
        $this->app->bind(WhatsAppGateway::class, fn () => match (config('messaging.whatsapp.driver')) {
            'meta' => new MetaCloudGateway,
            'log' => new LogWhatsAppGateway,
            default => new AutoWhatsAppGateway(new MetaCloudGateway, new LogWhatsAppGateway),
        });

        // Messages WhatsApp libres : Claude si une clé API est configurée, sinon analyse par règles
        $this->app->bind(OrderMessageParser::class, fn ($app) => filled(config('messaging.ai.api_key'))
            ? $app->make(ClaudeOrderParser::class)
            : $app->make(HeuristicOrderParser::class));

        $this->app->bind(SmsGateway::class, fn () => match (config('messaging.sms.driver')) {
            'twilio' => new TwilioSmsGateway,
            default => new LogSmsGateway,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Détecte en développement les requêtes N+1 et les attributs non assignables ignorés
        // (la préproduction se comporte comme la production)
        $strict = $this->app->environment('local', 'testing');
        Model::preventLazyLoading($strict);
        Model::preventSilentlyDiscardingAttributes($strict);

        DB::prohibitDestructiveCommands($this->app->isProduction());

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('login')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // API publique : quota par clé, identifiée par son préfixe (en-têtes X-RateLimit-* renvoyés)
        // Demandes de vérification de numéro (inscription, mot de passe oublié) et SMS envoyés
        RateLimiter::for('verification-start', fn (Request $request) => [
            Limit::perMinute(3)->by('vs-min:'.$request->ip()),
            Limit::perHour(10)->by('vs-hour:'.$request->ip()),
        ]);

        // Commandes sur les pages des clients (sans compte) : contre les robots
        RateLimiter::for('shop-order', fn (Request $request) => [
            Limit::perMinute(5)->by('shop-min:'.$request->ip()),
            Limit::perHour(30)->by('shop-hour:'.$request->ip()),
        ]);

        RateLimiter::for('verification-sms', fn (Request $request) => Limit::perHour(10)->by('sms:'.$request->ip()));

        // Espace connecté : quota par compte et pour toute l'entreprise
        RateLimiter::for('tenant', function (Request $request) {
            $user = $request->user();

            if (! $user) {
                return Limit::perMinute((int) config('platform.limits.user_per_minute'))->by('tenant-ip:'.$request->ip());
            }

            return array_filter([
                Limit::perMinute((int) config('platform.limits.user_per_minute'))->by('tenant-user:'.$user->id),
                $user->company_id
                    ? Limit::perMinute((int) config('platform.limits.company_per_minute'))->by('tenant-company:'.$user->company_id)
                    : null,
            ]);
        });

        RateLimiter::for('public-api', function (Request $request) {
            $key = (string) ($request->bearerToken() ?? $request->header('X-Api-Key'));

            return Limit::perMinute((int) config('services.public_api.rate_limit', 120))
                ->by(preg_match('/^lv_([a-z0-9]{8})_/', $key, $m) ? 'api-key:'.$m[1] : 'api-ip:'.$request->ip());
        });
    }
}
