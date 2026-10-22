<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Livreurs</h1>
      <RouterLink v-if="auth.can('users.manage')" to="/admin/utilisateurs" class="btn-secondary">+ Créer un compte livreur</RouterLink>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="c in couriers" :key="c.id" class="card p-4 space-y-2">
        <div class="flex items-center justify-between">
          <div>
            <p class="font-semibold">{{ c.name }}</p>
            <a :href="telLink(c.phone)" class="text-sm text-blue-600">{{ c.phone }}</a>
          </div>
          <span :class="['text-xs rounded-full px-2 py-0.5', c.is_available ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600']">
            {{ c.is_available ? 'Disponible' : 'Indisponible' }}
          </span>
        </div>
        <p class="text-xs text-gray-500">
          Paie : {{ c.pay_plan ? c.pay_plan.name : 'plan par défaut' }}<span v-if="c.pay_plan?.personal"> (personnel)</span>
        </p>
        <p class="text-sm text-gray-600">{{ VEHICLES[c.vehicle_type] }} {{ c.vehicle_plate ? `· ${c.vehicle_plate}` : '' }} · <strong>{{ c.active_assignments_count }}</strong> mission(s) en cours</p>
        <p class="text-sm">Zones : {{ c.zones.map((z) => z.name).join(', ') || 'aucune précisée' }}<span v-if="c.backup_zones?.length" class="text-gray-500"> · secours : {{ c.backup_zones.map((z) => z.name).join(', ') }}</span></p>
        <p v-if="c.last_location_at" class="text-xs text-gray-500">
          Position du {{ dateTime(c.last_location_at) }} · <a :href="mapsLink(c.current_lat, c.current_lng)" target="_blank" class="text-blue-600">voir</a>
        </p>
        <div class="flex gap-3 text-sm">
          <RouterLink :to="{ path: '/admin/courses', query: { courier_id: c.id } }" class="text-blue-600">Courses</RouterLink>
          <button v-if="auth.can('users.manage')" class="text-blue-600" @click="openForm(c)">Modifier</button>
        </div>
      </div>
      <p v-if="!couriers.length" class="text-gray-500">Aucun livreur actif.</p>
    </div>

    <Modal :open="form.open" title="Profil livreur" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <div>
          <label class="label">Véhicule</label>
          <select v-model="form.data.vehicle_type" class="input">
            <option v-for="(label, value) in VEHICLES" :key="value" :value="value">{{ label }}</option>
          </select>
        </div>
        <div><label class="label">Immatriculation</label><input v-model="form.data.vehicle_plate" class="input"></div>
        <div v-if="canPay">
          <label class="label" for="courier-plan">Plan de paie</label>
          <select id="courier-plan" v-model="form.data.pay_plan_id" class="input">
            <option :value="null">Plan par défaut{{ defaultPlan ? ` (${defaultPlan.name})` : '' }}</option>
            <option v-for="p in planOptions" :key="p.id" :value="p.id">{{ p.name }}{{ p.personal ? ' (personnel)' : '' }}</option>
          </select>
          <p class="text-xs text-gray-500 mt-1">
            Les gains déjà acquis ne changent pas.
            <button type="button" class="text-blue-600" @click="customize">{{ personalPlan ? 'Modifier son plan personnel' : 'Personnaliser pour ce livreur' }}</button>
            · <RouterLink to="/admin/paie-livreurs" class="text-blue-600">Gérer les plans</RouterLink>
          </p>
        </div>
        <div v-if="canPay">
          <label class="label" for="courier-accept">Acceptation des missions</label>
          <select id="courier-accept" v-model="form.data.mission_acceptance" class="input">
            <option :value="null">Comme l'entreprise</option>
            <option v-for="m in ACCEPT_MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>
        <div>
          <label class="label">Zones desservies</label>
          <div class="max-h-56 overflow-y-auto border rounded divide-y">
            <div v-for="z in zones" :key="z.id" class="flex items-center justify-between gap-2 px-2 py-1 text-sm">
              <span class="min-w-0 truncate">{{ z.full_name }}</span>
              <select :value="zoneRole(z.id)" class="input w-36 py-1" :aria-label="`Rôle de la zone ${z.full_name}`" @change="setZoneRole(z.id, $event.target.value)">
                <option value="">—</option>
                <option value="main">Principale</option>
                <option value="backup">De secours</option>
              </select>
            </div>
          </div>
          <p class="text-xs text-gray-500 mt-1">Zone de secours : il y livre automatiquement quand personne de la zone principale n'est libre.</p>
        </div>
        <div class="grid grid-cols-3 gap-2">
          <div><label class="label" for="c-parcels">Colis au plus</label><input id="c-parcels" v-model.number="form.data.max_parcels" type="number" min="1" class="input" placeholder="Sans limite"></div>
          <div><label class="label" for="c-cash">Argent en main au plus</label><input id="c-cash" v-model.number="form.data.max_cash" type="number" min="0" step="1000" class="input" placeholder="Sans limite"></div>
          <div><label class="label" for="c-shift">Fin de service</label><input id="c-shift" v-model="form.data.shift_ends_at" type="time" class="input"></div>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.data.is_available" type="checkbox"> Disponible</label>
        <div><label class="label">Notes</label><textarea v-model="form.data.notes" rows="2" class="input" /></div>
        <p v-if="form.error" class="field-error">{{ form.error }}</p>
        <button class="btn-primary w-full">Enregistrer</button>
      </form>
    </Modal>
  </div>
