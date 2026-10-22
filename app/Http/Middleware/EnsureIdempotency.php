<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-tête Idempotency-Key obligatoire : une requête relancée (coupure réseau) avec la même
 * clé renvoie la réponse d'origine au lieu de créer un doublon. Mémorisé 24 heures.
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || mb_strlen($key) > 100) {
            return response()->json([
                'message' => 'En-tête Idempotency-Key obligatoire (100 caractères au plus), par exemple un UUID.',
                'errors' => ['Idempotency-Key' => ['En-tête Idempotency-Key obligatoire.']],
            ], 422);
        }

        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());
        $existing = IdempotencyKey::where('api_key_id', $apiKey->id)->where('key', $key)->first();

        if ($existing) {
            return $this->replay($existing, $hash);
        }

        try {
            $record = IdempotencyKey::create(['api_key_id' => $apiKey->id, 'key' => $key, 'request_hash' => $hash]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay(IdempotencyKey::where('api_key_id', $apiKey->id)->where('key', $key)->firstOrFail(), $hash);
        }

        $response = $next($request);

        // Erreur serveur : la clé est libérée pour permettre une nouvelle tentative
        if ($response->getStatusCode() >= 500) {
            $record->delete();
        } else {
            $record->forceFill(['response_code' => $response->getStatusCode(), 'response_body' => $response->getContent()])->save();
        }

        return $response;
    }

    private function replay(IdempotencyKey $record, string $hash): Response
    {
        if (! hash_equals($record->request_hash, $hash)) {
            return response()->json(['message' => 'Cette clé d\'idempotence a déjà servi pour une requête différente.'], 422);
        }

        if ($record->response_code === null) {
            return response()->json(['message' => 'Une requête identique est en cours de traitement. Réessayez dans un instant.'], 409);
        }

        return response($record->response_body, $record->response_code)
            ->header('Content-Type', 'application/json')
            ->header('Idempotent-Replayed', 'true');
    }
}
