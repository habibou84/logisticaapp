<template>
  <div>
    <!-- Quatre étapes, chacune avec son icône en couleur ; la ligne se remplit jusqu'à l'étape atteinte -->
    <div class="relative flex items-start justify-between px-1">
      <span class="absolute left-7 right-7 top-[22px] h-1 rounded-full bg-slate-200" aria-hidden="true">
        <span class="block h-full rounded-full bg-[var(--app-color)] transition-all" :style="{ width: `${(current / (steps.length - 1)) * 100}%` }" />
      </span>
      <div v-for="(step, i) in steps" :key="step.key" class="relative flex w-16 flex-col items-center gap-1">
        <div :class="['grid h-12 w-12 place-items-center rounded-full bg-white border-[3px]', dotClass(i)]">
          <ColorIcon :name="step.art" :size="28" :tile="false" :class="i > current ? 'opacity-40' : ''" />
        </div>
        <span :class="['text-[11.5px] text-center leading-tight', i <= current ? 'text-slate-800 font-semibold' : 'text-slate-400']">{{ step.label }}</span>
      </div>
    </div>
    <p v-if="problem" class="mt-3 rounded-xl bg-[#FFECEF] text-[#C02A4E] text-sm px-3 py-2">{{ problem }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import ColorIcon from './ColorIcon.vue'

const props = defineProps({
  status: { type: String, required: true },
  incident: { type: String, default: '' },
  rescheduledTo: { type: String, default: '' },
  // Zone d'expédition : la dernière étape est le dépôt à la gare
  shipping: { type: Boolean, default: false },
  // Distribution : le pli à la place du colis
  activity: { type: String, default: '' },
})

const steps = computed(() => [
  { key: 'created', label: 'Créée', art: 'plus' },
  { key: 'picked', label: 'Récupérée', art: props.activity === 'distribution' ? 'pli' : 'colis' },
  { key: 'way', label: props.shipping ? 'Vers la gare' : 'En chemin', art: 'route' },
  { key: 'done', label: props.shipping ? 'Expédiée' : 'Livrée', art: 'livre' },
])

// Étape atteinte selon le statut de la course
const current = computed(() => ({
  pending: 0, confirmed: 0, pickup_assigned: 0, pickup_in_progress: 0,
  picked_up: 1, at_hub: 1, delivery_assigned: 1, delivery_failed: 1, rescheduled: 1,
  out_for_delivery: 2,
  delivered: 3,
  return_assigned: 1, returning: 1, returned: 1, cancelled: 0, rejected: 0,
}[props.status] ?? 0))

const done = computed(() => props.status === 'delivered')
const failed = computed(() => ['delivery_failed', 'cancelled', 'rejected', 'return_assigned', 'returning', 'returned'].includes(props.status))

const problem = computed(() => {
  if (props.status === 'delivery_failed') return `Livraison échouée${props.incident ? ` : ${props.incident}` : ''}`
  if (['return_assigned', 'returning'].includes(props.status)) return 'Le colis est en cours de retour vers vous'
  if (props.status === 'returned') return 'Le colis vous a été retourné'
  if (props.status === 'cancelled') return 'Course annulée'
  if (props.status === 'rejected') return 'Course refusée par l\'entreprise de livraison'
  return ''
})

function dotClass(i) {
  if (failed.value && i === current.value + 1) return 'border-[#F7B6C8]'
  if (i < current.value || (i === current.value && done.value)) return 'border-[var(--app-color)]'
  if (i === current.value) return 'border-[var(--app-color)] shadow-[0_0_0_6px_color-mix(in_srgb,var(--app-color)_15%,transparent)]'
  return 'border-slate-200'
}
</script>
