<?php

namespace App\Services\Orders;

use App\Enums\Activity;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Order;
use Carbon\CarbonInterface;

/**
 * Réglages de la distribution et de la MAD, au choix de l'administrateur : ceux de l'entreprise
 * (Réglages › Courses), que chaque contrat peut changer (valeur vide : comme l'entreprise).
 *
 * - proof_name, proof_signature, proof_photo : preuve de remise demandée au livreur ;
 * - notify_recipient : messages WhatsApp au destinataire (en route, livrée…) ;
 * - mad_exclusive : livreurs en MAD réservés au client (pas de courses d'autres clients) ;
 * - mad_days (1 = lundi … 7 = dimanche), mad_from, mad_to (« 08:00 ») : quand ils le sont.
 */
class ActivityOptions
{
    public const KEYS = ['proof_name', 'proof_signature', 'proof_photo', 'notify_recipient', 'mad_exclusive', 'mad_days', 'mad_from', 'mad_to'];

    public const DEFAULTS = [
        'proof_name' => true,
        'proof_signature' => false,
        'proof_photo' => false,
        'notify_recipient' => true,
        'mad_exclusive' => true,
        'mad_days' => [1, 2, 3, 4, 5, 6, 7],
        'mad_from' => null,
        'mad_to' => null,
    ];

    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    /** Activités qui ont ces réglages (l'e-commerce garde son fonctionnement) */
    public const ACTIVITIES = [Activity::Distribution, Activity::Mad];

    /**
     * Réglages de l'entreprise pour une activité, valeurs par défaut comprises.
     *
     * @return array<string, mixed>
     */
    public function company(?Company $company, Activity $activity): array
    {
        $saved = $company?->activity_options[$activity->value] ?? [];

        return $this->merge(self::DEFAULTS, is_array($saved) ? $saved : []);
    }

    /**
     * Réglages d'un contrat : ceux de l'entreprise, changés par le contrat.
     *
     * @return array<string, mixed>
     */
    public function plan(BillingPlan $plan, ?Company $company = null): array
    {
        $company ??= Company::query()->find($plan->company_id);

        return $this->merge($this->company($company, $plan->activity ?? $plan->formula->activity()), $plan->options ?? []);
    }

    /**
     * Réglages qui s'appliquent à une course (null : e-commerce, rien de particulier).
     *
     * @return array<string, mixed>|null
     */
    public function order(Order $order): ?array
    {
        if (! in_array($order->activity, self::ACTIVITIES, true)) {
            return null;
        }
        // Gardé le temps d'une requête : une liste de courses ne relit pas les contrats pour chacune
        $key = $order->company_id.'|'.$order->activity->value.'|'.($order->billing_plan_id ?? 0);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $company = $order->relationLoaded('company') ? $order->company : Company::query()->find($order->company_id);
        $plan = $order->billing_plan_id ? BillingPlan::withoutGlobalScopes()->find($order->billing_plan_id) : null;

        return $this->memo[$key] = $plan ? $this->plan($plan, $company) : $this->company($company, $order->activity);
    }

    /** Réglages changés (entreprise, contrat) : relus à la prochaine course */
    public function forget(): void
    {
        $this->memo = [];
    }

    /**
     * Le livreur d'un contrat MAD est-il réservé au client à ce moment (jour et horaire du contrat) ?
     *
     * @param  array<string, mixed>  $options  réglages du contrat
     */
    public function reservedAt(array $options, CarbonInterface $at): bool
    {
        if (! in_array($at->dayOfWeekIso, array_map('intval', $options['mad_days'] ?? []), true)) {
            return false;
        }
        $from = $options['mad_from'] ?? null;
        $to = $options['mad_to'] ?? null;
        if ($from && $to) {
            $time = $at->format('H:i');

            return $from <= $to ? ($time >= $from && $time <= $to) : ($time >= $from || $time <= $to);
        }

        return true;
    }

    /**
     * Valeurs enregistrées, nettoyées (clés connues, types justes ; null : comme le niveau au-dessus).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
                continue;
            }
            $value = $input[$key];
            $out[$key] = match ($key) {
                'mad_days' => array_values(array_unique(array_map('intval', array_filter((array) $value, fn ($d) => (int) $d >= 1 && (int) $d <= 7)))),
                'mad_from', 'mad_to' => (string) $value,
                default => (bool) $value,
            };
        }
        if (isset($out['mad_days'])) {
            sort($out['mad_days']);
        }

        return $out;
    }

    /**
     * Règles de validation des réglages (entreprise ou contrat).
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix): array
    {
        return [
            $prefix => ['sometimes', 'nullable', 'array'],
            "{$prefix}.proof_name" => ['nullable', 'boolean'],
            "{$prefix}.proof_signature" => ['nullable', 'boolean'],
            "{$prefix}.proof_photo" => ['nullable', 'boolean'],
            "{$prefix}.notify_recipient" => ['nullable', 'boolean'],
            "{$prefix}.mad_exclusive" => ['nullable', 'boolean'],
            "{$prefix}.mad_days" => ['nullable', 'array'],
            "{$prefix}.mad_days.*" => ['integer', 'between:1,7'],
            "{$prefix}.mad_from" => ['nullable', 'date_format:H:i', "required_with:{$prefix}.mad_to"],
            "{$prefix}.mad_to" => ['nullable', 'date_format:H:i', "required_with:{$prefix}.mad_from"],
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function merge(array $base, array $over): array
    {
        foreach (self::clean($over) as $key => $value) {
            $base[$key] = $value;
        }

        return $base;
    }
}
