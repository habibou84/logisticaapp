<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\SetupGuide;
use App\Services\Onboarding\TestOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Parcours de mise en route de l'administrateur (droit settings.manage).
 */
class SetupGuideController extends Controller
{
    public function __construct(private readonly SetupGuide $guide) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            ...$this->guide->for($request->user()->company),
            // Jamais ouvert par cet administrateur : il s'ouvre tout seul à sa connexion
            'seen' => $request->user()->setup_seen_at !== null,
        ]]);
    }

    /** Lancer la course d'essai (ou retrouver celle en cours) */
    public function startTest(Request $request, TestOrder $test): JsonResponse
    {
        $order = $test->start($request->user());

        return response()->json(['data' => ['order_id' => $order->id, 'tracking_code' => $order->tracking_code]], 201);
    }

    /** Effacer la course d'essai et ce qu'elle a produit */
    public function cleanTest(Request $request, TestOrder $test): JsonResponse
    {
        $test->clean($request->user()->company);

        return $this->show($request);
    }

    public function seen(Request $request): JsonResponse
    {
        $request->user()->forceFill(['setup_seen_at' => $request->user()->setup_seen_at ?? now()])->save();

        return response()->json(null, 204);
    }

    /**
     * Remettre une étape conseillée à plus tard, ou la reprendre.
     */
    public function skip(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $data = $request->validate([
            'step' => ['required', 'string', Rule::in($this->guide->recommendedKeys($company))],
            'skipped' => ['required', 'boolean'],
        ], [], ['step' => 'étape']);

        $skipped = collect($company->setup_skipped ?? [])->reject(fn ($k) => $k === $data['step']);
        $company->forceFill(['setup_skipped' => ($data['skipped'] ? $skipped->push($data['step']) : $skipped)->values()->all()])->save();

        return response()->json(['data' => $this->guide->for($company->fresh())]);
    }
}
