<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\User;

/**
 * Le marchand gère ses produits et le stock gardé chez lui ; le personnel
 * (droit stock.manage) gère le stock des entrepôts de l'entreprise.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->merchant_id !== null) {
            return true;
        }

        return $user->can(Permission::StockManage->value)
            || $user->can(Permission::OrdersCreate->value)
            || $user->can(Permission::MerchantsView->value);
    }

    public function view(User $user, Product $product): bool
    {
        if ($user->merchant_id !== null) {
            return $user->merchant_id === $product->merchant_id;
        }

        return $this->viewAny($user);
    }

    /**
     * Créer ou modifier les produits d'un marchand.
     */
    public function manageFor(User $user, int $merchantId): bool
    {
        if ($user->merchant_id !== null && $user->merchant_id !== $merchantId) {
            return false;
        }

        return $user->can(Permission::StockManage->value);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->manageFor($user, $product->merchant_id);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->update($user, $product);
    }

    /**
     * Mouvement de stock : le marchand chez lui, le personnel dans les entrepôts.
     */
    public function moveStock(User $user, Product $product, ?int $hubId): bool
    {
        if (! $this->update($user, $product)) {
            return false;
        }

        return $user->merchant_id !== null ? $hubId === null : $hubId !== null;
    }
}
