<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\Reports\Revenue;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chiffre d'affaires par activité, mois par mois (périmètre de l'utilisateur).
 */
class RevenueController extends Controller
{
    public function __invoke(Request $request, Revenue $revenue): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && $user->can(Permission::RevenueView->value), 403);

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m', 'after_or_equal:from'],
        ]);
        $now = CarbonImmutable::now($user->company->timezone ?: config('app.timezone'));
        $to = isset($data['to']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['to'].'-01') : $now->startOfMonth();
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'].'-01') : $to->subMonths(11);
        abort_if($from->diffInMonths($to) > 35, 422, 'Trois ans au plus.');

        $activities = array_values(array_filter(Activity::cases(), fn (Activity $a) => $user->seesActivity($a)));

        return response()->json(['data' => [
            'activities' => array_map(fn (Activity $a) => ['value' => $a->value, 'label' => $a->label()], $activities),
            ...$revenue->monthly($user->company, $from, $to, $activities),
        ]]);
    }
}
