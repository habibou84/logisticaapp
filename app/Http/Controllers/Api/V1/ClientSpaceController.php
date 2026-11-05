<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Finance\ClientSpace;
use App\Services\Orders\ShipmentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Application du client : ses activités, le forfait du mois de chaque formule, ses factures à payer
 * et ses livreurs mis à disposition (MAD).
 */
class ClientSpaceController extends Controller
{
    public function __invoke(Request $request, ClientSpace $space): JsonResponse
    {
        $merchant = $request->user()->merchant;
        abort_if($merchant === null, 403);

        $summary = $space->summary($merchant->loadMissing('company'));
        // Montants (forfait, factures) : comptes qui voient l'argent du client
        if (! $request->user()->can('finance.view')) {
            $summary['plans'] = array_map(fn (array $p) => [...$p, 'monthly_fee' => null, 'overage_price' => null, 'estimate' => null], $summary['plans']);
            $summary['lots'] = array_map(fn (array $l) => [...$l, 'lot_price' => null, 'estimate' => null], $summary['lots']);
            $summary['invoices'] = null;
        }
        // Décompte du forfait (courses utilisées sur les courses incluses) : montré au client si l'agence le veut
        if (! app(ShipmentSettings::class)->for($merchant->company)['forfait_usage']) {
            $summary['plans'] = array_map(fn (array $p) => [...$p, 'included' => null, 'used' => null, 'remaining' => null, 'over' => null, 'percent' => null], $summary['plans']);
        }

        return response()->json(['data' => $summary]);
    }
}
