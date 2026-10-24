<template>
  <Modal :open="open" :title="`Location · ${vehicle.plate}`" @close="$emit('close')">
    <form class="space-y-3" @submit.prevent="save">
      <div class="grid grid-cols-2 gap-2">
        <button v-for="k in KINDS" :key="k.value" type="button" :class="['rounded-lg border px-3 py-2 text-left text-sm', form.kind === k.value ? 'border-slate-900 bg-slate-50' : 'border-slate-200']" @click="form.kind = k.value">
          <span class="block font-semibold">{{ k.label }}</span><span class="block text-xs text-gray-500">{{ k.hint }}</span>
        </button>
      </div>
      <div>
        <label class="label" for="ls-courier">Livreur</label>
        <select id="ls-courier" v-model="form.courier_id" class="input" required>
          <option :value="null" disabled>Choisir…</option>
          <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="ls-amount">Montant (F)</label><input id="ls-amount" v-model.number="form.amount" type="number" min="1" class="input" required></div>
        <div>
          <label class="label" for="ls-period">Par</label>
          <select id="ls-period" v-model="form.period" class="input"><option value="day">jour</option><option value="week">semaine</option><option value="month">mois</option></select>
        </div>
        <div v-if="form.period === 'day'">
          <label class="label" for="ls-dpw">Jours payants</label>
          <select id="ls-dpw" v-model.number="form.days_per_week" class="input"><option :value="7">7 jours sur 7</option><option :value="6">6 jours (sans le dimanche)</option><option :value="5">5 jours (sans le week-end)</option></select>
        </div>
        <div v-if="form.kind === 'purchase'"><label class="label" for="ls-total">Prix total (F)</label><input id="ls-total" v-model.number="form.total_amount" type="number" min="1" class="input" required></div>
        <div><label class="label" for="ls-start">Début</label><input id="ls-start" v-model="form.starts_on" type="date" class="input"></div>
        <div>
          <label class="label" for="ls-pay">Paiement</label>
          <select id="ls-pay" v-model="form.payment" class="input"><option value="payroll">Retenue sur la paie</option><option value="cash">Payé en caisse</option></select>
        </div>
      </div>
      <p v-if="form.kind === 'purchase' && form.amount && form.total_amount" class="rounded bg-violet-50 px-3 py-2 text-sm text-violet-900">
        {{ Math.ceil(form.total_amount / form.amount) }} versements de {{ money(form.amount) }} par {{ PERIODS[form.period] }} : le livreur devient propriétaire à la fin.
      </p>
      <div><label class="label" for="ls-notes">Conditions (facultatif)</label><textarea id="ls-notes" v-model="form.notes" rows="2" class="input" placeholder="Ex. véhicule repris après 30 jours de retard" /></div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="busy">Créer le contrat</button>
    </form>
  </Modal>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../Modal.vue'
import { money, today } from '../../utils/format'
import { PERIODS } from '../../utils/vehicles'

const props = defineProps({
  open: { type: Boolean, default: false },
  vehicle: { type: Object, required: true },
  couriers: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'saved'])
const KINDS = [
  { value: 'purchase', label: 'Location-vente', hint: 'Le livreur devient propriétaire une fois le prix payé' },
  { value: 'rental', label: 'Location', hint: 'Le véhicule reste à l\'entreprise' },
]
const form = reactive({})
const busy = ref(false)
const error = ref('')

watch(() => props.open, (open) => {
  if (!open) return
  error.value = ''
  Object.assign(form, { kind: 'purchase', courier_id: props.vehicle.courier?.id ?? null, amount: null, period: 'day', days_per_week: 7, total_amount: null, starts_on: today(), payment: 'payroll', notes: '' })
})

async function save() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/vehicles/${props.vehicle.id}/leases`, { ...form, total_amount: form.kind === 'purchase' ? form.total_amount : null })
    emit('saved', data.data)
    emit('close')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>
