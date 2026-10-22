<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\PlatformFeature;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Connect\Directory;
use App\Services\Platform\CompanySignup;
use App\Support\PhoneNumber;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Site de présentation, sur l'adresse principale de la plateforme (jibiat.com) :
 * accueil, fonctionnalités, tarifs et annuaire des entreprises de livraison. Les tarifs viennent des formules de la console.
 */
class MarketingController extends Controller
{
    public const PAGES = ['' => 'home', 'fonctionnalites' => 'features', 'tarifs' => 'pricing', 'annuaire' => 'directory'];

    public function __construct(private readonly CompanySignup $signup) {}

    public function show(Request $request): Response
    {
        $page = self::PAGES[trim($request->path(), '/')] ?? null;

        $data = $this->data();
        if ($page === 'directory') {
            $data['companies'] = app(Directory::class)->all();
        }

        return response()->view($page ? "marketing.{$page}" : 'marketing.not-found', $data, $page ? 200 : 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $domain = Tenancy::domain();
        $whatsapp = (string) PhoneNumber::normalize(config('marketing.whatsapp'));
        $open = $this->signup->isOpen();

        return [
            'domain' => $domain,
            'signupUrl' => $open ? config('platform.scheme').'://'.config('platform.admin_subdomain').'.'.$domain.'/creer-mon-entreprise' : null,
            'trialDays' => (int) config('platform.trial_days'),
            'plans' => $this->plans(),
            'whatsappUrl' => $whatsapp !== '' ? 'https://wa.me/'.ltrim($whatsapp, '+') : null,
            'demoUrl' => $whatsapp !== '' ? 'https://wa.me/'.ltrim($whatsapp, '+').'?text='.rawurlencode('Bonjour, je souhaite une démonstration du logiciel de livraison Jibiat pour mon entreprise.') : null,
            'contactEmail' => config('platform.contact_email') ?: config('marketing.legal.email'),
            'testimonials' => config('marketing.testimonials', []),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function plans(): Collection
    {
        return $this->signup->plans()->map(fn (Plan $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'price_monthly' => $p->price_monthly,
            'included_orders' => $p->included_orders,
            'overage_per_order' => $p->overage_per_order,
            'max_couriers' => $p->max_couriers,
            'features' => collect($p->features ?? [])->map(fn (string $f) => PlatformFeature::tryFrom($f)?->label())->filter()->values(),
        ])->values();
    }
}
