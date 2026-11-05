<template>
  <PaymentMethodsManager v-if="auth.user?.merchant_id" :merchant-id="auth.user.merchant_id" merchant-side />
</template>

<script setup>
import PaymentMethodsManager from '../../components/payment/PaymentMethodsManager.vue'
import { useAuthStore } from '../../stores/auth'

// « Comment être payé » : les moyens par lesquels l'agence reverse l'argent des ventes
const auth = useAuthStore()
</script>
