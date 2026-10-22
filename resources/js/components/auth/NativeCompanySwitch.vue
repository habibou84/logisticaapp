<template>
  <!-- Application : entreprise choisie sur l'appareil, et lien pour en changer -->
  <p v-if="company" class="text-center text-sm">
    <span :class="dark ? 'text-white/80' : 'text-slate-500'">{{ company.name }} · </span>
    <RouterLink to="/entreprise" :class="['font-semibold hover:underline', dark ? 'text-white' : 'text-slate-700']">Changer d'entreprise</RouterLink>
  </p>
</template>

<script setup>
import { nativeApp, nativeCompany } from '../../native'

defineProps({ dark: { type: Boolean, default: false } })

const company = nativeApp ? nativeCompany() : null
</script>
