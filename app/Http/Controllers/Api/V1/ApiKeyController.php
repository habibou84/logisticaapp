<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiScope;
use App\Http\Controllers\Concerns\ResolvesIntegrationMerchant;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiKeyController extends Controller
{
    use ResolvesIntegrationMerchant;

    public function index(Request $request): JsonResponse
    {
        $merchant = $this->integrationMerchant($request, required: false);

        $keys = ApiKey::query()
            ->with(['merchant', 'creator'])
            ->when($merchant, fn ($q) => $q->where('merchant_id', $merchant->id))
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $keys->map(fn (ApiKey $k) => $this->present($k)),
            'scopes' => collect(ApiScope::cases())->map(fn (ApiScope $s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $this->integrationMerchant($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(ApiScope::values())],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        [$key, $plain] = ApiKey::issue($merchant, $data['name'], $data['scopes'], $request->user(), $data['expires_at'] ?? null);

        return response()->json([
            'data' => $this->present($key->load(['merchant', 'creator'])),
            // Affichée une seule fois : seul son hash est conservé
            'key' => $plain,
        ], 201);
    }

    public function destroy(Request $request, ApiKey $apiKey): JsonResponse
    {
        $this->ensureOwnsIntegration($request, $apiKey->merchant_id);

        if ($apiKey->revoked_at === null) {
            $apiKey->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json(['data' => $this->present($apiKey->load(['merchant', 'creator']))]);
    }

    private function present(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'merchant_id' => $key->merchant_id,
            'merchant_name' => $key->merchant?->business_name,
            'masked_key' => $key->maskedKey(),
            'scopes' => $key->scopes,
            'created_by' => $key->creator?->name,
            'created_at' => $key->created_at,
            'last_used_at' => $key->last_used_at,
            'expires_at' => $key->expires_at,
            'revoked_at' => $key->revoked_at,
            'is_active' => $key->isUsable(),
        ];
    }
}
