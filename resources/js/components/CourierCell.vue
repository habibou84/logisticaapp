<template>
  <div class="space-y-1 text-xs" @click.stop>
    <!-- Ramassage en cours : livreur modifiable sans ouvrir la course -->
    <label v-if="pickupEditable" class="flex items-center gap-1" :title="`Ramassage : ${order.pickup_courier.name}`">
      <span aria-hidden="true">↑</span>
      <select
        :value="order.pickup_courier.id" class="courier-select" :disabled="busy" :aria-label="`Livreur du ramassage de ${order.tracking_code}`"
        @change="change('pickup', $event)"
      >
        <option v-for="c in options(order.pickup_courier)" :key="c.id" :value="c.id">{{ c.label }}</option>
      </select>
    </label>
    <div v-else-if="order.pickup_courier && !assignStage">↑ {{ order.pickup_courier.name }}</div>

    <label v-if="deliveryEditable" class="flex items-center gap-1" :title="`Livraison : ${order.delivery_courier.name}`">
      <span aria-hidden="true">↓</span>
      <select
        :value="order.delivery_courier.id" class="courier-select" :disabled="busy" :aria-label="`Livreur de la livraison de ${order.tracking_code}`"
        @change="change('delivery', $event)"
      >
        <option v-for="c in options(order.delivery_courier)" :key="c.id" :value="c.id">{{ c.label }}</option>
      </select>
    </label>
    <div v-else-if="order.delivery_courier">↓ {{ order.delivery_courier.name }}</div>

    <!-- Sans livreur : assigner directement -->
    <label v-if="assignStage" class="flex items-center gap-1">
      <span aria-hidden="true">{{ assignStage === 'pickup' ? '↑' : '↓' }}</span>
      <select
        value="" :class="['courier-select', order.awaiting_courier?.late ? 'border-red-300 text-red-700' : 'text-gray-500']" :disabled="busy"
        :aria-label="`Assigner ${assignStage === 'pickup' ? 'le ramassage' : 'la livraison'} de ${order.tracking_code}`"
        @change="change(assignStage, $event)"
      >
        <option value="" disabled>{{ assignStage === 'pickup' ? 'Ramassage : assigner…' : 'Livraison : assigner…' }}</option>
        <option v-for="c in options(null)" :key="c.id" :value="c.id">{{ c.label }}</option>
      </select>
    </label>

    <div v-if="order.held_by" class="text-amber-700" :title="`Colis chez ${order.held_by.name}`">🎒 {{ order.held_by.name }}</div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'

/**
 * Livreur d'une course dans la liste : petite liste pour changer le livreur du ramassage ou de la livraison, ou pour
 * en assigner un quand la course attend, sans ouvrir la fiche.
 */
const props = defineProps({
  order: { type: Object, required: true },
  couriers: { type: Array, default: () => [] },
  canDispatch: { type: Boolean, default: false },
})
const emit = defineEmits(['updated'])
const toasts = useToastStore()
const busy = ref(false)
const FINAL = ['delivered', 'cancelled', 'rejected', 'returned', 'lost']

const editable = computed(() => props.canDispatch && props.couriers.length > 0)
const pickupEditable = computed(() => editable.value && !props.order.to_reschedule && !!props.order.pickup_courier && ['pickup_assigned', 'pickup_in_progress'].includes(props.order.status))
const deliveryEditable = computed(() => editable.value && !!props.order.delivery_courier && !FINAL.includes(props.order.status))
const assignStage = computed(() => (editable.value && props.order.awaiting_courier?.stage) || null)

// Livreurs actifs, le livreur actuel compris même s'il n'est plus dans la liste
function options(current) {
  const list = props.couriers.map((c) => ({
    id: c.id,
    label: `${c.name}${c.active_assignments_count ? ` (${c.active_assignments_count})` : ''}${c.is_available === false ? ' · indispo.' : ''}`,
  }))
  if (current && !list.some((c) => c.id === current.id)) list.unshift({ id: current.id, label: current.name })
  return list
}

async function change(type, event) {
  const courierId = Number(event.target.value)
  if (!courierId) return
  busy.value = true
  try {
    const { data } = await http.post(`/orders/${props.order.id}/assign`, { type, courier_id: courierId })
    const name = props.couriers.find((c) => c.id === courierId)?.name || 'le livreur'
    toasts.success(`${props.order.tracking_code} : ${type === 'pickup' ? 'ramassage' : 'livraison'} confiée à ${name}.`)
    for (const w of data.meta?.warnings || []) toasts.push(w, 'info')
    emit('updated', data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    event.target.value = type === 'pickup' ? props.order.pickup_courier?.id ?? '' : props.order.delivery_courier?.id ?? ''
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.courier-select {
  max-width: 11rem;
  border-radius: 0.375rem;
  border: 1px solid #cbd5e1;
  background: #fff;
  padding: 0.125rem 0.25rem;
  font-size: 0.75rem;
}
.courier-select:hover { border-color: #64748b; }
</style>
