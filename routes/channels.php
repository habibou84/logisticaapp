<?php

use App\Enums\Role;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Événements temps réel de l'entreprise (nouvelles courses, incidents...) : personnel uniquement
Broadcast::channel('company.{companyId}', function ($user, $companyId) {
    return (int) $user->company_id === (int) $companyId
        && $user->hasAnyRole(Role::values(Role::staff()));
});

// Suivi temps réel des courses d'un marchand : comptes du marchand uniquement
Broadcast::channel('merchant.{merchantId}', function ($user, $merchantId) {
    return $user->merchant_id !== null && (int) $user->merchant_id === (int) $merchantId;
});
