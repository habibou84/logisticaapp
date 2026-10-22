<?php

namespace App\Http\Resources\V1;

use App\Models\IncidentReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IncidentReason
 */
class IncidentReasonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'label' => $this->label,
            'applies_to' => $this->applies_to,
            'requires_date' => $this->requires_date,
            'counts_as_attempt' => $this->counts_as_attempt,
            'triggers_return' => $this->triggers_return,
        ];
    }
}