</template>

import { ACCEPT_MODES } from './settings/options'
<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useAuthStore } from '../../stores/auth'
import { dateTime, mapsLink, telLink } from '../../utils/format'

const VEHICLES = { moto: 'Moto', velo: 'Vélo', voiture: 'Voiture', tricycle: 'Tricycle', pieton: 'À pied' }

const auth = useAuthStore()
const couriers = ref([])
const zones = ref([])
const form = reactive({ open: false, id: null, data: {}, error: '' })
const router = useRouter()
const plans = ref([])
const canPay = computed(() => auth.can('settings.manage'))
const defaultPlan = computed(() => plans.value.find((p) => p.is_default))
const personalPlan = computed(() => plans.value.find((p) => p.personal && p.courier?.id === form.id))
// Plans partagés et plan personnel de ce livreur
const planOptions = computed(() => plans.value.filter((p) => !p.is_default && (!p.personal || p.courier?.id === form.id) || p.id === form.data.pay_plan_id))

async function customize() {
  try {
    const plan = personalPlan.value ?? (await http.post('/pay-plans', { courier_id: form.id })).data.data
    router.push({ path: '/admin/paie-livreurs', query: { plan: plan.id } })
  } catch (e) {
    form.error = apiErrorMessage(e)
  }
}

async function load() {
  couriers.value = (await http.get('/couriers')).data.data
}

function openForm(c) {
  form.id = c.id
  form.data = {
    vehicle_type: c.vehicle_type,
    vehicle_plate: c.vehicle_plate,
    ...(canPay.value ? { pay_plan_id: c.pay_plan_id, mission_acceptance: c.mission_acceptance ?? null } : {}),
    is_available: c.is_available,
    notes: c.notes,
    zone_ids: c.zones.map((z) => z.id),
    backup_zone_ids: (c.backup_zones || []).map((z) => z.id),
    max_parcels: c.max_parcels,
    max_cash: c.max_cash,
    shift_ends_at: c.shift_ends_at,
  }
  form.error = ''
  form.open = true
}

const zoneRole = (id) => (form.data.zone_ids?.includes(id) ? 'main' : form.data.backup_zone_ids?.includes(id) ? 'backup' : '')
function setZoneRole(id, role) {
  form.data.zone_ids = form.data.zone_ids.filter((z) => z !== id).concat(role === 'main' ? [id] : [])
  form.data.backup_zone_ids = form.data.backup_zone_ids.filter((z) => z !== id).concat(role === 'backup' ? [id] : [])
}

async function save() {
  try {
    // Champs vidés : sans limite
    const blank = (v) => (v === '' ? null : v)
    await http.patch(`/couriers/${form.id}`, { ...form.data, max_parcels: blank(form.data.max_parcels), max_cash: blank(form.data.max_cash), shift_ends_at: form.data.shift_ends_at || null })
    form.open = false
    load()
  } catch (e) {
    form.error = apiErrorMessage(e)
  }
}

onMounted(async () => {
  load()
  zones.value = (await http.get('/zones')).data.data
  if (canPay.value) plans.value = (await http.get('/pay-plans')).data.data
})
</script>
