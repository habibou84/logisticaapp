<?php

namespace App\Services\WhatsApp;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\RescheduleRequest;
use App\Services\Orders\Reschedules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Réponse WhatsApp de le client à une demande de nouvelle date (colis pas ramassés) :
 * « tout demain », « 1 3 demain et 2 lundi », « tout le 9/11 », « 3 annuler 2 » (motif), puis récapitulatif, Oui ou
 * Corriger. Ne prend la main que si une demande est ouverte et que le message est une réponse reconnue (ou une
 * réponse attendue juste après une question de cette conversation).
 */
class RescheduleConversation
{
    private const STATE_MINUTES = 60;

    private const FILLER = ['le', 'la', 'les', 'l', 'colis', 'numero', 'numeros', 'num', 'n', 'no', 'pour', 'au', 'a', 'de', 'du', 'd',
        'livrer', 'livraison', 'livre', 'reporter', 'reporte', 'reportez', 'report', 'svp', 'stp', 'merci', 'je', 'veux', 'on', 'ok',
        'ils', 'sont', 'mettre', 'mets', 'mettez', 'et', 'ou', 'plutot', 'prochain', 'prochaine', 'ce', 'cette', 'semaine', 'jour', 'moi'];

    private const WEEKDAYS = ['lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7];

    private const KEYWORDS = ['reporter', 'report', 'reports', 'a reporter', 'colis a reporter', 'nouvelle date'];

    /** Motifs d'annulation numérotés 1 à 5 */
    private const REASONS = ['customer_cancelled', 'out_of_stock', 'order_mistake', 'self_delivery', 'other'];

    public function __construct(private readonly Reschedules $reschedules) {}

    /**
     * @return array{0: string, 1: array<string, string>}|null réponse (texte, boutons), ou null si le message n'est pas pour nous
     */
    public function handle(Company $company, Merchant $merchant, string $phone, ?string $text, ?string $button): ?array
    {
        $key = 'reschedule-chat:'.$company->id.':'.preg_replace('/\D/', '', $phone);
        $state = Cache::get($key);
        $text = trim((string) $text);

        if ($button !== null && ! str_starts_with($button, 'rs:')) {
            return null;
        }

        $request = $this->openRequest($company, $merchant, $state['request_id'] ?? null);
        if ($request === null) {
            Cache::forget($key);

            return $button !== null ? ['Ces colis ont déjà une nouvelle date. Merci 🙏', []] : null;
        }
        $pending = $this->reschedules->pendingOrders($request);
        $normalized = self::normalize($text);

        // Boutons et mots de la conversation
        $command = $button ?? match (true) {
            ($state['step'] ?? null) === 'confirm' && in_array($normalized, ['oui', 'o', 'ok', 'valider', 'confirmer', 'oui c est bon', 'c est bon'], true) => 'rs:confirm',
            $state !== null && in_array($normalized, ['corriger', 'non', 'modifier'], true) => 'rs:fix',
            in_array($normalized, self::KEYWORDS, true) => 'rs:menu',
            default => null,
        };

        $reply = match ($command) {
            'rs:menu', 'rs:fix' => $this->menu($request, $pending),
            'rs:all_tomorrow' => $this->recap($key, $request, $pending, array_fill_keys($pending->keys()->all(), ['date' => $this->day($company, 1)])),
            'rs:choose' => $this->choose($pending),
            'rs:cancel' => $this->askCancel($pending),
            'rs:confirm' => $this->confirm($key, $company, $request, $pending, $state),
            default => null,
        };
        if ($reply !== null) {
            $step = ['rs:menu' => 'menu', 'rs:fix' => 'menu', 'rs:choose' => 'choose', 'rs:cancel' => 'cancel'][$command] ?? null;
            if ($step !== null) {
                Cache::put($key, ['request_id' => $request->id, 'step' => $step, 'plan' => null], now()->addMinutes(self::STATE_MINUTES));
            }

            return $reply;
        }
        if ($text === '') {
            return null;
        }

        // Motif d'annulation attendu
        if (($state['step'] ?? null) === 'reason' && preg_match('/^\s*([1-5])\s*$/', $text, $m)) {
            $plan = $state['plan'];
            foreach ($plan as $n => $choice) {
                if (($choice['cancel'] ?? null) === '?') {
                    $plan[$n]['cancel'] = self::REASONS[(int) $m[1] - 1];
                }
            }

            return $this->recap($key, $request, $pending, $plan);
        }

        try {
            $plan = $this->parse($company, $normalized, $pending->keys()->all());
        } catch (BusinessRuleException $e) {
            return [$e->getMessage(), []];
        }
        if ($plan === null) {
            return $state !== null && in_array($state['step'], ['choose', 'cancel', 'reason'], true)
                ? ["Je n'ai pas compris 🙂 Écrivez les numéros puis le jour, par exemple « 1 3 demain et 2 lundi », « tout le 9/11 » ou « 3 annuler ».", ['rs:menu' => 'Voir la liste']]
                : null;
        }

        if (collect($plan)->contains(fn ($c) => ($c['cancel'] ?? null) === '?')) {
            Cache::put($key, ['request_id' => $request->id, 'step' => 'reason', 'plan' => $plan], now()->addMinutes(self::STATE_MINUTES));

            return ["Pourquoi annuler ? Répondez par le numéro du motif :\n".$this->reasons(), []];
        }

        return $this->recap($key, $request, $pending, $plan);
    }

    private function openRequest(Company $company, Merchant $merchant, ?int $id): ?RescheduleRequest
    {
        $query = RescheduleRequest::withoutGlobalScopes()->where('company_id', $company->id)->where('merchant_id', $merchant->id)->open();
        $request = $id ? (clone $query)->find($id) : null;
        $request ??= $query->latest('id')->first();

        return $request !== null && $this->reschedules->pendingOrders($request)->isNotEmpty() ? $request : null;
    }

    private function menu(RescheduleRequest $request, $pending): array
    {
        return ["📅 {$pending->count()} colis attendent une nouvelle date de livraison :\n".$this->list($pending)
            ."\n\nRépondez par exemple « tout demain », « 1 3 demain et 2 lundi » ou « 3 annuler ». Ou choisissez sur la page : ".Reschedules::link($request),
            ['rs:all_tomorrow' => 'Tout demain', 'rs:choose' => 'Choisir', 'rs:cancel' => 'Annuler des colis']];
    }

    private function choose($pending): array
    {
        return ["Écrivez les numéros puis le jour :\n".$this->list($pending)
            ."\n\nExemples : « 1 3 demain et 2 lundi », « tout le 9/11 », « 2 après-demain ».", []];
    }

    private function askCancel($pending): array
    {
        return ["Quels colis annuler ? Écrivez les numéros puis « annuler », par exemple « 3 annuler ».\n".$this->list($pending), []];
    }

    private function list($pending): string
    {
        return $pending->map(fn (Order $o, int $n) => "{$n}) {$o->tracking_code} ".trim(($o->recipient_name ?? '').($o->deliveryZone ? " ({$o->deliveryZone->name})" : '')))->implode("\n");
    }

    private function reasons(): string
    {
        return collect(self::REASONS)->map(fn ($code, $i) => ($i + 1).'. '.Reschedules::CANCEL_REASONS[$code])->implode("\n");
    }

    /** Récapitulatif à confirmer */
    private function recap(string $key, RescheduleRequest $request, $pending, array $plan): array
    {
        $lines = [];
        foreach ($plan as $n => $choice) {
            $order = $pending[$n] ?? null;
            if ($order === null) {
                continue;
            }
            $lines[] = "{$n}) {$order->tracking_code} → ".(isset($choice['cancel'])
                ? 'annulé ('.mb_strtolower(Reschedules::CANCEL_REASONS[$choice['cancel']] ?? 'motif').')'
                : Reschedules::shortDay($choice['date']));
        }
        $left = $pending->count() - count($lines);
        Cache::put($key, ['request_id' => $request->id, 'step' => 'confirm', 'plan' => $plan], now()->addMinutes(self::STATE_MINUTES));

        return ["Je récapitule :\n".implode("\n", $lines).($left > 0 ? "\n\n{$left} autre(s) colis restent sans date pour le moment." : '')."\n\nC'est bon ?",
            ['rs:confirm' => 'Oui', 'rs:fix' => 'Corriger']];
    }

    private function confirm(string $key, Company $company, RescheduleRequest $request, $pending, ?array $state): array
    {
        if (($state['step'] ?? null) !== 'confirm' || empty($state['plan'])) {
            return $this->menu($request, $pending);
        }
        $plan = [];
        foreach ($state['plan'] as $n => $choice) {
            if (isset($pending[$n])) {
                $plan[$pending[$n]->id] = $choice;
            }
        }

        try {
            $result = $this->reschedules->answer($request, $plan, 'whatsapp');
        } catch (BusinessRuleException $e) {
            return [$e->getMessage(), ['rs:menu' => 'Voir la liste']];
        }
        Cache::forget($key);

        $parts = [];
        if ($result['rescheduled'] !== []) {
            $parts[] = '✅ Nouvelle date enregistrée : '.collect($result['rescheduled'])->map(fn ($r) => "{$r['code']} le ".Reschedules::shortDay($r['date']))->implode(' ; ').'.';
        }
        if ($result['cancelled'] !== []) {
            $parts[] = '❌ Annulé : '.implode(', ', $result['cancelled']).'.';
        }
        if ($result['skipped'] !== []) {
            $parts[] = 'ℹ️ Pas changé : '.collect($result['skipped'])->map(fn ($s) => "{$s['code']} ({$s['why']})")->implode(', ').'.';
        }
        $left = $this->reschedules->pendingOrders($request->fresh())->count();
        if ($left > 0) {
            $parts[] = "Il reste {$left} colis sans date. Écrivez « reporter » pour les voir.";
        }

        return [implode("\n", $parts) ?: 'C\'est noté. Merci 🙏', []];
    }

    private function day(Company $company, int $days): string
    {
        return CarbonImmutable::parse(Reschedules::today($company))->addDays($days)->toDateString();
    }

    public static function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $text = str_replace(['’', "'", '-', ',', ';', '+', '.', '!', ':'], [' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' '], $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Lit « 1 3 demain et 2 lundi », « tout le 9/11 », « 3 annuler 2 », « annuler 4 ».
     *
     * @param  list<int>  $numbers  numéros de la demande
     * @return array<int, array{date?: string, cancel?: string}>|null numéro => choix (cancel « ? » : motif à demander)
     */
    public function parse(Company $company, string $normalized, array $numbers): ?array
    {
        $today = CarbonImmutable::parse(Reschedules::today($company));
        $tokens = $normalized === '' ? [] : explode(' ', $normalized);
        // Mots composés
        $joined = ' '.implode(' ', $tokens).' ';
        $joined = str_replace([' aujourd hui ', ' apres demain ', ' ce soir '], [' aujourdhui ', ' apresdemain ', ' aujourdhui '], $joined);
        $tokens = array_values(array_filter(explode(' ', trim($joined)), fn ($t) => $t !== ''));

        $plan = [];
        $current = [];
        $cancelMode = false;
        $seenAction = false;

        for ($i = 0; $i < count($tokens); $i++) {
            $t = $tokens[$i];

            if (in_array($t, ['tout', 'tous', 'toutes', 'tt'], true)) {
                $current = $numbers;

                continue;
            }
            if (preg_match('/^\d{1,2}$/', $t) && ! (isset($tokens[$i + 1]) && preg_match('/^(\/|janv|fev|mars|avr|mai|juin|juil|aout|sept|oct|nov|dec)/', $tokens[$i + 1])) && ! (($tokens[$i - 1] ?? null) === 'le' && $current !== [])) {
                $current[] = (int) $t;

                continue;
            }

            $date = $this->dateToken($today, $tokens, $i);
            if ($date !== null) {
                if ($current === [] || $cancelMode) {
                    return null;
                }
                foreach ($current as $n) {
                    $plan[$n] = ['date' => $date];
                }
                $current = [];
                $seenAction = true;

                continue;
            }

            if (in_array($t, ['annuler', 'annule', 'annulez', 'annulee', 'annulees', 'annules', 'annulation', 'supprimer'], true)) {
                if ($current === []) {
                    $cancelMode = true;

                    continue;
                }
                $reason = '?';
                $next = $tokens[$i + 1] ?? null;
                if ($next === 'motif' && preg_match('/^[1-5]$/', $tokens[$i + 2] ?? '')) {
                    $reason = self::REASONS[(int) $tokens[$i + 2] - 1];
                    $i += 2;
                } elseif ($next !== null && preg_match('/^[1-5]$/', $next) && ! isset($tokens[$i + 2])) {
                    $reason = self::REASONS[(int) $next - 1];
                    $i++;
                }
                foreach ($current as $n) {
                    $plan[$n] = ['cancel' => $reason];
                }
                $current = [];
                $seenAction = true;

                continue;
            }

            if ($t === 'motif' && $cancelMode && preg_match('/^[1-5]$/', $tokens[$i + 1] ?? '') && $current !== []) {
                $reason = self::REASONS[(int) $tokens[$i + 1] - 1];
                foreach ($current as $n) {
                    $plan[$n] = ['cancel' => $reason];
                }
                $current = [];
                $cancelMode = false;
                $seenAction = true;
                $i++;

                continue;
            }

            if (in_array($t, self::FILLER, true)) {
                continue;
            }

            return null;
        }

        if ($cancelMode && $current !== []) {
            foreach ($current as $n) {
                $plan[$n] = ['cancel' => '?'];
            }
            $current = [];
            $seenAction = true;
        }
        if (! $seenAction || $current !== [] || $cancelMode && $plan === []) {
            return null;
        }

        $unknown = array_diff(array_keys($plan), $numbers);
        if ($unknown !== []) {
            throw new BusinessRuleException('Le numéro '.implode(', ', $unknown).' n\'est pas dans la liste (de 1 à '.max($numbers).').');
        }
        $max = Reschedules::maxDate($company);
        foreach ($plan as $choice) {
            if (isset($choice['date']) && $choice['date'] > $max) {
                throw new BusinessRuleException('Choisissez une date avant le '.Reschedules::shortDay($max).'.');
            }
        }
        ksort($plan);

        return $plan;
    }

    /** Date lue à la position $i (avance $i si elle tient sur plusieurs mots) */
    private function dateToken(CarbonImmutable $today, array $tokens, int &$i): ?string
    {
        $t = $tokens[$i];
        $months = ['janv' => 1, 'janvier' => 1, 'fev' => 2, 'fevrier' => 2, 'mars' => 3, 'avr' => 4, 'avril' => 4, 'mai' => 5, 'juin' => 6,
            'juil' => 7, 'juillet' => 7, 'aout' => 8, 'sept' => 9, 'septembre' => 9, 'oct' => 10, 'octobre' => 10, 'nov' => 11, 'novembre' => 11, 'dec' => 12, 'decembre' => 12];

        if (in_array($t, ['aujourdhui', 'auj', 'ajd'], true)) {
            return $today->toDateString();
        }
        if (in_array($t, ['demain', 'dmain', 'dem'], true)) {
            return $today->addDay()->toDateString();
        }
        if ($t === 'apresdemain') {
            return $today->addDays(2)->toDateString();
        }
        if (isset(self::WEEKDAYS[$t])) {
            $days = (self::WEEKDAYS[$t] - $today->dayOfWeekIso + 7) % 7 ?: 7;

            return $today->addDays($days)->toDateString();
        }
        // 9/11, 9/11/2026
        if (preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?$#', $t, $m)) {
            return $this->fromParts($today, (int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : null);
        }
        // 9 nov, 9 novembre
        if (preg_match('/^\d{1,2}$/', $t) && isset($tokens[$i + 1], $months[$tokens[$i + 1]])) {
            $date = $this->fromParts($today, (int) $t, $months[$tokens[$i + 1]], null);
            $i++;

            return $date;
        }
        // « le 9 » (après des numéros de colis)
        if (preg_match('/^\d{1,2}$/', $t) && ($tokens[$i - 1] ?? null) === 'le') {
            $day = (int) $t;
            $date = $today->day($day);
            if ($day < 1 || $day > 31 || $date->day !== $day) {
                return null;
            }

            return ($date->lt($today) ? $today->addMonthNoOverflow()->day($day) : $date)->toDateString();
        }

        return null;
    }

    private function fromParts(CarbonImmutable $today, int $day, int $month, ?int $year): ?string
    {
        $given = $year !== null;
        $year = $year === null ? $today->year : ($year < 100 ? 2000 + $year : $year);
        if (! checkdate($month, $day, $year)) {
            return null;
        }
        $date = CarbonImmutable::create($year, $month, $day);
        if ($date->lt($today) && ! $given) {
            $date = $date->addYear();
        }

        return $date->toDateString();
    }
}
