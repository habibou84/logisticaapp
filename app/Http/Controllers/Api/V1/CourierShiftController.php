<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierShift;
use App\Services\Shifts\ShiftClock;
use App\Services\Shifts\ShiftPlace;
use App\Services\Shifts\ShiftSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pointage du livreur (son application) : où et quand il doit pointer aujourd'hui, puis le pointage sur place.
 */
class CourierShiftController extends Controller
{
    public function __construct(
        private readonly ShiftPlace $places,
        private readonly ShiftClock $clock,
        private readonly ShiftSettings $settings,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $courier = $this->courier($request);

        return response()->json(['data' => $this->state($courier)]);
    }

    public function store(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $shift = $this->clock->clockIn(
            $courier,
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
            isset($data['accuracy']) ? (int) $data['accuracy'] : null,
        );
        $courier->refresh();
        $time = $shift->clocked_in_at->setTimezone($courier->company?->timezone ?: config('app.timezone'))->format('H:i');

        return response()->json([
            'data' => $this->state($courier),
            'message' => match (true) {
                ! $courier->is_available => "Pointé à {$time}. Faites l'état des lieux de votre véhicule pour vous mettre en service.",
                $shift->late_minutes > 0 => "Pointé à {$time}, avec {$shift->late_minutes} min de retard. Vous êtes en service.",
                default => "Pointé à {$time}. Vous êtes en service, bonne journée !",
            },
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Courier $courier): array
    {
        $place = $this->places->for($courier);
        $shift = $this->clock->today($courier);
        $settings = $this->settings->for($courier->company);

        return [
            'place' => $place ? [...$place, 'expected_at' => $place['expected_at']?->toIso8601String()] : null,
            'shift' => $shift instanceof CourierShift ? $shift->brief() : null,
            'is_available' => $courier->is_available,
            'settings' => [
                'radius' => (int) $settings['radius'],
                'min_accuracy' => (int) $settings['min_accuracy'],
                'early_minutes' => (int) $settings['early_minutes'],
            ],
        ];
    }

    private function courier(Request $request): Courier
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');

        return $courier;
    }
}
