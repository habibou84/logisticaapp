<template>
  <!-- Profil d'un livreur : véhicule, paie, missions, zones, limites, ce qu'il fait (page Livreurs et fiche du livreur) -->
  <Modal :open="open" :title="`Profil livreur${courier?.name ? ` : ${courier.name}` : ''}`" @close="$emit('close')">
    <form class="space-y-3" @submit.prevent="save">
      <p v-if="vehicle" class="rounded-lg bg-slate-50 px-3 py-2 text-sm">
        Véhicule du parc :
        <RouterLink v-if="auth.can('fleet.manage')" :to="`/admin/vehicules/${vehicle.id}`" class="text-blue-600">{{ vehicle.label }}</RouterLink>
        <template v-else>{{ vehicle.label }}</template>
        (à changer dans sa fiche)
      </p>
      <template v-else>
        <div>
          <label class="label" for="cf-vehicle">Véhicule</label>
          <select id="cf-vehicle" v-model="data.vehicle_type" class="input">
            <option v-for="(label, value) in VEHICLES" :key="value" :value="value">{{ label }}</option>
          </select>
        </div>
        <div><label class="label" for="cf-plate">Immatriculation</label><input id="cf-plate" v-model="data.vehicle_plate" class="input"></div>
      </template>
      <div v-if="canPay">
        <label class="label" for="courier-plan">Plan de paie</label>
        <select id="courier-plan" v-model="data.pay_plan_id" class="input">
          <option :value="null">Plan par défaut{{ defaultPlan ? ` (${defaultPlan.name})` : '' }}</option>
          <option v-for="p in planOptions" :key="p.id" :value="p.id">{{ p.name }}{{ p.personal ? ' (personnel)' : '' }}</option>
        </select>
        <p class="mt-1 text-xs text-gray-500">
          Les gains déjà acquis ne changent pas.
          <button type="button" class="text-blue-600" @click="customize">{{ personalPlan ? 'Modifier son plan personnel' : 'Personnaliser pour ce livreur' }}</button>
          · <RouterLink to="/admin/paie-livreurs" class="text-blue-600">Gérer les plans</RouterLink>
        </p>
      </div>
      <div v-if="auth.can('planning.manage')">
        <label class="label" for="courier-accept">Acceptation des missions</label>
        <select id="courier-accept" v-model="data.mission_acceptance" class="input">
          <option :value="null">Comme l'entreprise</option>
          <option v-for="m in ACCEPT_MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
        </select>
      </div>
      <div>
        <label class="label">Zones desservies</label>
        <div class="max-h-56 divide-y overflow-y-auto rounded border">
          <div v-for="z in zones" :key="z.id" class="flex items-center justify-between gap-2 px-2 py-1 text-sm">
            <span class="min-w-0 truncate">{{ z.full_name }}</span>
            <select :value="zoneRole(z.id)" class="input w-36 py-1" :aria-label="`Rôle de la zone ${z.full_name}`" @change="setZoneRole(z.id, $event.target.value)">
              <option value="">—</option>
              <option value="main">Principale</option>
              <option value="backup">De secours</option>
            </select>
          </div>
        </div>
        <p class="mt-1 text-xs text-gray-500">Zone de secours : il y livre automatiquement quand personne de la zone principale n'est libre.</p>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <div><label class="label" for="c-parcels">Colis au plus</label><input id="c-parcels" v-model.number="data.max_parcels" type="number" min="1" class="input" placeholder="Sans limite"></div>
        <div><label class="label" for="c-cash">Argent en main au plus</label><input id="c-cash" v-model.number="data.max_cash" type="number" min="0" step="1000" class="input" placeholder="Sans limite"></div>
        <div><label class="label" for="c-shift">Fin de service</label><input id="c-shift" v-model="data.shift_ends_at" type="time" class="input"></div>
      </div>
      <!-- Capacités propres au livreur : il ne reçoit pas les courses qu'il ne fait pas -->
      <fieldset class="space-y-2 rounded border p-3">
        <legend class="px-1 text-sm font-medium">Ce qu'il fait</legend>
        <div v-for="(label, key) in CAPABILITIES" :key="key" class="flex items-center justify-between gap-2 text-sm">
          <span>{{ label }}</span>
          <select v-model="data.capabilities[key]" class="input w-48 py-1" :aria-label="label">
            <option :value="null">Comme l'entreprise ({{ defaults[key] ? 'oui' : 'non' }})</option>
            <option :value="true">Oui</option>
            <option :value="false">Non</option>
          </select>
        </div>
      </fieldset>
      <label class="flex items-center gap-2 text-sm"><input v-model="data.is_available" type="checkbox"> Disponible</label>
      <div><label class="label" for="c-notes">Notes internes</label><textarea id="c-notes" v-model="data.notes" rows="2" class="input" /></div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="saving">Enregistrer</button>
    </form>
  </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { ACCEPT_MODES } from '../views/admin/settings/options'
