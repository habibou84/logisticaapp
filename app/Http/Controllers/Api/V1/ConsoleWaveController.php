<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformWavePayment;
use App\Rules\PhoneNumber as PhoneRule;
use App\Services\Platform\WavePayments;
use App\Services\Platform\WaveSettings;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Console : paiement des factures par le lien marchand Wave (lien, QR code, validateur, envoi des alertes) et
 * paiements à valider (l'administrateur connecté valide sans code).
 */
class ConsoleWaveController extends Controller
{
    public function __construct(private readonly WaveSettings $settings, private readonly WavePayments $payments) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->present()]);
    }

    public function update(Request $request): JsonResponse
    {
        // Lien collé sans « https:// » : complété ; {montant} est le repère du montant, pas une erreur d'adresse
        $link = trim((string) $request->input('link'));
        if ($link !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $link)) {
            $request->merge(['link' => 'https://'.$link]);
        }
        $data = $request->validate([
            'link' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) {
                if (! filter_var(str_replace('{montant}', '1000', (string) $value), FILTER_VALIDATE_URL) || ! str_starts_with((string) $value, 'https://')) {
                    $fail('Le lien Wave doit être une adresse qui commence par https://, par exemple https://pay.wave.com/m/…');
                }
            }],
            'validator_email' => ['nullable', 'email', 'max:190'],
            'validator_phone' => ['nullable', new PhoneRule],
            'validator_pin' => ['nullable', 'string', 'min:4', 'max:40'],
        ], [], ['link' => 'lien Wave', 'validator_email' => 'e-mail du validateur', 'validator_phone' => 'téléphone du validateur', 'validator_pin' => 'code de validation']);
        if (filled($data['validator_phone'] ?? null)) {
            $data['validator_phone'] = PhoneNumber::normalize($data['validator_phone']);
        }
        $this->settings->update($data);

        return $this->show();
    }

    public function uploadQr(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096']], [], ['image' => 'QR code']);
        $this->settings->storeQr($request->file('image'));

        return $this->show();
    }

    public function removeQr(): JsonResponse
    {
        $this->settings->removeQr();

        return $this->show();
    }

    /**
     * Serveur d'e-mail des alertes (Gmail : smtp.gmail.com, port 587, mot de passe d'application) ; hôte vide : retiré.
     */
    public function updateMail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:190'],
            'from_address' => ['nullable', 'email', 'max:190'],
        ], [], ['host' => 'serveur', 'port' => 'port', 'username' => 'identifiant', 'password' => 'mot de passe', 'from_address' => 'adresse d\'envoi']);
        $this->settings->updateMail($data);

        return $this->show();
    }

    public function testAlert(): JsonResponse
    {
        return response()->json(['data' => $this->payments->test()]);
    }

    /**
     * Paiements Wave à vérifier, les plus récents d'abord.
     */
    public function pending(): JsonResponse
    {
        $payments = PlatformWavePayment::query()->with(['invoice:id,number,period,total,amount_paid', 'company:id,name', 'user:id,name'])
            ->where('status', PlatformWavePayment::PENDING)->latest('id')->limit(100)->get();

        return response()->json(['data' => $payments->map(fn (PlatformWavePayment $p) => [
            'id' => $p->id, 'amount' => $p->amount, 'payer_phone' => $p->payer_phone, 'payer_name' => $p->user?->name,
            'company' => ['id' => $p->company_id, 'name' => $p->company?->name],
            'invoice' => ['id' => $p->platform_invoice_id, 'number' => $p->invoice?->number, 'period' => $p->invoice?->period],
            'claimed_at' => $p->claimed_at?->toIso8601String(), 'created_at' => $p->created_at->toIso8601String(), 'alerts' => $p->alerts ?? [],
        ])->values()]);
    }

    public function decide(Request $request, PlatformWavePayment $payment): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['received', 'missing'])]]);
        $payment = $this->payments->decide($payment, $data['decision'] === 'received', $request->user());

        return response()->json(['data' => ['id' => $payment->id, 'status' => $payment->status]]);
    }
}
