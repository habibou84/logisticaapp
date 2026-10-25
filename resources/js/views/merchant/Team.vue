<template>
  <div class="space-y-3 px-4 py-4">
    <MerchantAccounts v-if="auth.user?.merchant_id" :merchant-id="auth.user.merchant_id" />
  </div>
</template>

<script setup>
import MerchantAccounts from '../../components/merchant/MerchantAccounts.vue'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
</script>
