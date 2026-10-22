<template>
  <!-- Statut d'une course et ses pastilles (liste en tableau et cartes du téléphone) -->
  <div>
    <StatusBadge :status="order.status" :label="order.status_label" />
    <div v-if="late" class="mt-1 inline-flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-800 whitespace-nowrap">
      ⏳ En retard · prévue le {{ date(lateDay) }}
    </div>
    <div
      v-if="order.awaiting_courier"
      :class="['mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', order.awaiting_courier.late ? 'bg-red-600 text-white' : 'bg-amber-100 text-amber-800']"
      :title="`Seuil d'alerte : ${duration(order.awaiting_courier.threshold_minutes)}`"
    >
      {{ order.awaiting_courier.late ? '⏰' : '🛵' }} Sans livreur · {{ order.awaiting_courier.stage === 'pickup' ? 'ramassage' : 'livraison' }} · {{ duration(order.awaiting_courier.minutes) }}
    </div>
    <div
      v-if="order.awaiting_courier?.after_cutoff"
      class="mt-1 inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 whitespace-nowrap"
      :title="`Heure limite du jour (${order.awaiting_courier.cutoff_time}) passée : risque de ne pas être livrée aujourd'hui`"
    >
      🕒 Après {{ order.awaiting_courier.cutoff_time }}
    </div>
    <div v-if="order.last_incident && ['delivery_failed', 'rescheduled'].includes(order.status)" class="text-xs text-red-600 mt-0.5">{{ order.last_incident.label }}</div>
    <div v-if="order.return_requested && !['returned', 'return_assigned', 'returning'].includes(order.status)" class="text-xs text-rose-600">Retour demandé</div>
    <div v-if="order.delivery.scheduled_date && (order.status === 'rescheduled' || (order.delivery.scheduled_date > todayIso && !['delivered', 'cancelled', 'rejected', 'returned', 'lost'].includes(order.status)))" :class="['text-xs', order.delivery.scheduled_date <= todayIso ? 'text-emerald-700 font-medium' : 'text-gray-600']">📅 {{ order.delivery.scheduled_date <= todayIso ? 'Prévue aujourd\'hui' : date(order.delivery.scheduled_date) }}</div>
    <!-- Décision du marchand encore d'actualité -->
    <div v-if="order.merchant_decision" class="mt-1 inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-800 whitespace-nowrap">
      🧑‍💼 Le marchand a décidé : {{ order.merchant_decision.kind === 'redeliver' ? `relivrer le ${date(order.merchant_decision.date)}` : 'retour demandé' }}
    </div>
    <button v-if="canDecide(order)" type="button" class="mt-1 block text-xs rounded bg-blue-600 text-white px-2 py-0.5" @click.stop="$emit('decide', order)">Décider</button>
    <button v-else-if="canRevise(order)" type="button" class="mt-1 block text-xs text-blue-700 underline" @click.stop="$emit('decide', order)">Modifier</button>
  </div>
</template>

<script setup>
import StatusBadge from './StatusBadge.vue'
import { useAuthStore } from '../stores/auth'
import { date, waitDuration as duration } from '../utils/format'

defineProps({
  order: { type: Object, required: true },
  // Jour de livraison passé, course toujours en cours
  late: { type: Boolean, default: false },
  lateDay: { type: String, default: '' },
})
defineEmits(['decide'])

const auth = useAuthStore()
const isoDay = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const todayIso = isoDay(new Date())
// « Décider » seulement si une décision est attendue ; un report sans livreur reparti reste modifiable
const canDecide = (o) => auth.can('orders.dispatch') && o.needs_decision
const canRevise = (o) => auth.can('orders.dispatch') && o.decision_editable
</script>
