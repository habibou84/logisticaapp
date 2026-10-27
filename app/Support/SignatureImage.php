<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;

/**
 * Signature dessinée sur le téléphone, envoyée en image PNG (data URL) : livraison, ramassage, retour au client,
 * bordereau de département. Seule une vraie image PNG de taille raisonnable est gardée.
 */
final class SignatureImage
{
    public const MAX_BYTES = 512 * 1024;

    /** Octets de l'image, null si rien n'a été signé ; BusinessRuleException si ce n'est pas une image PNG */
    public static function decode(?string $signature): ?string
    {
        if (blank($signature)) {
            return null;
        }
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $signature, $m)) {
            throw new BusinessRuleException('Signature illisible.', 'signature');
        }
        $data = base64_decode($m[1], true);
        if ($data === false || strlen($data) > self::MAX_BYTES || ! str_starts_with($data, "\x89PNG\r\n\x1a\n")
            || @getimagesizefromstring($data) === false) {
            throw new BusinessRuleException('Signature illisible.', 'signature');
        }

        return $data;
    }
}