import Modal from './Modal.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  // Livreur tel que renvoyé par GET /couriers/{id}
  courier: { type: Object, default: null },
  // Véhicule du parc qu'il a en ce moment ({ id, label }), s'il y en a un
  vehicle: { type: Object, default: null },
})
const emit = defineEmits(['close', 'saved'])

const VEHICLES = { moto: 'Moto', velo: 'Vélo', voiture: 'Voiture', tricycle: 'Tricycle', pieton: 'À pied' }
const CAPABILITIES = {
  collect_cash: 'Encaisse l\'argent des colis',
  mad: 'Fait la mise à disposition (MAD)',
  interior: 'Fait l\'intérieur (expéditions)',
  offline: 'Travaille hors connexion',
  see_gain: 'Voit son gain prévu',
}

const auth = useAuthStore()
const router = useRouter()
const canPay = computed(() => auth.can('pay.manage'))
const data = ref({ capabilities: {} })
const defaults = ref({})
const zones = ref([])
const plans = ref([])
const error = ref('')
const saving = ref(false)

const id = computed(() => props.courier?.id)
const defaultPlan = computed(() => plans.value.find((p) => p.is_default))
const personalPlan = computed(() => plans.value.find((p) => p.personal && p.courier?.id === id.value))
// Plans partagés et plan personnel de ce livreur
const planOptions = computed(() => plans.value.filter((p) => (!p.is_default && (!p.personal || p.courier?.id === id.value)) || p.id === data.value.pay_plan_id))

// Listes chargées à la première ouverture
let listsLoaded = false
async function loadLists() {
  if (listsLoaded) return
  listsLoaded = true
  const [z, p] = await Promise.all([
    http.get('/zones'),
    canPay.value ? http.get('/pay-plans') : null,
  ])
  zones.value = z.data.data
  plans.value = p?.data.data || []
}

watch(() => [props.open, props.courier], ([open, c]) => {
  if (!open || !c) return
  loadLists().catch(() => {})
  error.value = ''
  data.value = {
    vehicle_type: c.vehicle_type,
    vehicle_plate: c.vehicle_plate,
    ...(canPay.value ? { pay_plan_id: c.pay_plan_id } : {}),
    ...(auth.can('planning.manage') ? { mission_acceptance: c.mission_acceptance ?? null } : {}),
    capabilities: Object.fromEntries(Object.keys(CAPABILITIES).map((k) => [k, c.capabilities?.[k] ?? null])),
    is_available: c.is_available,
    notes: c.notes,
    zone_ids: (c.zones || []).map((z) => z.id),
    backup_zone_ids: (c.backup_zones || []).map((z) => z.id),
    max_parcels: c.max_parcels,
    max_cash: c.max_cash,
    shift_ends_at: c.shift_ends_at ? String(c.shift_ends_at).slice(0, 5) : null,
  }
  // Valeur de l'entreprise : celle du livreur quand il n'a rien de propre
  defaults.value = Object.fromEntries(Object.keys(CAPABILITIES).map((k) => [k, c.capabilities?.[k] == null ? c.can?.[k] : k === 'offline' ? !!auth.user?.company?.offline_courier_actions : true]))
}, { immediate: true })

const zoneRole = (z) => (data.value.zone_ids?.includes(z) ? 'main' : data.value.backup_zone_ids?.includes(z) ? 'backup' : '')
function setZoneRole(z, role) {
  data.value.zone_ids = data.value.zone_ids.filter((x) => x !== z).concat(role === 'main' ? [z] : [])
  data.value.backup_zone_ids = data.value.backup_zone_ids.filter((x) => x !== z).concat(role === 'backup' ? [z] : [])
}

async function customize() {
  try {
    const plan = personalPlan.value ?? (await http.post('/pay-plans', { courier_id: id.value })).data.data
    router.push({ path: '/admin/paie-livreurs', query: { plan: plan.id } })
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    // Champs vidés : sans limite
    const blank = (v) => (v === '' ? null : v)
    const payload = { ...data.value, max_parcels: blank(data.value.max_parcels), max_cash: blank(data.value.max_cash), shift_ends_at: data.value.shift_ends_at || null }
    // Véhicule du parc : son type et sa plaque se changent dans sa fiche
    if (props.vehicle) {
      delete payload.vehicle_type
      delete payload.vehicle_plate
    }
    await http.patch(`/couriers/${id.value}`, payload)
    emit('saved')
    emit('close')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
