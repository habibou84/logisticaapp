<?php

namespace App\Services\Destinations;

use App\Support\PhoneNumber;

/**
 * Numéros d'une destination tels que saisis (« 27-20-32-07-58 / 27-20-25-00-85 », « 21246211/12 ») :
 * seuls les numéros déjà au format ivoirien à 10 chiffres sont repris ; les autres restent tels quels,
 * « à vérifier ». Pour un ancien numéro à 8 chiffres, une correction est seulement proposée (règle de 2021).
 */
class DestinationPhones
{
    /** Préfixes valides à 10 chiffres : mobiles Moov, MTN, Orange, fixes Moov, MTN, Orange */
    private const PREFIXES = ['01', '05', '07', '21', '25', '27'];

    private const ORANGE = ['07', '08', '09', '47', '48', '49', '57', '58', '59', '67', '68', '69', '77', '78', '79', '87', '88', '89', '97', '98'];

    private const MTN = ['04', '05', '06', '44', '45', '46', '54', '55', '56', '64', '65', '66', '74', '75', '76', '84', '85', '86', '94', '95', '96'];

    private const MOOV = ['01', '02', '03', '40', '41', '42', '43', '50', '51', '52', '53', '70', '71', '72', '73'];

    private const FIXED = ['20', '21', '22', '23', '24', '30', '31', '32', '33', '34', '35', '36'];

    /**
     * Numéros lus dans un texte : bons (E.164), et morceaux douteux (gardés tels quels).
     *
     * @return array{valid: list<string>, doubtful: list<string>}
     */
    public static function parse(?string $text): array
    {
        $valid = $doubtful = [];
        $last = null;
        foreach (preg_split('#[/;,|\\\\]|\s(?:et|ou)\s|\s{3,}#iu', (string) $text) ?: [] as $part) {
            $digits = self::digits($part);
            if ($digits === '') {
                continue;
            }
            // « 21246211/12 » : le même numéro, fin différente
            $expanded = $last !== null && strlen($digits) <= 4 && strlen($digits) < strlen($last);
            if ($expanded) {
                $digits = substr($last, 0, strlen($last) - strlen($digits)).$digits;
            }
            $last = $digits;
            $normalized = self::valid($digits);
            if ($normalized !== null) {
                $valid[] = $normalized;
            } else {
                $doubtful[] = $expanded ? $digits : trim($part);
            }
        }

        return ['valid' => array_values(array_unique($valid)), 'doubtful' => $doubtful];
    }

    /** Numéro ivoirien à 10 chiffres (ou +225…) au bon format, en E.164 ; sinon null */
    public static function valid(?string $value): ?string
    {
        $digits = self::digits((string) $value);
        if (str_starts_with($digits, '00225')) {
            $digits = substr($digits, 5);
        } elseif (strlen($digits) === 13 && str_starts_with($digits, '225')) {
            $digits = substr($digits, 3);
        }

        return strlen($digits) === 10 && in_array(substr($digits, 0, 2), self::PREFIXES, true) ? PhoneNumber::normalize($digits) : null;
    }

    /**
     * Correction proposée pour un numéro douteux (jamais appliquée seule) : ancien numéro à 8 chiffres selon
     * la règle de 2021, 0 oublié. Null : rien à proposer.
     */
    public static function suggestion(?string $value): ?string
    {
        $digits = self::digits((string) $value);
        $guess = match (true) {
            strlen($digits) === 9 && in_array($digits[0], ['1', '5', '7'], true) => '0'.$digits,
            strlen($digits) === 8 && in_array(substr($digits, 0, 2), self::ORANGE, true) => '07'.$digits,
            strlen($digits) === 8 && in_array(substr($digits, 0, 2), self::MTN, true) => '05'.$digits,
            strlen($digits) === 8 && in_array(substr($digits, 0, 2), self::MOOV, true) => '01'.$digits,
            strlen($digits) === 8 && in_array(substr($digits, 0, 2), self::FIXED, true) => '27'.$digits,
            default => null,
        };

        return $guess !== null ? PhoneNumber::normalize($guess) : null;
    }

    public static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
