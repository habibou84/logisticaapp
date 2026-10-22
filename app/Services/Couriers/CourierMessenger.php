<?php

namespace App\Services\Couriers;

use App\Enums\OrderEventType;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\CourierMessage;
use App\Models\FieldReportReview;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderJournal;
use Illuminate\Support\Facades\DB;

/**
 * Consignes du dispatch aux livreurs : enregistrées, tracées au journal (note interne)
 * et poussées en direct sur le téléphone du livreur.
 */
class CourierMessenger
{
    public function __construct(private readonly OrderJournal $journal) {}

    public function send(User $sender, Order $order, Courier $courier, string $body, ?OrderEvent $replyTo = null, bool $markHandled = false): CourierMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw new BusinessRuleException('Écrivez la consigne.', 'body');
        }

        $assignment = $order->assignments()->where('courier_id', $courier->id)->latest('id')->first();

        if ($assignment === null) {
            throw new BusinessRuleException('Ce livreur n\'a pas de mission sur cette course.', 'courier_id');
        }

        return DB::transaction(function () use ($sender, $order, $courier, $body, $replyTo, $markHandled, $assignment) {
            $message = CourierMessage::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'courier_id' => $courier->id,
                'sender_id' => $sender->id,
                'reply_to_event_id' => $replyTo?->id,
                'body' => mb_substr($body, 0, 500),
            ]);

            $courier->loadMissing('user');
            $this->journal->record($order, $sender, OrderEventType::Note, [
                'note' => "Consigne à {$courier->user?->name} : {$message->body}",
                'visible_to_merchant' => false,
                'meta' => ['courier_message_id' => $message->id, 'courier_id' => $courier->id],
            ]);

            if ($replyTo && $markHandled) {
                FieldReportReview::updateOrCreate(['order_event_id' => $replyTo->id], [
                    'company_id' => $order->company_id,
                    'handled_by' => $sender->id,
                    'comment' => 'Consigne envoyée au livreur : '.mb_substr($message->body, 0, 400),
                    'handled_at' => now(),
                ]);
            }

            DB::afterCommit(function () use ($order, $courier, $message, $sender, $assignment) {
                if ($courier->user) {
                    $courier->user->notify(new OrderAlert($order, 'dispatch_message', "💬 {$sender->name} · {$order->tracking_code}", $message->body, [
                        'dispatch_message' => true,
                        'message_id' => $message->id,
                        'assignment_id' => $assignment->id,
                    ]));
                }
            });

            return $message;
        });
    }
}
