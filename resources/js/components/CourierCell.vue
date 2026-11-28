<template>
  <div class="space-y-1 text-xs" @click.stop>
    <!-- Deux lignes nommées : le ramasseur (va chercher le colis) et le livreur (le remet au destinataire) -->
    <div v-for="r in roles" :key="r.type" class="flex min-w-0 items-center gap-1.5 rounded-md bg-slate-50 px-1.5 py-1">
      <span :class="['w-[4.6rem] shrink-0 text-[10px] font-bold uppercase tracking-wide', r.type === 'pickup' ? 'text-amber-700' : 'text-blue-700']">{{ r.label }}</span>
      <span :class="['min-w-0 flex-1 truncate', r.nameClass]" :title="r.title">{{ r.name }}</span>
      <span v-if="r.actions.length" class="flex shrink-0 gap-1">
        <button
          v-for="a in r.actions" :key="a.key" type="button" :disabled="busy"
          :class="['rounded border px-1.5 py-0.5 font-semibold', a.key === 'assign' ? 'border-slate-900 bg-slate-900 text-white' : a.key === 'remove' ? 'border-slate-300 bg-white text-red-700' : 'border-slate-300 bg-white']"
          :aria-label="`${a.label} : ${r.label.toLowerCase()} de ${order.tracking_code}`"
          @click="a.run()"
        >
          {{ a.label }}
        </button>
      </span>
    </div>
    <div v-if="order.return_courier" class="px-1.5 text-rose-700">Retour : {{ order.return_courier.name }}</div>
    <div v-if="order.held_by" class="px-1.5 text-amber-700" :title="`Colis chez ${order.held_by.name}`"><Icone nom="en-main" taille="petite" class="inline align-text-bottom" /> Colis chez {{ order.held_by.name }}</div>

    <AssignCourierModal
      :open="!!picking" :title="picking?.title" :subtitle="picking?.subtitle" :zone="picking?.zone" :current-id="picking?.currentId"
      :action-label="picking?.currentId ? 'Choisir' : 'Assigner'" :busy="busy"
      @close="picking = null" @pick="assign"
    />
  </div>
</template>

