<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\User;

/**
 * Annulations : motif obligatoire (liste du client ou de l'équipe, « Autre » demande une précision), et qui a
 * annulé. La course garde le code du motif, la précision, le côté (client ou équipe) et la personne.
 */
class Cancellations
{
    /** Motifs de l'équipe (fiche course, refus, courses non traitées) */
    public const REASONS_COMPANY = [
        'client_request' => 'Demande du client',
        'recipient_unreachable' => 'Destinataire injoignable',
        'out_of_zone' => 'Adresse hors zone',
        'duplicate' => 'Course en double',
        'not_treated' => 'Course non traitée',
        'other' => 'Autre',
    ];

    /** @return array<string, string> */
    public static function reasons(bool $merchant): array
    {
        return $merchant ? Reschedules::CANCEL_REASONS : self::REASONS_COMPANY;
    }

    public static function label(?string $code, ?string $type): ?string
    {
        // Motif du client saisi par l'équipe à sa place (nouvelle date d'un colis pas ramassé) : les deux listes
        return $code === null ? null : (self::reasons($type === 'merchant')[$code] ?? Reschedules::CANCEL_REASONS[$code] ?? self::REASONS_COMPANY[$code] ?? $code);
    }

    /**
     * Motif vérifié : un code de la liste de la personne ; « Autre » demande une précision. Un texte seul vaut
     * « Autre ».
     *
     * @return array{cancel_reason_code: string, cancel_reason: ?string, note: string}
     */
    public static function context(User $actor, ?string $code, ?string $note, ?bool $merchant = null): array
    {
        $merchant ??= $actor->merchant_id !== null;
        $note = filled($note) ? trim((string) $note) : null;
        $code = filled($code) ? $code : ($note !== null ? 'other' : null);

        if ($code === null) {
            throw new BusinessRuleException('Indiquez pourquoi vous annulez la course.', 'cancel_reason_code');
        }
        if (! array_key_exists($code, self::reasons($merchant))) {
            throw new BusinessRuleException('Motif d\'annulation inconnu.', 'cancel_reason_code');
        }
        if ($code === 'other' && $note === null) {
            throw new BusinessRuleException('Précisez le motif de l\'annulation.', 'cancel_reason');
        }
        $label = self::reasons($merchant)[$code];

        return [
            'cancel_reason_code' => $code,
            'cancel_reason' => $note,
            // Journal de la course : « Motif · précision »
            'note' => $note !== null && $code !== 'other' ? "{$label} · {$note}" : ($note ?? $label),
        ];
    }

    /**
     * Annulation ou refus demandé par une personne (fiche course, application du client) : motif vérifié, puis
     * fait. Sans le droit d'annuler ou sur une course qui ne s'annule plus : l'erreur habituelle du workflow.
     *
     * @param  array<string, mixed>  $context
     */
    public static function cancel(OrderWorkflow $workflow, User $actor, Order $order, OrderStatus $to, array $context): Order
    {
        $mayCancel = $actor->merchant_id !== null
            ? $actor->merchant_id === $order->merchant_id && $actor->can(Permission::OrdersCreate->value)
            : $actor->can(($to === OrderStatus::Cancelled ? Permission::OrdersCancel : Permission::OrdersDispatch)->value);
        if ($mayCancel && $order->status->canTransitionTo($to)) {
            $context = [...$context, ...self::context($actor, $context['cancel_reason_code'] ?? null, $context['cancel_reason'] ?? $context['note'] ?? null)];
        }

        return $workflow->transition($actor, $order, $to, $context);
    }

    /** « par Koné (Dispatch) » : la personne de l'équipe qui a annulé, pour le message au client */
    public static function byWhom(Order $order): string
    {
        $c = self::describe($order);

        return $c === null || $c['by'] !== 'company' || $c['name'] === null ? '' : " par {$c['name']}".($c['role'] ? " ({$c['role']})" : '');
    }

    /**
     * Qui a annulé ou refusé la course, et pourquoi.
     *
     * @return array<string, mixed>|null
     */
    public static function describe(Order $order): ?array
    {
        if (! in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Rejected], true) || $order->cancelled_at === null) {
            return null;
        }
        $type = $order->cancelled_by_type ?? 'company';
        $user = $order->cancelled_by_id ? $order->cancelledBy()->withoutGlobalScopes()->first() : null;
        $code = $order->cancel_reason_code;

        return [
            'by' => $type,
            'name' => $user ? (strtok($user->name, ' ') ?: $user->name) : null,
            'role' => $type === 'company' ? $user?->roleLabel() : null,
            'reason' => $code,
            'reason_label' => self::label($code, $type),
            'note' => $order->cancel_reason,
            'rejected' => $order->status === OrderStatus::Rejected,
            'cancelled_at' => $order->cancelled_at->toIso8601String(),
        ];
    }
}
