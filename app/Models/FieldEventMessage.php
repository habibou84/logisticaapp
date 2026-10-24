<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Échange entre le livreur et l'équipe sur un événement terrain.
 */
class FieldEventMessage extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'field_event_id', 'sender_id', 'from_courier', 'body', 'read_at'];

    protected function casts(): array
    {
        return ['from_courier' => 'boolean', 'read_at' => 'datetime'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'from_courier' => $this->from_courier,
            'sender' => $this->relationLoaded('sender') ? $this->sender?->name : null,
            'created_at' => $this->created_at,
            'read_at' => $this->read_at,
        ];
    }
}
