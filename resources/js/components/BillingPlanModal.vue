<template>
  <Modal :open="open" :title="`Contrat · ${merchant?.business_name || ''}`" @close="$emit('close')">
    <div class="space-y-4">
      <p class="text-sm text-gray-600">
        Sans formule, le client paie ses courses une à une (frais déduits de ses reversements). Avec une formule, ses courses
        passent sur sa <strong>facture du mois</strong>, payée à part.
      </p>

      <p v-if="loading" class="text-gray-500 text-sm">Chargement…</p>

      <ul v-else-if="plans.length" class="divide-y rounded border text-sm">
        <li v-for="p in plans" :key="p.id" class="p-3 space-y-1">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium">{{ p.formula_label }}</span>
            <span :class="isCurrent(p) ? 'text-emerald-700' : 'text-gray-500'">
              {{ isCurrent(p) ? 'En cours' : p.starts_on > today() ? 'À venir' : 'Terminée' }} · du {{ date(p.starts_on) }}{{ p.ends_on ? ` au ${date(p.ends_on)}` : '' }}
            </span>
          </div>
          <p class="text-gray-600">{{ p.summary }}</p>
          <p v-if="p.cheque_price || p.extra_kg_price" class="text-gray-600">
            <template v-if="p.cheque_price">Chèque : {{ money(p.cheque_price) }}</template>
            <template v-if="p.cheque_price && p.extra_kg_price"> · </template>
            <template v-if="p.extra_kg_price">kg au-delà de 5 kg : {{ money(p.extra_kg_price) }}</template>
          </p>
          <p v-if="p.has_forfait" class="text-gray-600">
            Zones du forfait : {{ p.zones?.length ? zoneNames(p.zones) : 'communes « Forfait » (Réglages › Zones)' }}
          </p>
          <p v-if="p.internal_delay_hours" class="text-gray-600">Délai interne : {{ p.internal_delay_hours }} h (équipe)</p>
          <p v-if="p.couriers?.length" class="text-gray-600">Livreurs mis à disposition : {{ p.couriers.map((c) => c.name).join(', ') }}</p>
          <div v-if="canManage && !p.ends_on" class="flex flex-wrap gap-3 pt-1">
            <button type="button" class="text-blue-600" @click="endPlan(p)">Terminer…</button>
            <button v-if="p.has_forfait" type="button" class="text-blue-600" @click="editZones(p)">Changer les zones</button>
            <button type="button" class="text-blue-600" @click="editInternalDelay(p)">Délai interne…</button>
            <button v-if="p.formula === 'mad_contract'" type="button" class="text-blue-600" @click="editCouriers(p)">Changer les livreurs</button>
          </div>
        </li>
      </ul>
      <p v-else class="text-sm text-gray-500">Aucune formule : courses ordinaires.</p>

      <form v-if="canManage" class="space-y-3 border-t pt-3" @submit.prevent="save">
        <h3 class="font-semibold">{{ editing ? (editMode === 'zones' ? 'Zones couvertes par le forfait' : 'Livreurs mis à disposition') : 'Nouveau contrat' }}</h3>
        <div v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</div>

        <template v-if="!editing">
          <div>
            <label class="label" for="bp-formula">Formule</label>
            <select id="bp-formula" v-model="form.formula" class="input">
              <option v-for="f in formulas" :key="f.value" :value="f.value">{{ f.label }}</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <template v-if="hasForfait">
              <div><label class="label" for="bp-fee">Forfait mensuel (F)</label><input id="bp-fee" v-model.number="form.monthly_fee" type="number" min="0" class="input" required></div>
              <div>
                <label class="label" for="bp-qty">Courses incluses par mois</label>
                <input id="bp-qty" v-model.number="form.included_quantity" type="number" min="0" class="input" required>
              </div>
              <div>
                <label class="label" for="bp-over">{{ form.formula === 'mad_contract' ? 'Prix d\'une course en plus (F)' : 'Prix d\'une course en plus à Abidjan (F)' }}</label>
                <input id="bp-over" v-model.number="form.overage_price" type="number" min="0" class="input">
              </div>
            </template>
            <div>
              <label class="label" for="bp-delay">{{ hasForfait ? 'Délai de livraison du contrat' : 'Délai par défaut' }}</label>
              <select id="bp-delay" v-model="form.delivery_delay" class="input">
                <option value="24h">24 h</option>
                <option value="48h">48 h</option>
                <option value="72h">72 h</option>
              </select>
            </div>
            <div v-if="form.formula === 'distribution_contract'">
              <label class="label" for="bp-cheque">Prix d'un chèque livré (F)</label>
              <input id="bp-cheque" v-model.number="form.cheque_price" type="number" min="0" class="input">
            </div>
            <div>
              <label class="label" for="bp-kg">Kilogramme au-delà de 5 kg, Abidjan (F)</label>
              <input id="bp-kg" v-model.number="form.extra_kg_price" type="number" min="0" class="input">
            </div>
            <div>
              <label class="label" for="bp-internal">Délai interne (heures, facultatif)</label>
              <input id="bp-internal" v-model.number="form.internal_delay_hours" type="number" min="1" max="2000" class="input" placeholder="Celui de l'activité">
            </div>
            <div><label class="label" for="bp-start">À partir du</label><input id="bp-start" v-model="form.starts_on" type="date" class="input" required></div>
            <div><label class="label" for="bp-end">Jusqu'au (facultatif)</label><input id="bp-end" v-model="form.ends_on" type="date" class="input" :min="form.starts_on"></div>
          </div>
          <p class="text-xs text-gray-500">
            <template v-if="!hasForfait">Prix à l'unité par délai et type d'envoi : Distribution › Tarifs (grille commune ou prix négociés du client) ; </template>
            Un nouveau contrat arrête le précédent la veille de son début.
          </p>
        </template>

        <!-- Zones du forfait : courses incluses ; au-delà, prix de la destination. Ailleurs : facturé à part -->
        <fieldset v-if="(hasForfait && !editing) || editMode === 'zones'" class="border rounded p-3 space-y-2">
          <legend class="text-sm font-medium px-1">Zones couvertes par le forfait</legend>
          <p class="text-xs text-gray-500">
            Les courses vers ces zones comptent dans les courses incluses ; au-delà, chacune au prix de sa destination
            (prix du contrat à Abidjan, tarif de la ville à l'intérieur). Vers les autres zones : facturées à part dès la première
            (tarif de la ville à l'intérieur, tarif ordinaire ailleurs).
          </p>
          <ZoneCoverage v-model="form.zone_ids" :zones="zones" />
        </fieldset>

        <fieldset v-if="(form.formula === 'mad_contract' && !editing) || editMode === 'couriers'" class="border rounded p-3 space-y-2">
          <legend class="text-sm font-medium px-1">Livreurs mis à disposition</legend>
          <p class="text-xs text-gray-500">Les courses du client leur sont confiées (ramassage et livraison), au moins chargé des disponibles.</p>
          <label v-for="c in couriers" :key="c.id" class="flex items-center gap-2 text-sm">
            <input v-model="form.courier_ids" type="checkbox" :value="c.id"> {{ c.user?.name || c.name }}
          </label>
          <p v-if="!couriers.length" class="text-sm text-gray-500">Aucun livreur.</p>
        </fieldset>

        <div v-if="!editing"><label class="label" for="bp-notes">Notes (référence du contrat…)</label><input id="bp-notes" v-model="form.notes" class="input" maxlength="1000"></div>

        <div class="flex gap-2">
          <button class="btn-primary flex-1" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
          <button v-if="editing" type="button" class="btn-secondary" @click="reset">Annuler</button>
        </div>
      </form>
    </div>
  </Modal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import Modal from './Modal.vue'
import ZoneCoverage from './ZoneCoverage.vue'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toasts'
import { date, money, today } from '../utils/format'
import { myActivities } from '../utils/activities'

const props = defineProps({ open: Boolean, merchant: { type: Object, default: null } })
const emit = defineEmits(['close', 'saved'])

const auth = useAuthStore()
const toasts = useToastStore()
// Formules de son périmètre (un responsable distribution ne crée pas de formule MAD)
const FORMULAS = [
  { value: 'distribution_contract', activity: 'distribution', label: 'Distribution sous contrat (forfait mensuel)' },
  { value: 'distribution_unit', activity: 'distribution', label: 'Distribution à l\'unité (sans contrat)' },
  { value: 'mad_contract', activity: 'mad', label: 'Mise à disposition de livreurs (MAD)' },
]
const formulas = computed(() => FORMULAS.filter((f) => myActivities(auth.user).some((a) => a.value === f.activity)))
const canManage = computed(() => auth.can('rates.manage') && formulas.value.length > 0)
const plans = ref([])
const couriers = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const editing = ref(null)
// Modification d'un contrat en cours : 'zones' ou 'couriers'
const editMode = ref(null)
const zones = ref([])
// Zones par défaut d'un nouveau contrat : les communes marquées « Forfait »
const defaultZones = () => zones.value.filter((z) => !z.parent_id && z.in_forfait).map((z) => z.id)
const zoneNames = (list) => (list.length > 6 ? `${list.slice(0, 6).map((z) => z.name).join(', ')} et ${list.length - 6} autre(s)` : list.map((z) => z.name).join(', '))
const form = reactive({})
const hasForfait = computed(() => form.formula !== 'distribution_unit')

const isCurrent = (p) => p.starts_on <= today() && (!p.ends_on || p.ends_on >= today())

function reset() {
  editing.value = null
  editMode.value = null
  error.value = ''
  Object.assign(form, {
    formula: formulas.value[0]?.value ?? 'distribution_contract', monthly_fee: 0, included_quantity: 0, overage_price: 0, delivery_delay: '24h',
    cheque_price: 0, extra_kg_price: 0, internal_delay_hours: null, starts_on: today(), ends_on: '', notes: '', courier_ids: [], zone_ids: defaultZones(),
  })
}

async function load() {
  loading.value = true
  try {
    plans.value = (await http.get(`/merchants/${props.merchant.id}/billing-plans`)).data.data
    if (canManage.value && !couriers.value.length) couriers.value = (await http.get('/couriers')).data.data
    if (canManage.value && !zones.value.length) {
      zones.value = (await http.get('/zones')).data.data
      if (!editing.value) form.zone_ids = defaultZones()
    }
  } finally {
    loading.value = false
  }
}

watch(() => [props.open, props.merchant?.id], ([open]) => {
  if (open && props.merchant) {
    reset()
    load()
  }
}, { immediate: true })

function editZones(plan) {
  reset()
  editing.value = plan
  editMode.value = 'zones'
  form.formula = plan.formula
  form.zone_ids = plan.zones?.length ? plan.zones.map((z) => z.id) : defaultZones()
}

function editCouriers(plan) {
  reset()
  editing.value = plan
  editMode.value = 'couriers'
  form.formula = plan.formula
  form.courier_ids = (plan.couriers || []).map((c) => c.id)
}

// Délai interne propre au contrat (vide : celui de l'activité, Réglages › Courses)
async function editInternalDelay(plan) {
  const value = window.prompt('Délai interne de ce contrat, en heures (vide : celui de l\'activité) :', plan.internal_delay_hours ?? '')
  if (value === null) return
  try {
    await http.patch(`/billing-plans/${plan.id}`, { internal_delay_hours: value.trim() === '' ? null : Number(value) })
    toasts.success('Délai interne enregistré.')
    await load()
    emit('saved')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function endPlan(plan) {
  const end = window.prompt('Dernier jour de la formule (AAAA-MM-JJ) :', today())
  if (!end) return
  try {
    await http.patch(`/billing-plans/${plan.id}`, { ends_on: end })
    toasts.success('Formule terminée.')
    await load()
    emit('saved')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    if (editing.value) {
      await http.patch(`/billing-plans/${editing.value.id}`, editMode.value === 'zones' ? { zone_ids: form.zone_ids } : { courier_ids: form.courier_ids })
    } else {
      const payload = { ...form, ends_on: form.ends_on || null, notes: form.notes || null }
      if (form.formula !== 'mad_contract') delete payload.courier_ids
      if (!hasForfait.value) delete payload.zone_ids
      await http.post(`/merchants/${props.merchant.id}/billing-plans`, payload)
    }
    toasts.success('Formule enregistrée.')
    reset()
    await load()
    emit('saved')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
