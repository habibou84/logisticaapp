<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consigne du dispatch à un livreur sur une course, avec accusés « lu » et « compris ».
 */
class CourierMessage extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'order_id', 'courier_id', 'sender_id', 'reply_to_event_id', 'body'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(OrderEvent::class, 'reply_to_event_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'courier_id' => $this->courier_id,
            'reply_to_event_id' => $this->reply_to_event_id,
            'body' => $this->body,
            'sender' => $this->relationLoaded('sender') ? $this->sender?->name : null,
            'created_at' => $this->created_at,
            'read_at' => $this->read_at,
            'acknowledged_at' => $this->acknowledged_at,
        ];
    }
}
