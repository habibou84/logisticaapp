<template>
  <!-- Ligne d'un menu (profil) : icône, intitulé, détail, flèche -->
  <component :is="href ? 'a' : RouterLink" v-bind="href ? { href } : { to }" class="tap flex items-center gap-3 px-4 py-3.5 active:bg-slate-50 hover:bg-slate-50">
    <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-lg', TONES[tone]]" aria-hidden="true"><StatusIcon :name="icon" class="h-5 w-5" /></span>
    <span class="min-w-0 flex-1">
      <span class="block font-semibold">{{ label }}</span>
      <span v-if="sub" :class="['block truncate text-sm', subClass || 'text-slate-600']">{{ sub }}</span>
    </span>
    <StatusIcon name="right" class="h-5 w-5 shrink-0 text-slate-400" />
  </component>
</template>

<script setup>
import { RouterLink } from 'vue-router'
import StatusIcon from './StatusIcon.vue'

defineProps({
  to: { type: String, default: '' },
  href: { type: String, default: '' },
  icon: { type: String, required: true },
  tone: { type: String, default: 'slate' },
  label: { type: String, required: true },
  sub: { type: String, default: '' },
  subClass: { type: String, default: '' },
})

const TONES = {
  slate: 'bg-slate-100 text-slate-700',
  green: 'bg-emerald-50 text-emerald-700',
  amber: 'bg-amber-50 text-amber-700',
  red: 'bg-red-50 text-red-700',
  blue: 'bg-blue-50 text-blue-700',
  violet: 'bg-violet-50 text-violet-700',
}
</script>
