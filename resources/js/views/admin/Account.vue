<template>
  <!-- Mon compte (repris de Jibiat) : qui je suis, et mon mot de passe -->
  <div class="max-w-xl space-y-4">
    <h1 class="text-xl font-semibold">Mon compte</h1>
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 text-sm text-slate-600 space-y-1">
      <p><span class="font-medium text-slate-800">{{ auth.user?.name }}</span> · {{ auth.user?.role_label }}</p>
      <p>{{ auth.user?.phone }}<span v-if="auth.user?.email"> · {{ auth.user.email }}</span></p>
    </div>
    <PasswordForm class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200" />
  </div>
</template>

<script setup>
import PasswordForm from '../../components/PasswordForm.vue'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
</script>
