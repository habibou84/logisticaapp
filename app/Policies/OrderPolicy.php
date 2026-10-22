<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;

/**
 * L'isolation entre entreprises est assurée par le scope global (404 sinon).
 * Les transitions de statut sont contrôlées par OrderWorkflow.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::OrdersView->value);
    }

    public function view(User $user, Order $order): bool
    {
        if ($user->merchant_id !== null) {
            return $user->merchant_id === $order->merchant_id;
        }

        if ($user->isCourier()) {
            return $order->assignments()->where('courier_id', $user->courier?->id)->exists();
        }

        return $user->can(Permission::OrdersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::OrdersCreate->value);
    }

    public function update(User $user, Order $order): bool
    {
        if ($user->merchant_id !== null) {
            return $user->merchant_id === $order->merchant_id && $user->can(Permission::OrdersCreate->value);
        }

        return $user->can(Permission::OrdersDispatch->value);
    }

    public function dispatch(User $user): bool
    {
        return $user->can(Permission::OrdersDispatch->value);
    }

    public function addNote(User $user, Order $order): bool
    {
        return $this->view($user, $order);
    }

    public function requestReturn(User $user, Order $order): bool
    {
        return $user->merchant_id === $order->merchant_id || $user->can(Permission::OrdersDispatch->value);
    }

    /**
     * Frais engagés pour la course : le livreur de la course ou le personnel (dispatch, caisse).
     */
    public function addExpense(User $user, Order $order): bool
    {
        if ($user->isCourier()) {
            return $this->view($user, $order);
        }

        return $user->merchant_id === null
            && ($user->can(Permission::OrdersDispatch->value) || $user->can(Permission::FinanceManage->value));
    }

    public function cancelExpense(User $user, Order $order): bool
    {
        return $user->merchant_id === null && ! $user->isCourier()
            && ($user->can(Permission::OrdersDispatch->value) || $user->can(Permission::FinanceManage->value));
    }

    public function addAttachment(User $user, Order $order): bool
    {
        return $user->merchant_id === null && $this->view($user, $order);
    }
}
