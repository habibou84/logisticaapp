<template>
  <span class="inline-flex items-center gap-3 min-w-0">
    <img v-if="branding.logo_url" :src="branding.logo_url" :alt="`Logo ${branding.name}`" :class="['object-contain shrink-0', sizes[size]]">
    <span v-else :class="['shrink-0 rounded-xl grid place-items-center font-bold', sizes[size], dark ? 'bg-white/15 text-white' : 'bg-emerald-600 text-white']" aria-hidden="true">{{ initials }}</span>
    <span v-if="withName" :class="['font-bold truncate', dark ? 'text-white' : 'text-slate-900', size === 'lg' ? 'text-2xl' : 'text-lg']">{{ branding.name }}</span>
  </span>
</template>

<script setup>
import { computed } from 'vue'
import { branding } from '../../composables/useBranding'

defineProps({
  size: { type: String, default: 'md' },
  withName: { type: Boolean, default: true },
  dark: { type: Boolean, default: false },
})

const sizes = { sm: 'h-8 w-8 text-sm', md: 'h-11 w-11 text-base', lg: 'h-16 w-16 text-xl' }
const initials = computed(() => (branding.name || '🚚').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase())
</script>
