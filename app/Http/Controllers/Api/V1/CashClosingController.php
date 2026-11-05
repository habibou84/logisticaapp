<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\CashClosing;
use App\Services\Finance\CashClosings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clôture de la caisse du jour : chiffres à compter, clôture, historique.
 */
class CashClosingController extends Controller
{
    public function __construct(private readonly CashClosings $closings) {}

    /** Journée en cours : mouvements depuis la dernière clôture, ou la clôture du jour si elle est faite */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $companyId = $request->user()->company_id;
        $today = $this->closings->today($companyId);

        return response()->json([
            'data' => [
                'closed' => $today ? self::data($today->load('closer:id,name')) : null,
                'figures' => $today ? null : $this->closings->figures($companyId),
                'last' => ($last = $this->closings->last($companyId)) ? self::data($last->load('closer:id,name')) : null,
                'can_close' => $request->user()->can(Permission::FinanceManage->value),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeStaff($request, manage: true);
        $data = $request->validate([
            'counted' => ['required', 'array'],
            'counted.cash' => ['required', 'integer', 'min:0'],
            'counted.*' => ['nullable', 'integer', 'min:0'],
            'explanation' => ['nullable', 'string', 'max:500'],
            'float_kept' => ['required', 'integer', 'min:0'],
        ], [], ['counted.cash' => 'espèces comptées', 'explanation' => 'explication', 'float_kept' => 'fond de caisse']);

        $closing = $this->closings->close($request->user(), $request->user()->company, $data['counted'], $data['explanation'] ?? null, $data['float_kept']);

        return response()->json(['data' => self::data($closing->load('closer:id,name'))], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $closings = CashClosing::query()->with('closer:id,name')->latest('day')->limit(90)->get();

        return response()->json(['data' => $closings->map(fn (CashClosing $c) => self::data($c))]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(CashClosing $c): array
    {
        return [
            'id' => $c->id,
            'day' => $c->day->toDateString(),
            'period_start' => $c->period_start,
            'lines' => $c->lines,
            'total_in' => $c->total_in,
            'total_out' => $c->total_out,
            'difference' => $c->difference,
            'explanation' => $c->explanation,
            'float_kept' => $c->float_kept,
            'deposited' => $c->deposited,
            'with_couriers' => $c->with_couriers,
            'closed_by' => $c->closer?->name,
            'closed_at' => $c->closed_at,
        ];
    }

    private function authorizeStaff(Request $request, bool $manage = false): void
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && $user->can(($manage ? Permission::FinanceManage : Permission::FinanceView)->value), 403);
    }
}
