<template>
  <!-- Historique d'une course en mots simples, sans les étapes internes (missions, assignations) -->
  <ol class="relative border-l-2 border-slate-200 ml-2 space-y-3">
    <li v-for="item in items" :key="item.id" class="ml-4">
      <span :class="['absolute -left-[7px] mt-1.5 h-3 w-3 rounded-full', item.dot]" />
      <p class="text-sm font-medium">{{ item.text }} <span class="font-normal text-slate-500">· {{ item.when }}</span></p>
      <p v-if="item.detail" class="text-sm text-slate-600">{{ item.detail }}</p>
    </li>
  </ol>
</template>

<script setup>
import { computed } from 'vue'
import { date } from '../../utils/format'

const props = defineProps({
  // Événements du plus récent au plus ancien
  events: { type: Array, default: () => [] },
})

const STATUS_TEXT = {
  confirmed: 'Course validée',
  picked_up: 'Colis récupéré à votre boutique',
  at_hub: 'Colis arrivé à l\'entrepôt',
  out_for_delivery: 'En route vers le client',
  delivered: 'Livrée',
  delivery_failed: 'Livraison échouée',
  rescheduled: 'Livraison reportée',
  return_assigned: 'Retour vers votre boutique',
  returning: 'Retour vers votre boutique',
  returned: 'Colis retourné à votre boutique',
  cancelled: 'Course annulée',
  rejected: 'Course refusée par l\'agence',
  lost: 'Colis déclaré perdu',
}

const time = (value) => {
  const d = new Date(value)
  const sameDay = d.toDateString() === new Date().toDateString()
  const hour = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  return sameDay ? hour : `${d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })} à ${hour}`
}

function describe(event) {
  if (event.type === 'created') return { text: 'Course créée', dot: 'bg-slate-400' }
  if (event.type === 'status_changed' && STATUS_TEXT[event.to_status]) {
    // Retour à « validée » depuis le ramassage : c'est un ramassage manqué, dit par l'incident
    if (event.to_status === 'confirmed' && event.from_status !== 'pending') return null
    const bad = ['delivery_failed', 'rescheduled', 'cancelled', 'rejected', 'lost'].includes(event.to_status)
    return {
      text: STATUS_TEXT[event.to_status],
      detail: [event.incident_reason?.label, event.rescheduled_to ? `Nouvelle date : ${date(event.rescheduled_to)}` : null, event.note].filter(Boolean).join(' · ') || null,
      dot: event.to_status === 'delivered' ? 'bg-emerald-500' : bad ? 'bg-amber-500' : 'bg-slate-400',
    }
  }
  if (event.type === 'incident') return { text: 'Problème signalé', detail: [event.incident_reason?.label, event.note].filter(Boolean).join(' · ') || null, dot: 'bg-amber-500' }
  if (event.type === 'return_requested') return { text: 'Retour demandé', detail: event.note, dot: 'bg-amber-500' }
  if (event.type === 'note' && event.note) return { text: 'Message', detail: event.note, dot: 'bg-sky-400' }
  if (event.type === 'edited') return { text: 'Livraison modifiée', dot: 'bg-slate-300' }
  return null
}

const items = computed(() => props.events
  .map((event) => {
    const item = describe(event)
    return item && { ...item, id: event.id, when: time(event.created_at) }
  })
  .filter(Boolean))
</script>
