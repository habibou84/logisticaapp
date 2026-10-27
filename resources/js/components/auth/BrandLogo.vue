<template>
  <span class="inline-flex items-center gap-3 min-w-0">
    <img v-if="branding.logo_url" :src="branding.logo_url" :alt="`Logo ${branding.name}`" :class="['object-contain shrink-0', sizes[size]]">
    <!-- Sans logo envoyé : celui de Logistica (le nom y est déjà écrit) -->
    <img v-else :src="DEFAULT_LOGO" :alt="`Logo ${branding.name || 'Logistica'}`" :class="['object-contain shrink-0 w-auto', wide[size], dark ? 'rounded-xl bg-white px-2 py-1' : '']">
    <span v-if="withName && branding.logo_url" :class="['font-bold truncate', dark ? 'text-white' : 'text-slate-900', size === 'lg' ? 'text-2xl' : 'text-lg']">{{ branding.name }}</span>
  </span>
</template>

<script setup>
import { DEFAULT_LOGO, branding } from '../../composables/useBranding'

defineProps({
  size: { type: String, default: 'md' },
  withName: { type: Boolean, default: true },
  dark: { type: Boolean, default: false },
})

const sizes = { sm: 'h-8 w-8 text-sm', md: 'h-11 w-11 text-base', lg: 'h-16 w-16 text-xl' }
const wide = { sm: 'h-8', md: 'h-12', lg: 'h-20' }
</script>
