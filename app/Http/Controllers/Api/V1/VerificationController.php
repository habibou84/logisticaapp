<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\Accounts\PhoneVerifier;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suivi d'une vérification de numéro par le navigateur qui l'a demandée (jeton) :
 * état, envoi d'un code par SMS, saisie du code. À la fin d'une inscription, la
 * réponse ouvre la session du nouvel e-commerçant (une seule fois).
 */
class VerificationController extends Controller
{
    public function __construct(private readonly PhoneVerifier $verifier) {}

    public function show(string $token): JsonResponse
    {
        return response()->json($this->state($this->verification($token)));
    }

    public function sms(string $token): JsonResponse
    {
        $verification = $this->verification($token);
        $demoCode = $this->verifier->sendSms($verification);

        return response()->json([
            ...$this->state($verification->refresh()),
            'demo_code' => $demoCode,
        ]);
    }

    public function code(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']], [], ['code' => 'code']);

        $verification = $this->verification($token);
        $this->verifier->checkSmsCode($verification, $data['code']);

        return response()->json($this->state($verification->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(PhoneVerifier $verifier, PhoneVerification $verification): array
    {
        $whatsapp = $verifier->whatsappNumber($verification->company);
        $text = PhoneVerifier::WHATSAPP_PREFIX.' : '.$verification->whatsapp_code;

        $status = match (true) {
            $verification->completed_at !== null => 'completed',
            $verification->purpose === PhoneVerification::SIGNUP && $verification->isVerified() && $verification->user_id === null => 'failed',
            $verification->isVerified() => 'verified',
            $verification->isExpired() => 'expired',
            default => 'pending',
        };

        return [
            'purpose' => $verification->purpose,
            'phone' => PhoneNumber::display($verification->phone),
            'status' => $status,
            'message' => $status === 'failed' ? 'Ce numéro a déjà un compte. Connectez-vous, ou utilisez « Mot de passe oublié ».' : null,
            'verified_via' => $verification->verified_via,
            'expires_at' => $verification->expires_at,
            'whatsapp' => $whatsapp ? [
                'number' => PhoneNumber::display($whatsapp),
                'text' => $text,
                'link' => 'https://wa.me/'.ltrim($whatsapp, '+').'?text='.rawurlencode($text),
            ] : null,
            'sms' => [
                'available' => $verifier->smsAvailable($verification->phone),
                'sent' => $verification->sms_count > 0,
                'wait_seconds' => $verifier->smsWaitSeconds($verification),
            ],
            'demo' => $verifier->demoMode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(PhoneVerification $verification): array
    {
        $state = ['data' => self::describe($this->verifier, $verification)];

        // Inscription vérifiée : la première réponse qui le constate ouvre la session
        if ($verification->purpose === PhoneVerification::SIGNUP && $verification->user_id !== null && $verification->completed_at === null
            && PhoneVerification::whereKey($verification->id)->whereNull('completed_at')->update(['completed_at' => now()]) === 1) {
            $state['data']['status'] = 'completed';
            $state['session'] = AuthController::session(User::findOrFail($verification->user_id), 'inscription');
        }

        return $state;
    }

    private function verification(string $token): PhoneVerification
    {
        $verification = strlen($token) <= 100 ? $this->verifier->find($token) : null;
        abort_if($verification === null, 404, 'Demande introuvable. Recommencez.');

        return $verification;
    }
}
