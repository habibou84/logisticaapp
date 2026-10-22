<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationEvent;
use App\Enums\ReportFrequency;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Rules\PhoneNumber;
use App\Services\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Messages reçus par un marchand (notification sur son téléphone ou WhatsApp) :
 * événements et points d'activité programmés.
 */
class MerchantNotificationController extends Controller
{
    public function show(Merchant $merchant): JsonResponse
    {
        Gate::authorize('manageNotifications', $merchant);

        return response()->json(['data' => $this->settings($merchant)]);
    }

    public function update(Request $request, Merchant $merchant): JsonResponse
    {
        Gate::authorize('manageNotifications', $merchant);

        $data = $request->validate([
            'whatsapp_phone' => ['sometimes', 'nullable', 'string', new PhoneNumber],
            'events' => ['sometimes', 'array'],
            'events.*' => ['boolean'],
            'reports' => ['sometimes', 'array'],
            'reports.*.active' => ['required', 'boolean'],
            'reports.*.send_time' => ['required', 'date_format:H:i'],
            'reports.*.weekday' => ['nullable', 'integer', 'between:1,7'],
        ]);

        $unknownEvents = array_diff(array_keys($data['events'] ?? []), array_column(NotificationEvent::cases(), 'value'));
        $unknownReports = array_diff(array_keys($data['reports'] ?? []), array_column(ReportFrequency::cases(), 'value'));
        abort_if($unknownEvents || $unknownReports, 422, 'Réglage inconnu.');

        DB::transaction(function () use ($merchant, $data) {
            if (array_key_exists('whatsapp_phone', $data)) {
                $merchant->update(['whatsapp_phone' => $data['whatsapp_phone']]);
            }

            foreach ($data['events'] ?? [] as $event => $enabled) {
                $merchant->notificationPreferences()->updateOrCreate(
                    ['event' => $event],
                    ['company_id' => $merchant->company_id, 'channels' => $enabled ? ['push', 'whatsapp'] : []],
                );
            }

            foreach ($data['reports'] ?? [] as $frequency => $settings) {
                $report = $merchant->scheduledReports()->firstOrNew(['frequency' => $frequency]);
                $activating = $settings['active'] && ! $report->is_active;

                $report->fill([
                    'company_id' => $merchant->company_id,
                    'is_active' => $settings['active'],
                    'send_time' => $settings['send_time'],
                    'weekday' => $settings['weekday'] ?? $report->weekday ?? 1,
                ]);
                // Pas de rapport rattrapé immédiatement : le premier part à la prochaine échéance
                if ($activating) {
                    $report->last_sent_at = now();
                }
                $report->save();
            }
        });

        return response()->json(['data' => $this->settings($merchant->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(Merchant $merchant): array
    {
        $merchant->load(['notificationPreferences', 'scheduledReports']);

        return [
            'whatsapp_phone' => $merchant->whatsapp_phone,
            'messaging_phone' => $merchant->messagingPhone(),
            // Notification sur le téléphone d'abord (gratuit), WhatsApp à défaut
            'push_first' => WebPush::enabled() && (bool) $merchant->company?->merchant_push_first,
            'push_devices' => $merchant->pushSubscriptions()->count(),
            'events' => array_map(fn (NotificationEvent $e) => [
                'event' => $e->value,
                'label' => $e->label(),
                'enabled' => $merchant->wantsNotification($e),
            ], NotificationEvent::cases()),
            'reports' => collect(ReportFrequency::cases())->mapWithKeys(function (ReportFrequency $f) use ($merchant) {
                $report = $merchant->scheduledReports->firstWhere('frequency', $f);

                return [$f->value => [
                    'label' => $f->label(),
                    'active' => (bool) $report?->is_active,
                    'send_time' => substr($report->send_time ?? ($f === ReportFrequency::Daily ? '19:00' : '09:00'), 0, 5),
                    'weekday' => $report->weekday ?? 1,
                ]];
            }),
        ];
    }
}
