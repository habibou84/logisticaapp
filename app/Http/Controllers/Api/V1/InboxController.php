<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\InboundMessage;
use App\Models\OutboundMessage;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\InboxContacts;
use App\Services\Messaging\Messenger;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Messages : la messagerie de l'entreprise, façon WhatsApp. Une conversation par numéro, avec ce que les
 * clients, les clients et les livreurs écrivent et tout ce qui leur a été envoyé (WhatsApp, SMS,
 * notifications). L'équipe lit et répond. Avec la connexion officielle de WhatsApp, une réponse libre n'est permise que dans les 24 h qui
 * suivent le dernier message reçu.
 */
class InboxController extends Controller
{
    public const WINDOW_HOURS = 24;

    /** Conversations affichées : activité des 90 derniers jours, 300 numéros au plus par sens */
    public const HISTORY_DAYS = 90;

    public const LIST_LIMIT = 300;

    public function __construct(private readonly InboxContacts $contacts) {}

    /**
     * Conversations, la plus récente en premier : chaque numéro qui a écrit à l'entreprise ou à qui elle a
     * envoyé un message (WhatsApp, SMS, notification), sur les 90 derniers jours.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::OrdersDispatch->value);
        $request->validate([
            'filter' => ['nullable', Rule::in(['unread', 'failed', InboxContacts::CLIENT, InboxContacts::MERCHANT, InboxContacts::COURIER, InboxContacts::UNKNOWN])],
            'search' => ['nullable', 'string', 'max:50'],
        ]);
        $companyId = $request->user()->company_id;
        $since = now()->subDays(self::HISTORY_DAYS);
        $filter = $request->input('filter');

        $in = InboundMessage::query()
            ->where('simulated', false)->where('created_at', '>=', $since)
            ->selectRaw('from_phone AS phone, MAX(id) AS last_id, MAX(created_at) AS last_at, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread')
            ->groupBy('from_phone')->orderByDesc('last_id')->limit(self::LIST_LIMIT)
            ->get()->keyBy('phone');
        $out = $filter === 'unread' ? collect() : OutboundMessage::query()
            ->where('to', '!=', '')->where('recipient_type', '!=', 'test')->where('created_at', '>=', $since)
            ->selectRaw("\"to\" AS phone, MAX(id) AS last_id, MAX(created_at) AS last_at, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->groupBy('to')->orderByDesc('last_id')->limit(self::LIST_LIMIT)
            ->get()->keyBy('phone');

        $phones = $in->keys()->merge($out->keys())->unique()
            ->filter(fn ($phone) => match ($filter) {
                'unread' => ($in[$phone]->unread ?? 0) > 0,
                'failed' => ($out[$phone]->failed ?? 0) > 0,
                default => true,
            })->values();

        // Dernier message de chaque sens (only() d'une collection Eloquent filtre par identifiant : base d'abord)
        $lastIn = InboundMessage::whereIn('id', $in->toBase()->only($phones->all())->pluck('last_id'))->get()->keyBy('from_phone');
        $lastOut = OutboundMessage::whereIn('id', $out->toBase()->only($phones->all())->pluck('last_id'))->get()->keyBy('to');
        $contacts = $this->contacts->identify($companyId, $phones->all());

        $search = $request->filled('search') ? mb_strtolower(preg_replace('/\s+/', '', (string) $request->input('search'))) : null;
        $kind = in_array($filter, [null, 'unread', 'failed'], true) ? null : $filter;

        $data = $phones->map(function (string $phone) use ($in, $out, $lastIn, $lastOut, $contacts) {
            $received = $lastIn[$phone] ?? null;
            $sent = $lastOut[$phone] ?? null;
            $latestIsIn = $received && (! $sent || $received->created_at->gte($sent->created_at));

            return [
                'contact' => $contacts[$phone],
                'last' => $latestIsIn
                    ? ['body' => $this->preview($received), 'at' => $received->created_at, 'direction' => 'in']
                    : ['body' => (string) $sent->body, 'at' => $sent->created_at, 'direction' => 'out',
                        'automatic' => $sent->event !== 'inbox_reply', 'status' => $sent->status->value],
                'unread' => (int) ($in[$phone]->unread ?? 0),
                'failed' => (int) ($out[$phone]->failed ?? 0),
            ];
        })->filter(fn ($c) => $kind === null || $c['contact']['kind'] === $kind)
            ->filter(fn ($c) => $search === null || str_contains(mb_strtolower(str_replace(' ', '', implode(' ', array_filter([
                $c['contact']['name'], $c['contact']['phone'], $c['contact']['order']['tracking_code'] ?? null,
            ])))), $search))
            ->sortByDesc(fn ($c) => $c['last']['at']->getTimestamp())
            ->values()
            ->take(150);

        return response()->json(['data' => $data, 'meta' => ['unread' => $this->unreadCount()]]);
    }

    /** Nombre de conversations non lues (compteur du menu) */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize(Permission::OrdersDispatch->value);

