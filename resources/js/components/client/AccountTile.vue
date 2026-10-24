<template>
  <!-- Tuile « Mon compte » de l'accueil : un chiffre, une ligne, un lien ou un panneau -->
  <component
    :is="to ? RouterLink : 'button'"
    v-bind="to ? { to } : { type: 'button' }"
    class="tap m-card block w-full p-3.5 text-left active:bg-slate-50 hover:ring-slate-300"
  >
    <span :class="['grid h-9 w-9 place-items-center rounded-lg', TONES[tone]]" aria-hidden="true"><StatusIcon :name="icon" class="h-5 w-5" /></span>
    <span class="mt-2 block truncate text-sm text-slate-600">{{ label }}</span>
    <span class="block truncate text-lg font-bold leading-snug">{{ value }}</span>
    <span v-if="sub" :class="['block truncate text-xs', subClass || 'text-slate-500']">{{ sub }}</span>
    <span v-if="percent !== null" class="mt-2 block h-1.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="Math.min(100, percent)" aria-valuemin="0" aria-valuemax="100">
      <span class="block h-full rounded-full" :class="percent >= 100 ? 'bg-red-500' : percent >= 80 ? 'bg-amber-500' : 'bg-[var(--app-color)]'" :style="{ width: `${Math.min(100, percent)}%` }" />
    </span>
  </component>
</template>

<script setup>
import { RouterLink } from 'vue-router'
import StatusIcon from '../mobile/StatusIcon.vue'

defineProps({
  to: { type: String, default: '' },
  icon: { type: String, required: true },
  tone: { type: String, default: 'slate' },
  label: { type: String, required: true },
  value: { type: String, required: true },
  sub: { type: String, default: '' },
  subClass: { type: String, default: '' },
  percent: { type: Number, default: null },
})

// Même sens des couleurs que les états des courses
const TONES = {
  green: 'bg-emerald-50 text-emerald-700',
  amber: 'bg-amber-50 text-amber-700',
  red: 'bg-red-50 text-red-700',
  violet: 'bg-violet-50 text-violet-700',
  blue: 'bg-blue-50 text-blue-700',
  slate: 'bg-slate-100 text-slate-700',
}
</script>
