<template>
  <Modal :open="open" :title="`Suite à donner · ${order?.tracking_code || ''}`" @close="$emit('close')">
    <form v-if="order" class="space-y-4" @submit.prevent="submit">
      <!-- Contexte -->
      <div class="rounded-lg bg-slate-50 p-3 text-sm space-y-1">
        <p>
          <StatusBadge :status="order.status" :label="order.status_label" />
          <span v-if="order.last_incident" class="ml-2 text-red-700">{{ order.last_incident.label }}</span>
        </p>
        <p class="text-gray-600">
          {{ order.recipient?.name || 'Client' }} · {{ order.recipient?.phone }} · {{ order.delivery?.zone_name }}
          · tentative{{ order.attempts_count > 1 ? 's' : '' }} {{ order.attempts_count }} / {{ order.max_attempts }}
        </p>
        <p v-if="order.held_by" class="text-amber-800">🎒 Colis encore chez {{ order.held_by.name }}</p>
      </div>

      <!-- Choix -->
      <div class="grid gap-2" role="radiogroup" aria-label="Décision">
        <label
          v-for="c in choices"
          :key="c.value"
          :class="['flex gap-3 rounded-lg border p-3 cursor-pointer', form.decision === c.value ? 'border-blue-600 bg-blue-50' : 'border-slate-200', c.disabled ? 'opacity-50 cursor-not-allowed' : '']"
        >
          <input v-model="form.decision" type="radio" :value="c.value" :disabled="!!c.disabled" class="mt-1">
          <span>
            <span class="block font-medium">{{ c.icon }} {{ c.label }}</span>
            <span class="block text-xs text-gray-600">{{ c.disabled || c.help }}</span>
          </span>
        </label>
      </div>

      <!-- Relivrer : date et livreur -->
      <template v-if="form.decision === 'redeliver'">
        <div>
          <label class="label" for="dec-date">Date de livraison *</label>
          <div class="flex flex-wrap gap-2 mb-2">
            <button v-for="d in quickDates" :key="d.label" type="button" :class="['rounded-full px-3 py-1 text-xs ring-1', form.date === d.value ? 'bg-blue-600 text-white ring-blue-600' : 'ring-slate-300']" @click="form.date = d.value">{{ d.label }}</button>
          </div>
          <input id="dec-date" v-model="form.date" type="date" :min="today" class="input" required>
        </div>
      </template>
      <div v-if="form.decision === 'redeliver' || form.decision === 'return'">
        <label class="label" for="dec-courier">Assigner un livreur maintenant (facultatif)</label>
        <select id="dec-courier" v-model="form.courier_id" class="input">
          <option :value="null">Assigner plus tard</option>
          <option v-for="c in couriers" :key="c.id" :value="c.id">
            {{ c.name }}{{ order.held_by?.id === c.id ? ' · a le colis' : '' }} ({{ c.active_assignments_count }} en cours){{ c.is_available ? '' : ' · indisponible' }}
          </option>
        </select>
        <p v-if="form.decision === 'redeliver' && form.date !== today && form.courier_id" class="text-xs text-amber-700 mt-1">La mission apparaîtra dès maintenant dans l'application du livreur.</p>
      </div>

      <div>
        <label class="label" for="dec-note">Note (journal de la course, message au marchand)</label>
        <input id="dec-note" v-model="form.note" class="input" maxlength="500" placeholder="Ex. : le client sera disponible samedi">
      </div>

      <p class="text-xs text-gray-500">{{ merchantInfo }}</p>
      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="busy || !form.decision">Valider la décision</button>
    </form>
  </Modal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import Modal from './Modal.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  order: { type: Object, default: null },
  couriers: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'decided'])

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const addDays = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return iso(d) }
const today = addDays(0)
const quickDates = [
  { label: "Aujourd'hui", value: today },
  { label: 'Demain', value: addDays(1) },
  { label: 'Après-demain', value: addDays(2) },
]

const form = reactive({ decision: null, date: addDays(1), courier_id: null, note: '' })
const busy = ref(false)
const error = ref('')

const choices = computed(() => {
  const o = props.order || {}
  const maxReached = o.attempts_count >= o.max_attempts
  return [
    {
      value: 'redeliver', icon: '🛵', label: 'Relivrer',
      help: 'Nouvelle tentative à la date choisie. Le marchand est prévenu de la date.',
      disabled: o.return_requested ? 'Le retour de ce colis est déjà demandé.' : (maxReached ? `Nombre maximal de tentatives atteint (${o.max_attempts}).` : null),
    },
    o.from_warehouse
      ? { value: 'restock', icon: '🏬', label: 'Remettre en stock', help: 'Le colis revient dans le stock de l\'entrepôt.', disabled: o.held_by ? `Le colis est encore chez ${o.held_by.name} : recevez-le d'abord.` : null }
      : { value: 'return', icon: '↩️', label: 'Retourner au marchand', help: 'Le colis part dans la file « À retourner ». Le marchand est prévenu.' },
  ]
})

const merchantInfo = computed(() => ({
  redeliver: '📱 Le marchand reçoit un WhatsApp avec la nouvelle date.',
  return: '📱 Le marchand reçoit un WhatsApp : son colis lui sera retourné.',
  restock: 'Le stock est remis à jour.',
}[form.decision] || ''))

watch(() => [props.open, props.order?.id], () => {
  if (!props.open) return
  const firstAllowed = choices.value.find((c) => !c.disabled)
  Object.assign(form, { decision: firstAllowed?.value || null, date: addDays(1), courier_id: null, note: '' })
  error.value = ''
})

async function submit() {
  busy.value = true
  error.value = ''
  try {
    const payload = { decision: form.decision, note: form.note || undefined, courier_id: form.courier_id ?? undefined }
    if (form.decision === 'redeliver') payload.date = form.date
    const { data } = await http.post(`/orders/${props.order.id}/decision`, payload)
    emit('decided', data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>
