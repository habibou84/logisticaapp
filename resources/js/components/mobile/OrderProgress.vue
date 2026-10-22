<template>
  <div>
    <div class="flex items-center">
      <template v-for="(step, i) in steps" :key="step.key">
        <div class="flex flex-col items-center gap-1 w-14">
          <div :class="['h-9 w-9 rounded-full grid place-items-center text-sm font-bold', dotClass(i)]">
            <span v-if="i < current || (i === current && done)">✓</span>
            <span v-else>{{ step.icon }}</span>
          </div>
          <span :class="['text-[11px] text-center leading-tight', i <= current ? 'text-slate-800 font-medium' : 'text-slate-400']">{{ step.label }}</span>
        </div>
        <div v-if="i < steps.length - 1" :class="['flex-1 h-1 rounded -mt-5', i < current ? 'bg-[var(--app-color)]' : 'bg-slate-200']" />
      </template>
    </div>
    <p v-if="problem" class="mt-3 rounded-xl bg-red-50 text-red-700 text-sm px-3 py-2">{{ problem }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, required: true },
  incident: { type: String, default: '' },
  rescheduledTo: { type: String, default: '' },
  // Zone d'expédition : la dernière étape est le dépôt à la gare
  shipping: { type: Boolean, default: false },
})

const steps = computed(() => [
  { key: 'created', label: 'Créée', icon: '1' },
  { key: 'picked', label: 'Récupérée', icon: '2' },
  { key: 'way', label: props.shipping ? 'Vers la gare' : 'En chemin', icon: '3' },
  { key: 'done', label: props.shipping ? 'Expédiée' : 'Livrée', icon: '4' },
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
  if (failed.value && i === current.value + 1) return 'bg-red-100 text-red-600 ring-2 ring-red-300'
  if (i < current.value || (i === current.value && done.value)) return 'bg-[var(--app-color)] text-white'
  if (i === current.value) return 'bg-white ring-2 ring-[var(--app-color)] text-[var(--app-color)]'
  return 'bg-slate-100 text-slate-400'
}
</script>
