<template>
  <form class="space-y-3" @submit.prevent="submit">
    <div v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</div>

    <div v-if="choices.length > 1">
      <label class="label" for="to">Nouveau statut</label>
      <select id="to" v-model="form.status" class="input" required>
        <option v-for="s in choices" :key="s" :value="s">{{ STATUS_LABELS[s] }}</option>
      </select>
    </div>

    <div v-if="showReason">
      <label class="label" for="reason">Motif {{ reasonRequired ? '*' : '' }}</label>
      <select id="reason" v-model="form.incident_reason_id" class="input" :required="reasonRequired">
        <option :value="null">{{ reasonRequired ? 'Choisir…' : 'Aucun' }}</option>
        <option v-for="r in reasons" :key="r.id" :value="r.id">{{ r.label }}</option>
      </select>
    </div>

    <div v-if="showDate">
      <label class="label" for="date">Nouvelle date de livraison *</label>
      <input id="date" v-model="form.rescheduled_to" type="date" :min="today()" class="input" required>
    </div>

    <template v-if="form.status === 'delivered'">
      <template v-if="order.is_shipping">
        <p class="text-sm rounded bg-indigo-50 text-indigo-900 p-2">🚌 Expédition : colis déposé à la gare, frais facturés au marchand.</p>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="label" for="carrier">Compagnie ou gare *</label><input id="carrier" v-model="form.shipping_carrier" class="input" required></div>
          <div><label class="label" for="shipfee">Frais d'expédition (F) *</label><input id="shipfee" v-model.number="form.shipping_fee" type="number" min="0" class="input" required></div>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="label" for="ticket">Numéro du ticket</label><input id="ticket" v-model="form.shipping_reference" class="input"></div>
          <div>
            <label class="label" for="shippaid">Payés par</label>
            <select id="shippaid" v-model="form.shipping_paid_by" class="input">
              <option value="courier">Le livreur (avance ou de sa poche)</option>
              <option value="company">L'agence directement</option>
            </select>
          </div>
        </div>
      </template>
      <div>
        <label class="label" for="amount">Montant encaissé (F)</label>
        <input id="amount" v-model.number="form.collected_amount" type="number" min="0" class="input">
      </div>
      <div v-if="form.collected_amount > 0">
        <label class="label" for="method">Mode de paiement</label>
        <select id="method" v-model="form.payment_method" class="input">
          <option v-for="m in DELIVERY_PAYMENT_METHODS" :key="m" :value="m">{{ PAYMENT_METHODS[m] }}</option>
        </select>
      </div>
      <template v-if="form.collected_amount > 0 && form.payment_method !== 'cash'">
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.received_by_company" type="checkbox">
          Payé directement sur le compte de l'entreprise (rien à verser à la caisse)
        </label>
        <div>
          <label class="label" for="ref">Référence de la transaction</label>
          <input id="ref" v-model="form.transaction_ref" class="input">
        </div>
      </template>
      <div v-if="askCode && !order.is_shipping">
        <label class="label" for="code">Code de livraison donné par le client</label>
        <input id="code" v-model="form.delivery_code" class="input" inputmode="numeric" maxlength="4" autocomplete="one-time-code">
      </div>
    </template>

    <div>
      <label class="label" for="note">{{ form.status === 'cancelled' ? 'Raison' : 'Note' }}</label>
      <textarea id="note" v-model="form.note" rows="2" class="input" />
    </div>

    <button type="submit" class="btn-primary w-full" :disabled="saving">{{ saving ? 'Envoi…' : 'Valider' }}</button>
  </form>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { DELIVERY_PAYMENT_METHODS, PAYMENT_METHODS, STATUS_LABELS, today } from '../utils/format'
import { needsDate, needsReason } from '../utils/workflow'

const props = defineProps({
  order: { type: Object, required: true },
  choices: { type: Array, required: true },
  askCode: { type: Boolean, default: false },
  position: { type: Object, default: null },
})
const emit = defineEmits(['done'])

const form = reactive({
  status: props.choices[0],
  incident_reason_id: null,
  rescheduled_to: null,
  collected_amount: props.order.amounts.cod_amount,
  payment_method: 'cash',
  received_by_company: false,
  transaction_ref: '',
  delivery_code: '',
  shipping_carrier: '',
  shipping_fee: props.order.shipping?.fee_estimate ?? 0,
  shipping_reference: '',
  shipping_paid_by: 'courier',
  note: '',
})
const allReasons = ref([])
const error = ref('')
const saving = ref(false)

const stage = computed(() => (['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress'].includes(props.order.status) ? 'pickup' : 'delivery'))
const reasons = computed(() => allReasons.value.filter((r) => r.applies_to === 'both' || r.applies_to === stage.value))
const reasonRequired = computed(() => needsReason(props.order.status, form.status))
const showReason = computed(() => reasonRequired.value || ['rescheduled', 'cancelled'].includes(form.status))
const selectedReason = computed(() => allReasons.value.find((r) => r.id === form.incident_reason_id))
const showDate = computed(() => needsDate(form.status) || selectedReason.value?.requires_date)

watch(() => props.choices, (c) => { if (!c.includes(form.status)) form.status = c[0] })

onMounted(async () => {
  const { data } = await http.get('/incident-reasons')
  allReasons.value = data.data
})

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const payload = { status: form.status, note: form.note || undefined }
    if (showReason.value && form.incident_reason_id) payload.incident_reason_id = form.incident_reason_id
    if (showDate.value) payload.rescheduled_to = form.rescheduled_to
    if (form.status === 'delivered') {
      payload.collected_amount = form.collected_amount
      if (form.collected_amount > 0) {
        payload.payment_method = form.payment_method
        if (form.payment_method !== 'cash') {
          payload.received_by_company = form.received_by_company
          if (form.transaction_ref) payload.transaction_ref = form.transaction_ref
        }
      }
      if (form.delivery_code) payload.delivery_code = form.delivery_code
      if (props.order.is_shipping) {
        Object.assign(payload, {
          shipping_carrier: form.shipping_carrier,
          shipping_fee: form.shipping_fee ?? 0,
          shipping_reference: form.shipping_reference || undefined,
          shipping_paid_by: form.shipping_paid_by,
        })
      }
    }
    if (form.status === 'cancelled') payload.cancel_reason = form.note || undefined
    if (props.position) Object.assign(payload, props.position)
    const { data } = await http.post(`/orders/${props.order.id}/status`, payload)
    emit('done', data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
