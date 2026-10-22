<?php

namespace App\Http\Resources\V1;

use App\Models\OrderExpense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderExpense
 */
class OrderExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->type->label(),
            'label' => $this->label,
            'description' => $this->description(),
            'amount' => $this->amount,
            'paid_by' => $this->paid_by,
            'billed_to' => $this->billed_to,
            'courier_name' => $this->when($request->user()?->merchant_id === null, fn () => $this->relationLoaded('courier') ? $this->courier?->user?->name : null),
            'refunded' => $this->remittance_id !== null,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
