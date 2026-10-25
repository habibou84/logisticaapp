<template>
  <div class="space-y-3 px-4 py-4">
    <p class="text-sm text-slate-600">Vos départements : qui envoie, qui reçoit, et les envois du mois de chacun.</p>
    <DepartmentsManager v-if="auth.user?.merchant_id" :merchant-id="auth.user.merchant_id" />
  </div>
</template>

<script setup>
import { useAuthStore } from '../../stores/auth'
import DepartmentsManager from '../../components/departments/DepartmentsManager.vue'

const auth = useAuthStore()
</script>
