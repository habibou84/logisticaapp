<?php

namespace App\Services\WhatsApp;

use App\Enums\FeePayer;
use App\Enums\OrderStatus;
use App\Enums\PlatformFeature;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\InboundMessage;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Models\Zone;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Messaging\Messenger;
use App\Services\Orders\OrderService;
use App\Services\Pricing\PricingService;
use App\Services\Reports\OrderSummary;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conversation WhatsApp avec un e-commerçant : menu, création d'une course à partir
 * d'un message libre (questions pour les informations manquantes, récapitulatif,
 * confirmation), suivi d'un colis, point du jour.
 */
class Conversation
{
    // Champs indispensables, dans l'ordre où on les demande
    private const REQUIRED = ['recipient_phone', 'zone_id', 'items_amount'];

    private const QUESTIONS = [
        'recipient_phone' => '📞 Quel est le numéro du client ?',
        'zone_id' => '📍 Dans quelle commune ou quel quartier faut-il livrer ?',
        'items_amount' => '💰 Combien le livreur doit-il encaisser pour les articles ? (0 si c\'est déjà payé)',
    ];

    private const MENU = ['new' => '📦 Nouvelle course', 'point' => '📊 Point du jour', 'track' => '🔎 Suivre un colis'];

    private Company $company;

    private WhatsAppSession $session;

    private string $phone;

    private ?InboundMessage $inbound = null;

    public function __construct(
        private readonly Messenger $messenger,
        private readonly OrderService $orders,
        private readonly PricingService $pricing,
        private readonly OrderSummary $summary,
        private readonly OrderMessageParser $parser,
        private readonly HeuristicOrderParser $rules,
        private readonly ZoneMatcher $zoneMatcher,
        private readonly PhoneVerifier $verifier,
    ) {}

    /**
     * Traite un message reçu. $type : text | button | autre (image, audio…).
     */
    public function handle(Company $company, string $from, string $type, ?string $text, ?string $buttonId = null, array $meta = []): InboundMessage
    {
        $this->company = $company;
        $this->phone = PhoneNumber::normalize($from) ?? $from;

        $this->inbound = InboundMessage::create([
            'company_id' => $company->id,
            'whatsapp_account_id' => $meta['whatsapp_account_id'] ?? null,
            'provider_message_id' => $meta['provider_message_id'] ?? null,
            'from_phone' => $this->phone,
            'type' => $type,
            'body' => $text,
            'payload' => $buttonId ? ['button_id' => $buttonId] : null,
        ]);

        // Message de vérification d'un numéro (inscription, mot de passe oublié)
        if ($type === 'text' && ($answer = $this->verifier->handleWhatsApp($company, $this->phone, $text)) !== null) {
            $this->reply($answer);

            return $this->done();
        }

        [$merchant, $user] = $this->identify();
        $this->inbound->update(['merchant_id' => $merchant?->id]);

        if ($merchant === null) {
            $this->reply("Bonjour 👋 Ce numéro n'est pas enregistré comme e-commerçant chez {$company->name}."
                .($company->phone ? " Contactez l'agence au ".PhoneNumber::display($company->phone).'.' : ''));

            return $this->done();
        }

        // Activé par l'entreprise et inclus dans sa formule
        if (! $company->whatsapp_orders || ! $company->hasFeature(PlatformFeature::WhatsAppBot)) {
            $this->reply("La création de courses par WhatsApp n'est pas activée. Utilisez votre application {$company->name}.");

            return $this->done();
        }

        $this->session = WhatsAppSession::forCompany($company->id)->firstOrNew(['phone' => $this->phone]);
        $this->session->fill(['company_id' => $company->id, 'merchant_id' => $merchant->id, 'user_id' => $user?->id]);
        if ($this->session->exists && $this->session->expires_at?->isPast()) {
            $this->session->reset();
        }

        if (! in_array($type, ['text', 'button'], true)) {
            $this->reply('Je ne lis que les messages écrits pour le moment. Envoyez les informations de la course en texte 🙏');
        } else {
            $this->route($merchant, trim((string) $text), $buttonId);
        }

        $this->session->expires_at = now()->addMinutes(config('messaging.session_minutes'));
        $this->session->save();

        return $this->done();
    }

