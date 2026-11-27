<template>
  <div class="text-center py-12 px-6">
    <!-- Nom du dictionnaire de la charte (« course », « message »…) ou ancien nom (« check », « search ») : icône au trait dans une pastille ; sinon le caractère donné -->
    <!-- Icône en couleur (espace client), en grand -->
    <div v-if="color" class="mx-auto mb-3 w-fit"><ColorIcon :name="color" :size="76" /></div>
    <span v-else-if="ICONES[icon]" class="mx-auto mb-3 grid h-16 w-16 place-items-center rounded-2xl bg-slate-100 text-slate-600" aria-hidden="true">
      <Icone :nom="icon" taille="grande" class="h-8 w-8" />
    </span>
    <span v-else-if="/^[a-z-]+$/.test(icon)" class="mx-auto mb-3 grid h-16 w-16 place-items-center rounded-2xl bg-slate-100 text-slate-600" aria-hidden="true">
      <StatusIcon :name="icon" class="h-8 w-8" />
    </span>
    <div v-else class="text-5xl mb-3">{{ icon }}</div>
    <p class="font-semibold text-slate-800">{{ title }}</p>
    <p v-if="text" class="text-sm text-slate-600 mt-1">{{ text }}</p>
    <div v-if="$slots.default" class="mt-5"><slot /></div>
  </div>
</template>

<script setup>
import Icone from '../ui/Icone.vue'
import ColorIcon from './ColorIcon.vue'
import StatusIcon from './StatusIcon.vue'
import { ICONES } from '../../utils/icones'

defineProps({
  color: { type: String, default: '' },
  icon: { type: String, default: 'vide' },
  title: { type: String, required: true },
  text: { type: String, default: '' },
})
</script>
