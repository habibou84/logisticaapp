<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\Messaging\AlertWhatsApp;
use App\Services\Messaging\MessagingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WhatsApp des alertes (réglage des alertes) : numéro relié en scannant un QR code,
 * essai, déconnexion. Réservé aux responsables des réglages.
 */
class AlertWhatsAppController extends Controller
{
    public function __construct(private readonly AlertWhatsApp $alerts) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->alerts->state($this->companyId($request))]);
    }

    public function connect(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->attempt(fn () => $this->alerts->connect($companyId));

        return response()->json(['data' => $this->alerts->state($companyId)]);
    }

    public function test(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $message = $this->attempt(fn () => $this->alerts->test($companyId, $request->user()->phone));

        return response()->json(['data' => ['to' => $message->to, 'status' => $message->status]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->attempt(fn () => $this->alerts->disconnect($companyId));

        return response()->json(['data' => $this->alerts->state($companyId)]);
    }

    private function companyId(Request $request): int
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company_id === null || ! $user->can(Permission::SettingsManage->value), 403);

        return (int) $user->company_id;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (MessagingException $e) {
            abort(422, $e->getMessage());
        }
    }
}
