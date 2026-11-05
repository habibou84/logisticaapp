<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantPaymentMethod;
use App\Models\MerchantPaymentMethodEvent;
use App\Models\User;
use App\Services\Finance\MerchantPaymentMethods;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Moyens de paiement d'un client : il les gère depuis son application (le propriétaire du compte),
 * la caisse les consulte, les vérifie et peut en saisir elle-même.
 */
class MerchantPaymentMethodController extends Controller
{
    public function __construct(private MerchantPaymentMethods $methods) {}

    public function index(Request $request, Merchant $merchant): JsonResponse
    {
        $staff = $this->authorizeFor($request, $merchant);

        return response()->json([
            'data' => $merchant->paymentMethods()->with('verifier:id,name')->get()->map(fn ($m) => self::data($m)),
            // Historique : pour la caisse seulement
            'history' => $staff ? $merchant->paymentMethodEvents()->with('user:id,name')->limit(30)->get()->map(fn (MerchantPaymentMethodEvent $e) => [
                'action' => $e->action,
                'summary' => $e->summary,
                'by_merchant' => $e->by_merchant,
                'user' => $e->user?->name,
                'at' => $e->created_at?->toIso8601String(),
            ]) : null,
        ]);
    }

    public function store(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authorizeFor($request, $merchant, manage: true);
        abort_if($merchant->paymentMethods()->count() >= 6, 422, 'Six moyens de paiement au plus.');

        $method = $this->methods->add($request->user(), $merchant, $this->validated($request), $request->file('qr'));

        return response()->json(['data' => self::data($method)], 201);
    }

    public function update(Request $request, MerchantPaymentMethod $method): JsonResponse
    {
        $this->authorizeFor($request, $method->merchant, manage: true);

        $method = $this->methods->update($request->user(), $method, $this->validated($request), $request->file('qr'), $request->boolean('remove_qr'));

        return response()->json(['data' => self::data($method)]);
    }

    public function destroy(Request $request, MerchantPaymentMethod $method): Response
    {
        $this->authorizeFor($request, $method->merchant, manage: true);
        $this->methods->remove($request->user(), $method);

        return response()->noContent();
    }

    public function prefer(Request $request, MerchantPaymentMethod $method): JsonResponse
    {
        $this->authorizeFor($request, $method->merchant, manage: true);

        return response()->json(['data' => self::data($this->methods->prefer($request->user(), $method))]);
    }

    public function verify(Request $request, MerchantPaymentMethod $method): JsonResponse
    {
        $this->authorizeStaff($request);

        return response()->json(['data' => self::data($this->methods->verify($request->user(), $method)->load('verifier:id,name'))]);
    }

    public function reject(Request $request, MerchantPaymentMethod $method): JsonResponse
    {
        $this->authorizeStaff($request);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:200']])['reason'] ?? null;

        return response()->json(['data' => self::data($this->methods->reject($request->user(), $method, $reason)->load('verifier:id,name'))]);
    }

    public function qr(Request $request, MerchantPaymentMethod $method): StreamedResponse
    {
        $this->authorizeFor($request, $method->merchant);
        abort_unless($method->qr_path && Storage::disk('local')->exists($method->qr_path), 404);

        return Storage::disk('local')->response($method->qr_path);
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(MerchantPaymentMethod $m): array
    {
        return [
            'id' => $m->id,
            'type' => $m->type->value,
            'type_label' => $m->type->label(),
            'number' => $m->number,
            'display_number' => $m->displayNumber(),
            'holder_name' => $m->holder_name,
            'bank_name' => $m->bank_name,
            'payment_link' => $m->payment_link,
            'qr_url' => $m->qr_path ? "/merchant-payment-methods/{$m->id}/qr" : null,
            'is_preferred' => $m->is_preferred,
            'status' => $m->status,
            'verified_by' => $m->relationLoaded('verifier') ? $m->verifier?->name : null,
            'verified_at' => $m->verified_at?->toIso8601String(),
            'rejected_reason' => $m->rejected_reason,
            'summary' => $m->summary(),
            'updated_at' => $m->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_map(fn (PaymentMethod $p) => $p->value, MerchantPaymentMethod::TYPES))],
            'number' => ['required', 'string', 'max:60'],
            'holder_name' => ['nullable', 'string', 'max:120'],
            'bank_name' => ['nullable', 'required_if:type,bank', 'string', 'max:80'],
            'payment_link' => ['nullable', 'url:https,http', 'max:500'],
            'qr' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_qr' => ['nullable', 'boolean'],
            'is_preferred' => ['nullable', 'boolean'],
        ], [], ['number' => 'numéro', 'holder_name' => 'nom du titulaire', 'bank_name' => 'banque', 'payment_link' => 'lien de paiement', 'qr' => 'QR code']);

        if ($data['type'] !== PaymentMethod::Bank->value) {
            $phone = PhoneNumber::normalize($data['number']);
            abort_unless($phone !== null && PhoneNumber::isValid($phone), 422, 'Ce numéro de téléphone n\'est pas valable.');
            $data['number'] = $phone;
        } else {
            $data['number'] = mb_strtoupper(preg_replace('/\s+/', ' ', trim($data['number'])));
        }
        $data['is_preferred'] = $request->boolean('is_preferred');

        return $data;
    }

    /**
     * Le propriétaire du compte marchand pour ses propres moyens, ou la caisse.
     * Renvoie vrai pour un membre de l'agence.
     */
    private function authorizeFor(Request $request, Merchant $merchant, bool $manage = false): bool
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->merchant_id !== null) {
            abort_unless($user->merchant_id === $merchant->id && $user->hasRole(Role::MerchantOwner->value), 403);

            return false;
        }

        abort_unless($user->can(($manage ? Permission::FinanceManage : Permission::FinanceView)->value), 403);

        return true;
    }

    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && $user->can(Permission::FinanceManage->value), 403);
    }
}
