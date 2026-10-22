<?php

namespace App\Http\Resources\V1;

use App\Models\OutboundMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OutboundMessage
 */
class OutboundMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'channel_label' => $this->channel->label(),
            'to' => $this->to,
            'recipient_type' => $this->recipient_type,
            'event' => $this->event,
            'template_name' => $this->template_name,
            'body' => $this->body,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'error' => $this->error,
            'attempts' => $this->attempts,
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'tracking_code' => $this->order->tracking_code,
            ] : null),
            'merchant' => $this->whenLoaded('merchant', fn () => $this->merchant ? [
                'id' => $this->merchant->id,
                'business_name' => $this->merchant->business_name,
            ] : null),
            'fallback_for_id' => $this->fallback_for_id,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'read_at' => $this->read_at,
            'failed_at' => $this->failed_at,
            'created_at' => $this->created_at,
        ];
    }
}
