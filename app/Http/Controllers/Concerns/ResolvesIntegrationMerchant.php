<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\Permission;
use App\Models\Merchant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Intégrations (clés API, webhooks) : le marchand gère les siennes, l'administration
 * (droit integrations.manage) celles de n'importe quel marchand de l'entreprise.
 */
trait ResolvesIntegrationMerchant
{
    protected function authorizeIntegrations(Request $request): void
    {
        if (! $request->user()->can(Permission::IntegrationsManage->value)) {
            throw new AuthorizationException('Réservé au gérant du compte marchand ou à l\'administration.');
        }
    }

    /**
     * Marchand concerné : celui de l'utilisateur, ou celui choisi par l'administration (merchant_id).
     */
    protected function integrationMerchant(Request $request, bool $required = true): ?Merchant
    {
        $this->authorizeIntegrations($request);
        $user = $request->user();

        if ($user->merchant_id !== null) {
            return Merchant::findOrFail($user->merchant_id);
        }

        $request->validate(['merchant_id' => [$required ? 'required' : 'nullable', 'integer']]);

        return $request->filled('merchant_id') ? Merchant::findOrFail($request->integer('merchant_id')) : null;
    }

    protected function ensureOwnsIntegration(Request $request, int $merchantId): void
    {
        $this->authorizeIntegrations($request);
        $user = $request->user();

        if ($user->merchant_id !== null && $user->merchant_id !== $merchantId) {
            throw new AuthorizationException;
        }
    }
}
