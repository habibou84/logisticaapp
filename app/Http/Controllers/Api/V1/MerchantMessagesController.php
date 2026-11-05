<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationEvent;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Réglages › Messages aux clients : pour chaque étape d'une course, WhatsApp (ou notification sur le téléphone)
 * en plus de la notification dans l'application, et le client peut-il changer ces choix.
 */
class MerchantMessagesController extends Controller
{
    /** Exemple du message, par étape */
    private const EXAMPLES = [
        'order.received' => '✅ Course LV-7K2M reçue et validée. Livraison aujourd\'hui pour Awa K. (Yopougon).',
        'order.confirmed' => 'Course LV-7K2M validée. Le livreur passe bientôt.',
        'order.picked_up' => 'Colis LV-7K2M récupéré par notre livreur.',
        'order.delivered' => 'Colis LV-7K2M livré, 15 000 F encaissés.',
        'order.incident' => 'Livraison LV-7K2M : client injoignable, nouvelle date…',
        'payout.paid' => 'Reversement de 120 000 F envoyé.',
        'order.returned' => 'Bon de retour : 3 colis vous sont rapportés.',
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $events = array_column(NotificationEvent::cases(), 'value');
        $data = $request->validate([
            'events' => ['sometimes', 'array'],
            'events.*' => ['boolean'],
            'locked' => ['sometimes', 'boolean'],
        ]);
        abort_if(array_diff(array_keys($data['events'] ?? []), $events), 422, 'Étape inconnue.');

        $company = $request->user()->company;
        $company->forceFill(array_filter([
            'merchant_whatsapp_events' => isset($data['events']) ? [...($company->merchant_whatsapp_events ?? []), ...$data['events']] : null,
            'merchant_messages_locked' => $data['locked'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return response()->json(['data' => $this->present($request)]);
    }

    /** « Appliquer à tous » : les choix des clients pour cette étape (ou toutes) reprennent ceux de l'agence */
    public function applyToAll(Request $request): JsonResponse
    {
        $data = $request->validate(['event' => ['nullable', Rule::in(array_column(NotificationEvent::cases(), 'value'))]]);
        $removed = NotificationPreference::query()->where('company_id', $request->user()->company_id)
            ->when($data['event'] ?? null, fn ($q, $event) => $q->where('event', $event))
            ->delete();

        return response()->json(['data' => [...$this->present($request), 'removed' => $removed]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request): array
    {
        $company = $request->user()->company->fresh();
        $merchants = Merchant::query()->where('status', 'active')->count();
        // Clients dont le choix diffère de celui de l'agence, par étape
        $differs = NotificationPreference::query()->where('company_id', $company->id)
            ->whereIn('merchant_id', Merchant::query()->where('status', 'active')->select('id'))
            ->get(['event', 'channels'])
            ->filter(fn (NotificationPreference $p) => ($p->channels !== []) !== $company->merchantWantsByDefault($p->event))
            ->countBy(fn (NotificationPreference $p) => $p->event->value);

        return [
            'locked' => (bool) $company->merchant_messages_locked,
            'merchants' => $merchants,
            'events' => array_map(fn (NotificationEvent $e) => [
                'event' => $e->value,
                'label' => $e->label(),
                'whatsapp' => $company->merchantWantsByDefault($e),
                'example' => self::EXAMPLES[$e->value] ?? null,
                // Ces clients gardent leur propre choix (tant que l'agence ne verrouille pas)
                'differs' => (int) ($differs[$e->value] ?? 0),
            ], NotificationEvent::cases()),
        ];
    }
}