        return response()->json(['data' => ['unread' => $this->unreadCount()]]);
    }

    /** Une conversation : messages reçus et envoyés à ce numéro ; marquée lue */
    public function show(Request $request, string $phone): JsonResponse
    {
        Gate::authorize(Permission::OrdersDispatch->value);
        $phone = $this->phone($phone);

        InboundMessage::where('from_phone', $phone)->where('simulated', false)->whereNull('read_at')
            ->update(['read_at' => now(), 'read_by' => $request->user()->id]);

        return response()->json(['data' => $this->conversation($request->user()->company_id, $phone)]);
    }

    /** Réponse de l'équipe */
    public function reply(Request $request, string $phone, Messenger $messenger): JsonResponse
    {
        Gate::authorize(Permission::OrdersDispatch->value);
        $data = $request->validate(['text' => ['required', 'string', 'max:1000']], [], ['text' => 'réponse']);
        $phone = $this->phone($phone);
        $companyId = $request->user()->company_id;

        $window = $this->window($companyId, $phone);
        if (! $window['open']) {
            throw new BusinessRuleException($window['reason'], 'text');
        }

        $contact = $this->contacts->identify($companyId, [$phone])[$phone];
        $messenger->reply($companyId, $phone, trim($data['text']), [], [
            'recipient_type' => $contact['kind'] === InboxContacts::CLIENT ? 'recipient' : $contact['kind'],
            'event' => 'inbox_reply',
            'order_id' => $contact['order']['id'] ?? null,
            'merchant_id' => $contact['merchant_id'],
        ]);

        return response()->json(['data' => $this->conversation($companyId, $phone)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function conversation(int $companyId, string $phone): array
    {
        $inbound = InboundMessage::where('from_phone', $phone)->where('simulated', false)->latest('id')->limit(100)->get()
            ->map(fn (InboundMessage $m) => [
                'id' => "in-{$m->id}", 'direction' => 'in', 'body' => $this->preview($m), 'at' => $m->created_at,
                'order' => $m->order_id,
            ]);
        $outbound = OutboundMessage::where('to', $phone)->where('recipient_type', '!=', 'test')->latest('id')->limit(100)->get()
            ->map(fn (OutboundMessage $m) => [
                'id' => "out-{$m->id}", 'message_id' => $m->id, 'direction' => 'out', 'body' => $m->body, 'at' => $m->created_at,
                'error' => $m->error,
                'channel' => $m->channel->value, 'status' => $m->status->value, 'status_label' => $m->status->label(),
                'automatic' => $m->event !== 'inbox_reply',
                // Réponse du robot à un message reçu (création de course, suivi) ; sinon message parti tout seul
                'robot' => $m->event === 'conversation',
                'template' => $m->template_name,
            ]);

        return [
            'contact' => $this->contacts->identify($companyId, [$phone])[$phone],
            'messages' => $inbound->concat($outbound)->sortBy(fn ($m) => [$m['at']?->getTimestamp(), $m['id']])->values(),
            'window' => $this->window($companyId, $phone),
        ];
    }

    /**
     * Réponse libre possible ? WhatsApp : 24 h après le dernier message reçu.
     *
     * @return array{open: bool, until: ?Carbon, reason: ?string}
     */
    private function window(int $companyId, string $phone): array
    {
        $account = WhatsAppAccount::forCompanyId($companyId);
        if ($account === null || ! $account->isConfigured()) {
            return ['open' => false, 'until' => null, 'reason' => 'WhatsApp n\'est pas connecté : connectez-le dans la page WhatsApp.'];
        }

        $lastIn = InboundMessage::where('from_phone', $phone)->where('simulated', false)->latest('id')->value('created_at');
        $until = $lastIn ? Carbon::parse($lastIn)->addHours(self::WINDOW_HOURS) : null;
        if ($until === null) {
            return ['open' => false, 'until' => null, 'reason' => 'Cette personne n\'a pas encore écrit au WhatsApp de l\'entreprise : WhatsApp n\'autorise que les messages prédéfinis tant qu\'elle n\'a pas écrit.'];
        }
        if ($until->isPast()) {
            return ['open' => false, 'until' => $until, 'reason' => 'Plus de 24 h depuis son dernier message : WhatsApp n\'autorise plus de réponse libre. Seuls les messages prédéfinis partent, jusqu\'à ce que la personne écrive de nouveau.'];
        }

        return ['open' => true, 'until' => $until, 'reason' => null];
    }

    private function unreadCount(): int
    {
        return InboundMessage::where('simulated', false)->whereNull('read_at')->distinct()->count('from_phone');
    }

    private function preview(InboundMessage $message): string
    {
        return match (true) {
            filled($message->body) => (string) $message->body,
            $message->type === 'image' => '[Photo]',
            $message->type === 'audio' => '[Message vocal]',
            $message->type === 'location' => '[Position]',
            default => '[Message sans texte]',
        };
    }

    private function phone(string $phone): string
    {
        $normalized = PhoneNumber::normalize($phone);
        abort_if($normalized === null, 404, 'Conversation introuvable.');

        return $normalized;
    }
}
