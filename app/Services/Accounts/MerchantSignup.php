<?php

namespace App\Services\Accounts;

use App\Enums\MerchantStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Merchant;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Notifications\MerchantSignedUp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Inscription en ligne d'un e-commerçant : une fois son numéro vérifié, la boutique
 * et le compte du gérant sont créés et actifs aussitôt ; l'équipe est prévenue.
 */
class MerchantSignup
{
    public function complete(PhoneVerification $verification): User
    {
        $user = DB::transaction(function () use ($verification) {
            $verification = PhoneVerification::lockForUpdate()->findOrFail($verification->id);

            if ($verification->user_id !== null) {
                return User::findOrFail($verification->user_id);
            }

            $data = $verification->payload ?? [];

            if (User::takenIn($verification->company_id, 'phone', $verification->phone)
                || Merchant::forCompany($verification->company_id)->where('phone', $verification->phone)->exists()) {
                throw new BusinessRuleException('Ce numéro a déjà un compte. Connectez-vous, ou utilisez « Mot de passe oublié ».', 'phone');
            }

            if (User::takenIn($verification->company_id, 'email', $data['email'] ?? null)) {
                $data['email'] = null;
            }

            $merchant = Merchant::create([
                'company_id' => $verification->company_id,
                'business_name' => $data['business_name'],
                'contact_name' => $data['contact_name'],
                'phone' => $verification->phone,
                'email' => $data['email'] ?? null,
                'pickup_zone_id' => $data['pickup_zone_id'] ?? null,
                'pickup_address' => $data['pickup_address'] ?? null,
                'pickup_landmark' => $data['pickup_landmark'] ?? null,
                'status' => MerchantStatus::Active,
            ]);
            $merchant->forceFill(['source' => 'signup'])->save();

            $user = new User([
                'company_id' => $verification->company_id,
                'merchant_id' => $merchant->id,
                'name' => $data['contact_name'],
                'phone' => $verification->phone,
                'email' => $data['email'] ?? null,
            ]);
            // Mot de passe haché à la demande d'inscription (jamais conservé en clair)
            $user->forceFill(['password' => $data['password_hash'], 'phone_verified_at' => now()])->save();
            $user->assignRole(Role::MerchantOwner->value);

            // Le mot de passe haché n'a plus à être conservé
            $verification->forceFill(['user_id' => $user->id, 'payload' => [...$data, 'password_hash' => null]])->save();

            DB::afterCommit(fn () => $this->notifyStaff($merchant));

            return $user;
        });

        $verification->refresh();

        return $user;
    }

    private function notifyStaff(Merchant $merchant): void
    {
        $staff = User::forCompany($merchant->company_id)
            ->where('status', 'active')
            ->whereNull('merchant_id')
            ->permission(Permission::MerchantsManage->value)
            ->get();

        if ($staff->isNotEmpty()) {
            Notification::send($staff, new MerchantSignedUp($merchant));
        }
    }
}