<script setup>
import Icone from './ui/Icone.vue'
import AssignCourierModal from './AssignCourierModal.vue'
import { computed, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { promptDialog } from '../composables/useDialog'
import { useToastStore } from '../stores/toasts'

/**
 * Ramasseur et livreur d'une course dans la liste : assigner, changer ou retirer sans ouvrir la fiche.
 * Le choix se fait dans une fenêtre avec recherche et l'état de chaque livreur (AssignCourierModal).
 */
const props = defineProps({
  order: { type: Object, required: true },
  // Gardé pour les écrans qui le passent encore ; la fenêtre charge elle-même l'état des livreurs
  couriers: { type: Array, default: () => [] },
  canDispatch: { type: Boolean, default: false },
})
const emit = defineEmits(['updated'])
const toasts = useToastStore()
const busy = ref(false)
const picking = ref(null)

const FINAL = ['delivered', 'cancelled', 'rejected', 'returned', 'lost']
const BEFORE_PICKUP = ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress']
const PICKUP_ALLOWED = BEFORE_PICKUP
const DELIVERY_ALLOWED = [...BEFORE_PICKUP, 'picked_up', 'at_hub', 'delivery_assigned', 'delivery_failed', 'rescheduled']
const STEP = { pickup: 'ramassage', delivery: 'livraison' }

const o = computed(() => props.order)
const fromWarehouse = computed(() => !!o.value.pickup?.hub_id)

const roles = computed(() => {
  const order = o.value
  const can = props.canDispatch
  const awaiting = order.awaiting_courier?.stage
  const list = []

  // Ramasseur : sans objet pour une commande qui part de l'entrepôt
  const pc = order.pickup_courier
  const pickupOpen = ['pickup_assigned', 'pickup_in_progress'].includes(order.status) && !order.to_reschedule
  let pickup
  if (fromWarehouse.value) pickup = { name: 'Départ de l\'entrepôt', nameClass: 'italic text-slate-500', actions: [] }
  else if (pc) {
    const picked = !BEFORE_PICKUP.includes(order.status)
    pickup = {
      name: picked ? `${pc.name} · ramassé` : pc.name,
      title: pc.name,
      nameClass: 'font-semibold text-slate-800',
      actions: can && pickupOpen ? [change('pickup', pc), remove('pickup', pc)] : [],
    }
  } else if (BEFORE_PICKUP.includes(order.status) && !FINAL.includes(order.status)) {
    pickup = {
      name: 'Sans ramasseur',
      nameClass: awaiting === 'pickup' ? 'font-semibold text-red-700' : 'text-slate-500',
      actions: can && PICKUP_ALLOWED.includes(order.status) && !order.to_reschedule ? [assignAction('pickup')] : [],
    }
  } else pickup = { name: '—', nameClass: 'text-slate-400', actions: [] }
  list.push({ type: 'pickup', label: 'Ramasseur', ...pickup })

  // Livreur : choisi d'avance avant le ramassage, ou à assigner une fois le colis ramassé
  const dc = order.delivery_courier
  let delivery
  if (dc) {
    delivery = {
      name: dc.name,
      title: dc.name,
      nameClass: 'font-semibold text-slate-800',
      actions: can && !FINAL.includes(order.status) ? [change('delivery', dc), remove('delivery', dc)] : [],
    }
  } else if (FINAL.includes(order.status)) delivery = { name: '—', nameClass: 'text-slate-400', actions: [] }
  else if (BEFORE_PICKUP.includes(order.status)) {
    delivery = {
      name: 'après le ramassage',
      nameClass: 'italic text-slate-500',
      actions: can && !order.to_reschedule ? [{ key: 'choose', label: 'Choisir', run: () => openPicker('delivery', null) }] : [],
    }
  } else {
    delivery = {
      name: 'Sans livreur',
      nameClass: awaiting === 'delivery' ? 'font-semibold text-red-700' : 'text-slate-500',
      actions: can && DELIVERY_ALLOWED.includes(order.status) && !order.return_requested ? [assignAction('delivery')] : [],
    }
  }
  list.push({ type: 'delivery', label: 'Livreur', ...delivery })
  return list
})

function assignAction(type) {
  return { key: 'assign', label: 'Assigner', run: () => openPicker(type, null) }
}
function change(type, current) {
  return { key: 'change', label: 'Changer', run: () => openPicker(type, current) }
}
function remove(type, current) {
  return { key: 'remove', label: 'Retirer', run: () => unassign(type, current) }
}

function openPicker(type, current) {
  const order = o.value
  const zone = type === 'pickup' ? order.pickup?.zone_name : order.delivery?.zone_name
  const where = type === 'pickup' ? `ramassage chez ${order.merchant?.business_name || 'le client'}` : `livraison à ${order.recipient?.name || 'son destinataire'}`
  picking.value = {
    type,
    currentId: current?.id ?? null,
    zone: zone || '',
    title: `${current ? 'Changer' : 'Assigner'} ${type === 'pickup' ? 'le ramasseur' : 'le livreur'} · ${order.tracking_code}`,
    subtitle: [where, zone].filter(Boolean).join(' · '),
  }
}

async function assign(courier) {
  const type = picking.value.type
  busy.value = true
  try {
    const { data } = await http.post(`/orders/${o.value.id}/assign`, { type, courier_id: courier.id })
    toasts.success(`${o.value.tracking_code} : ${STEP[type]} confié(e) à ${courier.name}.`)
    for (const w of data.meta?.warnings || []) toasts.push(w, 'info')
    picking.value = null
    emit('updated', data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function unassign(type, current) {
  const back = type === 'pickup' ? 'Sans ramasseur' : 'Sans livreur'
  const reason = await promptDialog(
    `Retirer ${current.name} ${type === 'pickup' ? 'du ramassage' : 'de la livraison'} de ${o.value.tracking_code} ?\nLa course repasse « ${back} » et ${current.name} est prévenu(e).\nMotif (facultatif) :`,
    '',
    { okLabel: 'Retirer', danger: true },
  )
  if (reason === null) return
  busy.value = true
  try {
    const { data } = await http.post(`/orders/${o.value.id}/unassign`, { type, reason: reason.trim() || undefined })
    toasts.success(`${o.value.tracking_code} : ${current.name} retiré(e), course « ${back.toLowerCase()} ».`)
    emit('updated', data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}
</script>
