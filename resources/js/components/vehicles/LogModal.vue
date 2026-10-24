<template>
  <Modal :open="open" :title="`${kindInfo?.icon || ''} ${title}`" @close="$emit('close')">
    <form class="space-y-3" @submit.prevent="save">
      <div class="flex flex-wrap gap-1.5">
        <button v-for="k in catalog.log_kinds" :key="k.value" type="button" :class="['rounded-full px-3 py-1 text-sm', form.kind === k.value ? 'bg-slate-900 text-white' : 'bg-slate-100']" @click="form.kind = k.value">{{ k.icon }} {{ k.label }}</button>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="l-date">Date</label><input id="l-date" v-model="form.done_on" type="date" class="input"></div>
        <div><label class="label" for="l-odo">Compteur (km)</label><input id="l-odo" v-model.number="form.odometer" type="number" min="0" class="input" :placeholder="String(vehicle.odometer)"></div>
        <div class="col-span-2"><label class="label" for="l-label">{{ form.kind === 'fine' ? 'Motif' : 'Travaux, détail' }}</label><input id="l-label" v-model="form.label" class="input" :placeholder="PLACEHOLDERS[form.kind]"></div>
        <div v-if="['maintenance', 'repair', 'claim'].includes(form.kind)"><label class="label" for="l-garage">Garage</label><input id="l-garage" v-model="form.garage" class="input"></div>
        <div><label class="label" for="l-amount">Montant (F)</label><input id="l-amount" v-model.number="form.amount" type="number" min="0" class="input"></div>
        <div v-if="form.kind === 'fuel'"><label class="label" for="l-liters">Litres</label><input id="l-liters" v-model.number="form.liters" type="number" min="0" step="0.1" class="input"></div>
        <div>
          <label class="label" for="l-paid">Payé par</label>
          <select id="l-paid" v-model="form.paid_by" class="input"><option value="company">L'entreprise</option><option value="courier">Le livreur</option></select>
        </div>
        <div v-if="['maintenance', 'repair', 'claim'].includes(form.kind)"><label class="label" for="l-immo">Jours d'immobilisation</label><input id="l-immo" v-model.number="form.immobilized_days" type="number" min="0" class="input"></div>
        <div v-if="['fine', 'claim', 'fuel'].includes(form.kind)">
          <label class="label" for="l-courier">Livreur</label>
          <select id="l-courier" v-model="form.courier_id" class="input">
            <option :value="null">Celui qui avait le véhicule ce jour-là</option>
            <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <div v-if="form.kind === 'claim' && settings.damage_charge_courier">
          <label class="label" for="l-share">Part retenue au livreur (F)</label><input id="l-share" v-model.number="form.courier_share" type="number" min="0" class="input">
          <p class="text-xs text-gray-500 mt-1">Dans la limite de {{ money(settings.damage_cap_month) }} par mois.</p>
        </div>
      </div>
      <label v-if="form.kind === 'fine' && settings.fines_charge_courier" class="flex items-center gap-2 text-sm"><input v-model="form.charge_courier" type="checkbox"> Retenir l'amende sur la paie du livreur</label>
      <p v-if="form.kind === 'fuel' && form.paid_by === 'courier'" class="text-xs text-gray-500">{{ FUEL_NOTE[settings.fuel_mode] }}</p>

      <div v-if="form.kind === 'maintenance' && plan.length">
        <p class="label">Entretien du plan fait</p>
        <div class="flex flex-wrap gap-x-4 gap-y-1">
          <label v-for="p in plan" :key="p.key" class="flex items-center gap-1.5 text-sm"><input v-model="form.plan_keys" type="checkbox" :value="p.key"> {{ p.icon }} {{ p.label }}</label>
        </div>
      </div>
      <div>
        <label class="label" for="l-status">Ensuite, le véhicule est</label>
        <select id="l-status" v-model="form.status" class="input">
          <option value="">Inchangé ({{ vehicle.status_label }})</option>
          <option v-for="s in catalog.statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </div>
      <div><label class="label" for="l-photos">Facture, reçu, photos</label><input id="l-photos" type="file" multiple accept="image/*,application/pdf" class="text-sm" @change="files = [...$event.target.files]"></div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="busy">Enregistrer</button>
    </form>
  </Modal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../Modal.vue'
import { money, today } from '../../utils/format'

const props = defineProps({
  open: { type: Boolean, default: false },
  kind: { type: String, default: 'maintenance' },
  vehicle: { type: Object, required: true },
  couriers: { type: Array, default: () => [] },
  catalog: { type: Object, required: true },
  settings: { type: Object, required: true },
})
const emit = defineEmits(['close', 'saved'])
const PLACEHOLDERS = { maintenance: 'Vidange + filtre à air', repair: 'Changement d\'embrayage', claim: 'Accrochage, rétroviseur et carénage', fine: 'Stationnement interdit, Plateau', fuel: 'Plein', other: 'Lavage, accessoires…' }
const FUEL_NOTE = {
  refund: 'Remboursé au livreur sur sa paie (mode remboursement).',
  allowance: 'Mode dotation : seul le dépassement de la dotation du jour est remboursé, si l\'entreprise le veut.',
  courier: 'Carburant à la charge du livreur : rien sur sa paie.',
}
const form = reactive({})
const files = ref([])
const busy = ref(false)
const error = ref('')
const kindInfo = computed(() => props.catalog.log_kinds.find((k) => k.value === form.kind))
const title = computed(() => `${kindInfo.value?.label || 'Dépense'} · ${props.vehicle.plate}`)
const plan = computed(() => props.vehicle.maintenance || [])

watch(() => props.open, (open) => {
  if (!open) return
  error.value = ''
  files.value = []
  Object.assign(form, { kind: props.kind, done_on: today(), odometer: null, label: '', garage: '', amount: null, liters: null, paid_by: 'company',
    courier_id: null, courier_share: null, charge_courier: true, plan_keys: [], immobilized_days: null, status: '' })
})

async function save() {
  busy.value = true
  error.value = ''
  const body = new FormData()
  Object.entries(form).forEach(([k, v]) => {
    if (v === null || v === '') return
    if (Array.isArray(v)) v.forEach((x) => body.append(`${k}[]`, x))
    else body.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : v)
  })
  files.value.forEach((f) => body.append('photos[]', f))
  try {
    const { data } = await http.post(`/vehicles/${props.vehicle.id}/logs`, body)
    emit('saved', data.data)
    emit('close')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>
