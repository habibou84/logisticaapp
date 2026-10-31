<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Services\Couriers\DailyActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Activité des livreurs : suivi d'un jour (passé, aujourd'hui ou à venir) de toute l'équipe, par activité au choix,
 * et missions de ce jour d'un livreur.
 */
class CourierActivityController extends Controller
{
    /** Jours à venir consultables (ce qui est déjà prévu) */
    public const FUTURE_DAYS = 30;

    public function __construct(private readonly DailyActivity $activity) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->addDays(self::FUTURE_DAYS)->toDateString()],
            'activity' => ['nullable', Rule::in(['ecommerce', 'distribution', 'mad'])],
        ]);

        return response()->json($this->activity->day($request->user()->company, $data['date'] ?? null, $data['activity'] ?? null));
    }

    public function show(Request $request, Courier $courier): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->addDays(self::FUTURE_DAYS)->toDateString()]]);

        return response()->json(['data' => $this->activity->missions($courier, $request->input('date'))]);
    }
}