    private function route(Merchant $merchant, string $text, ?string $button): void
    {
        $command = $button ?? $this->command($text);

        // Code de suivi cité n'importe où
        if ($button === null && preg_match('/\bLV-[A-Z0-9]{4}-[A-Z0-9]{4}\b/i', $text, $m)) {
            $this->track($merchant, strtoupper($m[0]));

            return;
        }

        if (str_starts_with((string) $button, 'zone:')) {
            $this->chooseZone($merchant, (int) substr($button, 5));

            return;
        }

        match ($command) {
            'menu' => $this->menu($merchant),
            'cancel' => $this->cancel(),
            'new' => $this->startDraft(),
            'point' => $this->point($merchant),
            'track' => $this->askTracking(),
            'confirm' => $this->session->state === 'confirm' ? $this->create($merchant) : $this->menu($merchant),
            'edit' => $this->askCorrection(),
            default => $this->freeText($merchant, $text),
        };
    }

    private function freeText(Merchant $merchant, string $text): void
    {
        if ($this->session->state === 'track') {
            $this->reply('Je ne trouve pas de code de suivi dans ce message. Il ressemble à LV-AB12-CD34.', ['menu' => 'Menu']);

            return;
        }

        $zones = $this->zones();
        $draft = $this->session->draft ?? [];
        $fields = $this->session->awaiting
            ? $this->answer($this->session->awaiting, $text, $zones)
            : [];

        // Pas une réponse à la question posée : on analyse le message complet
        if ($fields === []) {
            $fields = $this->parser->parse($text, $zones, $this->phone);
        }

        // « De Cocody à Yopougon » : la commune de ramassage du marchand n'est pas la destination
        if (count($fields['zones'] ?? []) > 1 && $merchant->pickup_zone_id) {
            $others = array_values(array_diff($fields['zones'], [$merchant->pickup_zone_id]));
            $fields['zones'] = $others ?: $fields['zones'];
        }

        if ($this->session->state === 'idle' && $fields === []) {
            $this->menu($merchant, "Je n'ai pas compris 🙂 Choisissez une action, ou envoyez directement les informations de la course.");

            return;
        }

        $this->session->state = 'draft';
        $this->session->draft = $this->merge($draft, $fields);
        $this->next($merchant, $zones);
    }

