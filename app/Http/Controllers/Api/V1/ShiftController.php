<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierShift;
use App\Services\Shifts\ShiftBoard;
use App\Services\Shifts\ShiftClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pointages des livreurs, pour l'équipe : écran du jour, pointage manuel (avec un motif), annulation d'un pointage.
 */
class ShiftController extends Controller
{
    public function index(Request $request, ShiftBoard $board): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $board->day($request->user()->company, $data['date'] ?? null)]);
    }

    public function manual(Request $request, Courier $courier, ShiftClock $clock): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'time' => ['nullable', 'date_format:H:i'],
        ], [], ['reason' => 'motif', 'time' => 'heure d\'arrivée']);

        $tz = $request->user()->company?->timezone ?: config('app.timezone');
        $at = isset($data['time']) ? CarbonImmutable::now($tz)->setTimeFromTimeString($data['time'].':00') : null;
        $shift = $clock->manual($courier, $request->user(), $data['reason'], $at);

        return response()->json(['data' => $shift->load('recorder:id,name')->brief()], 201);
    }

    public function destroy(CourierShift $shift): JsonResponse
    {
        $shift->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
