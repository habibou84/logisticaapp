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
 * - receipt_label : accusé de réception (nom, prénoms, fonction, date, signature, cachet) imprimé sur l'étiquette ;
 *   sa photo, signé et tamponné, est alors demandée au livreur à la remise ;
 * - notify_recipient : messages WhatsApp au destinataire (en route, livrée…) ;
 * - mad_exclusive : livreurs en MAD réservés au client (pas de courses d'autres clients) ;
 * - mad_days (1 = lundi … 7 = dimanche), mad_from, mad_to (« 08:00 ») : quand ils le sont ;
 * - quantity_billing : course de plusieurs plis ou chèques facturée par pli (per_item) ou une fois (per_course).
 */
class ActivityOptions
{
    public const KEYS = ['proof_name', 'proof_signature', 'proof_photo', 'receipt_label', 'notify_recipient', 'mad_exclusive', 'mad_days', 'mad_from', 'mad_to', 'quantity_billing'];

    public const DEFAULTS = [
        'proof_name' => true,
        'proof_signature' => false,
        'proof_photo' => false,
        'receipt_label' => true,
        'notify_recipient' => true,
        'mad_exclusive' => true,
        'mad_days' => [1, 2, 3, 4, 5, 6, 7],
        'mad_from' => null,
        'mad_to' => null,
        'quantity_billing' => 'per_item',
    ];

    public const QUANTITY_BILLING = ['per_item', 'per_course'];

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
        // MAD : pas d'accusé sur l'étiquette par défaut (le livreur est chez le client)
        $defaults = $activity === Activity::Mad ? [...self::DEFAULTS, 'receipt_label' => false] : self::DEFAULTS;

        return $this->merge($defaults, is_array($saved) ? $saved : []);
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

    /**
     * Preuve de remise demandée au livreur pour une course : l'accusé imprimé sur l'étiquette demande sa photo.
     *
     * @return array{proof_name: bool, proof_signature: bool, proof_photo: bool, receipt_label: bool}|null
     */
    public function proof(Order $order): ?array
    {
        $options = $this->order($order);
        if ($options === null) {
            return null;
        }

        return [
            'proof_name' => (bool) $options['proof_name'],
            'proof_signature' => (bool) $options['proof_signature'],
            'proof_photo' => (bool) ($options['proof_photo'] || $options['receipt_label']),
            'receipt_label' => (bool) $options['receipt_label'],
        ];
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
                'quantity_billing' => in_array($value, self::QUANTITY_BILLING, true) ? $value : null,
                default => (bool) $value,
            };
        }
        $out = array_filter($out, fn ($v) => $v !== null);
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
            "{$prefix}.receipt_label" => ['nullable', 'boolean'],
            "{$prefix}.notify_recipient" => ['nullable', 'boolean'],
            "{$prefix}.mad_exclusive" => ['nullable', 'boolean'],
            "{$prefix}.mad_days" => ['nullable', 'array'],
            "{$prefix}.mad_days.*" => ['integer', 'between:1,7'],
            "{$prefix}.mad_from" => ['nullable', 'date_format:H:i', "required_with:{$prefix}.mad_to"],
            "{$prefix}.mad_to" => ['nullable', 'date_format:H:i', "required_with:{$prefix}.mad_from"],
            "{$prefix}.quantity_billing" => ['nullable', 'in:'.implode(',', self::QUANTITY_BILLING)],
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