    /**
     * Réponse courte à la question posée.
     *
     * @param  Collection<int, Zone>  $zones
     * @return array<string, mixed>
     */
    private function answer(string $field, string $text, Collection $zones): array
    {
        return match ($field) {
            'recipient_phone' => ($phone = $this->rules->phones($text)->first() ?? PhoneNumber::normalize($text)) ? ['recipient_phone' => $phone] : [],
            'items_amount' => match (true) {
                (bool) preg_match('/\d/', $text) => ['items_amount' => $this->rules->amount($text, true)],
                (bool) preg_match('/\b(d[ée]j[aà]\s+)?pay[ée]e?s?\b|\brien\b|pr[ée]pay/iu', $text) => ['items_amount' => 0],
                default => [],
            },
            'zone_id' => ($found = $this->zoneMatcher->answer($text, $zones))->isNotEmpty() ? ['zones' => $found->pluck('id')->all()] : [],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function merge(array $draft, array $fields): array
    {
        if (isset($fields['zones'])) {
            $candidates = $fields['zones'];
            unset($fields['zones']);
            if (count($candidates) === 1) {
                $draft['zone_id'] = $candidates[0];
                unset($draft['zone_choices']);
            } else {
                $draft['zone_choices'] = array_slice($candidates, 0, 3);
            }
        }

        return array_merge($draft, array_filter($fields, fn ($v) => $v !== null));
    }

    /**
     * Pose la question suivante, ou affiche le récapitulatif quand tout est là.
     *
     * @param  Collection<int, Zone>  $zones
     */
    private function next(Merchant $merchant, Collection $zones): void
    {
        $draft = $this->session->draft;

        // Plusieurs zones possibles : on fait choisir
        if (! isset($draft['zone_id']) && ! empty($draft['zone_choices'])) {
            $choices = $zones->whereIn('id', $draft['zone_choices'])
                ->mapWithKeys(fn (Zone $z) => ['zone:'.$z->id => $z->name])->all();
            $this->session->awaiting = 'zone_id';
            $this->reply('📍 Quelle zone exactement ?', $choices);

            return;
        }

        foreach (self::REQUIRED as $field) {
            if (! isset($draft[$field])) {
                $this->session->awaiting = $field;
                $this->reply($this->progress($draft, $zones).self::QUESTIONS[$field], ['cancel' => 'Annuler']);

                return;
            }
        }

        $this->session->awaiting = null;
        $this->recap($merchant, $zones);
    }

    /**
     * @param  Collection<int, Zone>  $zones
     */
    private function recap(Merchant $merchant, Collection $zones): void
    {
        $draft = $this->session->draft;
        $zone = $zones->firstWhere('id', $draft['zone_id']);
        $pickup = $merchant->pickup_zone_id ? Zone::forCompany($this->company->id)->find($merchant->pickup_zone_id) : null;

        if ($pickup === null) {
            $this->reply("Votre adresse de ramassage n'est pas encore enregistrée : contactez l'agence pour la compléter.");

            return;
        }

        try {
            $fees = $this->pricing->quote($merchant, $pickup, $zone)->total();
        } catch (BusinessRuleException $e) {
            $this->session->awaiting = 'zone_id';
            unset($draft['zone_id']);
            $this->session->draft = $draft;
            $this->reply("{$e->getMessage()} Indiquez une autre zone, ou tapez « annuler ».");

            return;
        }

        $payer = FeePayer::tryFrom($draft['fee_payer'] ?? '') ?? $merchant->default_fee_payer;
        $items = (int) $draft['items_amount'];
        $cod = Order::computeCodAmount($items, $fees, $payer);

        $lines = array_filter([
            '📝 *Récapitulatif de la course*',
            '👤 '.trim(($draft['recipient_name'] ?? 'Client').' · '.PhoneNumber::display($draft['recipient_phone'])),
            isset($draft['recipient_phone2']) ? '📞 Autre numéro : '.PhoneNumber::display($draft['recipient_phone2']) : null,
            '📍 '.$zone->fullName().(isset($draft['delivery_address']) ? ' — '.$draft['delivery_address'] : ''),
            isset($draft['delivery_landmark']) ? '🧭 Repère : '.$draft['delivery_landmark'] : null,
            isset($draft['description']) ? '📦 '.$draft['description'] : null,
            '💰 Articles : '.Money::format($items),
            '🛵 Livraison : '.Money::format($fees).($payer === FeePayer::Recipient ? ' (payée par le client)' : ' (à votre charge)'),
            $zone->is_shipping ? '🚌 Expédition : frais de gare en plus, au réel' : null,
            '💵 *Le client paiera : '.Money::format($cod).'*',
        ]);

        $this->session->state = 'confirm';
        $this->reply(implode("\n", $lines), ['confirm' => '✅ Confirmer', 'edit' => '✏️ Modifier', 'cancel' => '❌ Annuler']);
    }

    private function create(Merchant $merchant): void
    {
        $draft = $this->session->draft;
        $actor = $this->actor($merchant);

        if ($actor === null) {
            $this->reply("Aucun compte utilisateur n'est rattaché à votre boutique : contactez l'agence.");

            return;
        }

        try {
            $order = DB::transaction(fn () => $this->orders->create($actor, $merchant, [
                'recipient_name' => $draft['recipient_name'] ?? null,
                'recipient_phone' => $draft['recipient_phone'],
                'recipient_phone2' => $draft['recipient_phone2'] ?? null,
                'delivery_zone_id' => $draft['zone_id'],
                'delivery_address' => $draft['delivery_address'] ?? null,
                'delivery_landmark' => $draft['delivery_landmark'] ?? null,
                'items_amount' => (int) $draft['items_amount'],
                'fee_payer' => $draft['fee_payer'] ?? null,
                'description' => $draft['description'] ?? null,
                'merchant_note' => $draft['merchant_note'] ?? null,
            ], 'whatsapp'));
        } catch (BusinessRuleException $e) {
            $this->reply("La course n'a pas pu être enregistrée : {$e->getMessage()}", ['edit' => '✏️ Modifier', 'cancel' => '❌ Annuler']);

            return;
        }

        $this->inbound->update(['order_id' => $order->id]);
        $this->session->reset();

        $this->reply(implode("\n", [
            "✅ Course *{$order->tracking_code}* enregistrée.",
            'Code de livraison : '.$order->delivery_code,
            'Le client paiera : '.Money::format($order->cod_amount),
            'Suivi : '.$order->company->url('/suivi/'.$order->tracking_code),
        ]), ['new' => '📦 Autre course', 'menu' => 'Menu']);
    }

    private function startDraft(): void
    {
        $this->session->reset();
        $this->session->state = 'draft';
        $this->reply(implode("\n", [
            'Envoyez les informations de la course en un message, par exemple :',
            '',
            'Nom : Awa Koné',
            'Tél : 07 07 07 07 07',
            'Commune : Yopougon',
            'Adresse : Siporex, face pharmacie',
            'Montant : 15000',
            '',
            'Vous pouvez aussi transférer le message de votre client : je m\'occupe du reste 🙂',
        ]), ['cancel' => 'Annuler']);
    }

    private function chooseZone(Merchant $merchant, int $zoneId): void
    {
        $zones = $this->zones();
        if (! $zones->contains('id', $zoneId) || $this->session->state === 'idle') {
            $this->menu($merchant);

            return;
        }

        $draft = $this->session->draft ?? [];
        $draft['zone_id'] = $zoneId;
        unset($draft['zone_choices']);
        $this->session->draft = $draft;
        $this->next($merchant, $zones);
    }

    private function askCorrection(): void
    {
        if (empty($this->session->draft)) {
            $this->startDraft();

            return;
        }

        $this->session->state = 'draft';
        $this->session->awaiting = null;
        $this->reply('Envoyez la correction, par exemple « montant 15000 », « commune Cocody » ou « tél 05 06 07 08 09 ».', ['cancel' => 'Annuler']);
    }

    private function cancel(): void
    {
        $had = ! empty($this->session->draft);
        $this->session->reset();
        $this->reply($had ? 'Course annulée. Tapez « menu » quand vous voulez.' : 'D\'accord. Tapez « menu » quand vous voulez.');
    }

    private function askTracking(): void
    {
        $this->session->state = 'track';
        $this->reply('🔎 Envoyez le code de suivi du colis (il ressemble à LV-AB12-CD34).');
    }

    private function track(Merchant $merchant, string $code): void
    {
        $order = Order::forCompany($this->company->id)->where('merchant_id', $merchant->id)->where('tracking_code', $code)->first();
        $this->session->state = $this->session->state === 'track' ? 'idle' : $this->session->state;

        if ($order === null) {
            $this->reply("Aucune de vos courses n'a le code {$code}.");

            return;
        }

        $lines = array_filter([
            "📦 *{$order->tracking_code}* : {$order->statusLabel()}",
            '👤 '.($order->recipient_name ?: PhoneNumber::display($order->recipient_phone)),
            $order->status === OrderStatus::Rescheduled && $order->delivery_scheduled_date ? 'Nouvelle date : '.$order->delivery_scheduled_date->format('d/m/Y') : null,
            $order->status === OrderStatus::Delivered ? 'Encaissé : '.Money::format((int) $order->collected_amount) : 'À encaisser : '.Money::format($order->cod_amount),
            'Détail : '.$order->company->url('/marchand/courses/'.$order->id),
        ]);
        $this->reply(implode("\n", $lines));
    }

    private function point(Merchant $merchant): void
    {
        $data = $this->summary->build(Order::forCompany($this->company->id)->where('merchant_id', $merchant->id), today(), today());
        $c = $data['counts'];
        $a = $data['amounts'];

        $this->reply(implode("\n", [
            '📊 *Point du jour* ('.today()->format('d/m/Y').')',
            "📦 {$c['total']} courses · ✅ {$c['delivered']} livrées · 🛵 {$c['in_progress']} en cours · ⚠️ ".($c['failed'] + $c['rescheduled']).' problèmes',
            'Encaissé : '.Money::format($a['collected']),
            'Frais : '.Money::format($a['fees'] + $a['shipping_fees'] + $a['other_fees']),
            '*Net : '.Money::format($a['net_to_merchant']).'*',
        ]), ['new' => '📦 Nouvelle course', 'menu' => 'Menu']);
    }

    private function menu(Merchant $merchant, ?string $intro = null): void
    {
        $inProgress = ! empty($this->session->draft) ? "\n(Une course est en cours de saisie : renvoyez une information pour la continuer.)" : '';
        $this->reply(($intro ?? "Bonjour {$merchant->business_name} 👋 Que voulez-vous faire ?").$inProgress, self::MENU);
    }

    /**
     * Ce qu'on a déjà compris, rappelé avant la question suivante.
     *
     * @param  array<string, mixed>  $draft
     * @param  Collection<int, Zone>  $zones
     */
    private function progress(array $draft, Collection $zones): string
    {
        $known = array_filter([
            isset($draft['recipient_name']) ? '👤 '.$draft['recipient_name'] : null,
            isset($draft['recipient_phone']) ? '📞 '.PhoneNumber::display($draft['recipient_phone']) : null,
            isset($draft['zone_id']) ? '📍 '.$zones->firstWhere('id', $draft['zone_id'])?->fullName() : null,
            isset($draft['items_amount']) ? '💰 '.Money::format((int) $draft['items_amount']) : null,
        ]);

        return $known === [] ? '' : 'Noté : '.implode(' · ', $known)."\n\n";
    }

    private function command(string $text): ?string
    {
        $t = ZoneMatcher::normalize($text);

        return match (true) {
            in_array($t, ['menu', 'aide', 'help', 'bonjour', 'bonsoir', 'salut', 'hello', 'start', 'accueil', '0'], true) => 'menu',
            in_array($t, ['annuler', 'annule', 'stop', 'cancel'], true) => 'cancel',
            in_array($t, ['nouvelle course', 'nouvelle', 'course', 'nouveau', '1'], true) => 'new',
            in_array($t, ['point', 'point du jour', 'mon point', '2'], true) => 'point',
            in_array($t, ['suivi', 'suivre', 'suivre un colis', '3'], true) => 'track',
            $this->session->state === 'confirm' && in_array($t, ['oui', 'ok', 'confirmer', 'confirme', 'valider', 'valide', 'd accord', 'dac'], true) => 'confirm',
            $this->session->state === 'confirm' && in_array($t, ['modifier', 'non', 'corriger'], true) => 'edit',
            default => null,
        };
    }

    /**
     * Marchand de l'entreprise reconnu par son numéro WhatsApp, son numéro principal
     * ou le numéro d'un de ses comptes utilisateurs.
     *
     * @return array{0: ?Merchant, 1: ?User}
     */
    private function identify(): array
    {
        $user = User::forCompany($this->company->id)->where('phone', $this->phone)->whereNotNull('merchant_id')
            ->where('status', 'active')->first();

        $merchant = $user
            ? Merchant::forCompany($this->company->id)->find($user->merchant_id)
            : Merchant::forCompany($this->company->id)
                ->where(fn ($q) => $q->where('whatsapp_phone', $this->phone)->orWhere('phone', $this->phone))
                ->first();

        return [$merchant?->isActive() ? $merchant : null, $user];
    }

    private function actor(Merchant $merchant): ?User
    {
        return $this->session->user
            ?? User::forCompany($this->company->id)->where('merchant_id', $merchant->id)->where('status', 'active')->orderBy('id')->first();
    }

    /**
     * @return Collection<int, Zone>
     */
    private function zones(): Collection
    {
        return Zone::forCompany($this->company->id)->active()->with('parent')->get();
    }

    /**
     * @param  array<string, string>  $buttons
     */
    private function reply(string $text, array $buttons = []): void
    {
        $this->messenger->reply($this->company->id, $this->phone, $text, $buttons, [
            'merchant_id' => $this->inbound?->merchant_id,
        ]);
    }

    private function done(): InboundMessage
    {
        $this->inbound->update(['processed_at' => now()]);

        return $this->inbound;
    }
}
