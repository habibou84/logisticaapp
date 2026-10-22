<?php

namespace App\Support;

/**
 * Exploitant de l'application (conditions générales, politique de confidentialité) :
 * réglages LEGAL_* du .env, à défaut le nom et l'e-mail de l'entreprise.
 */
class Legal
{
    /**
     * @return array{name: string, address: ?string, email: ?string, rccm: ?string}
     */
    public static function details(): array
    {
        $company = rescue(fn () => Branding::company(), null, false);
        $legal = config('platform.legal');

        return [
            'name' => $legal['name'] ?: ($company?->name ?? config('app.name')),
            'address' => $legal['address'] ?: $company?->address,
            'email' => $legal['email'] ?: $company?->email,
            'rccm' => $legal['rccm'] ?: null,
        ];
    }
}
