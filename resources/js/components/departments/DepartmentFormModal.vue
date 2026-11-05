<template>
  <!-- Ajout ou modification d'un département : l'essentiel d'abord, le reste dans « Plus d'options » ; puis qui y travaille -->
  <BottomSheet :open="open" :title="step === 'people' ? form.name : (department ? `Modifier ${department.name}` : 'Nouveau département')" @close="$emit('close')">
    <div v-if="!department" class="mb-4 flex gap-1.5" aria-hidden="true">
      <span class="h-1 flex-1 rounded-full bg-[var(--app-color,#e11d2a)]" />
      <span :class="['h-1 flex-1 rounded-full', step === 'people' ? 'bg-[var(--app-color,#e11d2a)]' : 'bg-slate-200']" />
    </div>

    <!-- Étape 1 : le département -->
    <form v-if="step === 'details'" novalidate class="space-y-4" @submit.prevent="save">
      <div class="grid grid-cols-[1fr_6rem] gap-3">
        <div>
          <label class="label" for="d-name">Nom du département</label>
          <input id="d-name" v-model="form.name" :class="['input rounded-xl py-2.5', errors.name && '!border-red-400 !bg-red-50']" maxlength="120" placeholder="ex. Finance" autocomplete="off" @input="suggestCode">
          <p v-if="errors.name" class="field-error">{{ errors.name }}</p>
        </div>
        <div>
          <label class="label" for="d-code">Code</label>
          <input id="d-code" v-model="form.code" :class="['input rounded-xl py-2.5 uppercase', errors.code && '!border-red-400 !bg-red-50']" maxlength="12" placeholder="ex. FIN" @input="codeTouched = true">
          <p v-if="errors.code" class="field-error">{{ errors.code }}</p>
        </div>
      </div>
      <p class="-mt-2 text-xs text-gray-500">Le code est proposé à partir du nom ; il apparaît sur les étiquettes et les factures.</p>

      <!-- Responsable : un compte du client, ou un nom saisi -->
      <div>
        <p class="label">Responsable <span class="font-normal text-gray-400">(facultatif)</span></p>
        <div v-if="accounts.length && !typedManager" class="space-y-2">
          <button
            v-for="u in accounts.slice(0, showAllAccounts ? undefined : 4)" :key="u.id" type="button"
            :class="['flex w-full items-center gap-3 rounded-2xl border-2 px-3 py-2 text-left', managerId === u.id ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200']"
            :aria-pressed="managerId === u.id" @click="chooseManager(u)"
          >
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-800">{{ initials(u.name) }}</span>
            <span class="min-w-0 flex-1"><b class="block truncate text-sm">{{ u.name }}</b><span class="text-xs text-gray-500">{{ u.owner ? 'propriétaire du compte' : (u.role_label || 'compte de votre équipe') }}</span></span>
            <span :class="['grid h-6 w-6 place-items-center rounded-lg border-2 text-sm', managerId === u.id ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300']">{{ managerId === u.id ? '✓' : '' }}</span>
          </button>
          <p class="text-xs text-gray-500">
            <button v-if="accounts.length > 4 && !showAllAccounts" type="button" class="font-semibold text-blue-600" @click="showAllAccounts = true">Voir les {{ accounts.length }} comptes</button>
            <template v-if="accounts.length > 4 && !showAllAccounts"> · </template>
            Pas dans la liste ? <button type="button" class="font-semibold text-blue-600" @click="typeManager">Saisir un nom</button>
          </p>
        </div>
        <template v-else>
          <input id="d-manager" v-model="form.manager_name" class="input rounded-xl py-2.5" maxlength="120" placeholder="ex. Mme Aya Koné" aria-label="Nom du responsable">
          <button v-if="accounts.length" type="button" class="mt-1 text-xs font-semibold text-blue-600" @click="typedManager = false">Choisir parmi vos comptes</button>
        </template>
      </div>

      <div>
        <label class="label" for="d-phone">Téléphone du responsable <span class="font-normal text-gray-400">(facultatif)</span></label>
        <input id="d-phone" v-model="form.phone" :class="['input rounded-xl py-2.5', errors.phone && '!border-red-400 !bg-red-50']" inputmode="tel" placeholder="ex. 07 00 00 00 00">
        <p v-if="errors.phone" class="field-error">{{ errors.phone }}</p>
      </div>

      <!-- Où se trouve-t-il ? -->
      <div>
        <p class="label">Où se trouve-t-il ?</p>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" :class="choiceClass(!ownAddress)" :aria-pressed="!ownAddress" @click="ownAddress = false">
            <b class="block text-sm">🏢 Dans nos locaux</b><span class="text-xs text-gray-500">même adresse que {{ staff ? 'le client' : 'vous' }}</span>
          </button>
          <button type="button" :class="choiceClass(ownAddress)" :aria-pressed="ownAddress" @click="ownAddress = true">
            <b class="block text-sm">📍 Sur un autre site</b><span class="text-xs text-gray-500">ramassage et remise sur place</span>
          </button>
        </div>
      </div>
      <template v-if="ownAddress">
        <div>
          <label class="label" for="d-zone">Commune ou quartier</label>
          <ZoneSelect id="d-zone" v-model="form.pickup_zone_id" :zones="zones" />
          <p v-if="errors.pickup_zone_id" class="field-error">{{ errors.pickup_zone_id }}</p>
        </div>
        <div>
          <label class="label" for="d-address">Adresse</label>
          <input id="d-address" v-model="form.pickup_address" :class="['input rounded-xl py-2.5', errors.pickup_address && '!border-red-400 !bg-red-50']" maxlength="500" placeholder="ex. Avenue Chardy, immeuble Alpha 2000">
          <p v-if="errors.pickup_address" class="field-error">{{ errors.pickup_address }}</p>
        </div>
        <div><label class="label" for="d-landmark">Repère <span class="font-normal text-gray-400">(facultatif)</span></label><input id="d-landmark" v-model="form.pickup_landmark" class="input rounded-xl py-2.5" maxlength="255" placeholder="ex. en face de la pharmacie"></div>
        <div>
          <p class="label">Position <span class="font-normal text-gray-400">(conseillée)</span></p>
          <LocationPicker v-model="point" height="h-48" gps-label="📍 Je suis sur place : utiliser ma position" />
        </div>
      </template>
      <div>
        <label class="label" for="d-location">Bâtiment, étage, bureau <span class="font-normal text-gray-400">(facultatif)</span></label>
        <input id="d-location" v-model="form.location" class="input rounded-xl py-2.5" maxlength="255" placeholder="ex. Bâtiment A, 3e étage, bureau 302">
      </div>

      <!-- Plus d'options -->
      <div class="border-t border-slate-100 pt-3">
        <button type="button" class="flex w-full items-center justify-between text-left font-semibold text-slate-700" :aria-expanded="moreOpen" @click="moreOpen = !moreOpen">
          Plus d'options
          <span class="text-xs font-normal text-gray-500">{{ moreOpen ? 'masquer ⌃' : 'centre de coûts, quota, e-mail ›' }}</span>
        </button>
        <div v-if="moreOpen" class="mt-3 space-y-4">
          <div>
            <label class="label" for="d-cc">Centre de coûts <span class="font-normal text-gray-400">(facultatif)</span></label>
            <input id="d-cc" v-model="form.cost_center" class="input rounded-xl py-2.5" maxlength="60" placeholder="ex. CC-100">
            <p class="mt-1 text-xs text-gray-500">Le code de {{ staff ? 'sa' : 'votre' }} comptabilité. Il apparaît sur la facture de ce département.</p>
          </div>
          <div>
            <label class="label" for="d-quota">Envois prévus par mois <span class="font-normal text-gray-400">(facultatif)</span></label>
            <input id="d-quota" v-model.number="form.monthly_quota" type="number" min="1" inputmode="numeric" class="input rounded-xl py-2.5" placeholder="ex. 50 · vide : pas de limite">
            <p class="mt-1 text-xs text-gray-500">{{ staff ? 'Le client est prévenu' : 'Vous êtes prévenu' }} à 80 % et à 100 %.</p>
          </div>
          <div>
            <label class="label" for="d-email">E-mail du département <span class="font-normal text-gray-400">(facultatif)</span></label>
            <input id="d-email" v-model="form.email" type="email" :class="['input rounded-xl py-2.5', errors.email && '!border-red-400 !bg-red-50']" maxlength="190" placeholder="ex. finance@entreprise.ci">
            <p v-if="errors.email" class="field-error">{{ errors.email }}</p>
          </div>
          <label class="flex items-start gap-3 text-sm">
            <input v-model="form.is_main" type="checkbox" class="mt-1">
            <span><b>Département principal</b><span class="block text-xs text-gray-500">Il reçoit la facture des envois qui ne sont rattachés à aucun département.</span></span>
          </label>
          <label v-if="department" class="flex items-start gap-3 text-sm">
            <input v-model="form.active" type="checkbox" class="mt-1">
            <span><b>Actif</b><span class="block text-xs text-gray-500">Désactivé, il reste sur les anciennes courses mais ne se choisit plus.</span></span>
          </label>
          <div v-if="staff"><label class="label" for="d-notes">Notes (internes à l'agence)</label><textarea id="d-notes" v-model="form.notes" rows="2" class="input rounded-xl py-2.5" /></div>
        </div>
      </div>

      <p v-if="error" class="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
      <div class="sticky bottom-0 -mx-5 border-t border-slate-100 bg-white px-5 pb-1 pt-3">
        <button class="btn-primary w-full py-3" :disabled="busy">{{ busy ? 'Enregistrement…' : department ? 'Enregistrer' : (people.length ? 'Continuer' : 'Ajouter le département') }}</button>
      </div>
    </form>

    <!-- Étape 2 : qui travaille dans ce département -->
    <div v-else class="space-y-3">
      <p class="flex items-center gap-2 rounded-2xl bg-emerald-50 px-3 py-2.5 text-sm text-emerald-900">
        ✅ <span><b>Département ajouté.</b> Choisissez maintenant qui envoie pour lui.</span>
      </p>
      <div v-for="u in people" :key="u.id" :class="['rounded-2xl border-2 px-3 py-2', members[u.id] ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200']">
        <label class="flex items-center gap-3">
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-800">{{ initials(u.name) }}</span>
          <span class="min-w-0 flex-1"><b class="block truncate text-sm">{{ u.name }}</b><span class="text-xs text-gray-500">{{ u.role_label || 'compte' }}<template v-if="otherDepartments(u)"> · {{ otherDepartments(u) }}</template></span></span>
          <input v-model="members[u.id]" type="checkbox" class="h-5 w-5" :aria-label="`${u.name} travaille dans ce département`">
        </label>
        <label v-if="settings.head_approval && members[u.id]" class="ml-11 mt-1 flex items-center gap-2 text-xs font-semibold text-emerald-800">
          <input v-model="heads[u.id]" type="checkbox"> Chef de service (valide les demandes des agents)
        </label>
      </div>
      <p class="text-xs text-gray-500">
        {{ settings.staff_own_only ? 'Les comptes cochés ne voient que les courses de leurs départements.' : 'Les comptes cochés envoient pour ce département.' }}
        Les propriétaires et gestionnaires voient déjà tout.
      </p>
      <p v-if="error" class="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
      <div class="sticky bottom-0 -mx-5 border-t border-slate-100 bg-white px-5 pb-1 pt-3">
        <button type="button" class="btn-primary w-full py-3" :disabled="busy" @click="savePeople">{{ busy ? 'Enregistrement…' : chosenCount ? `Enregistrer · ${chosenCount} compte${chosenCount > 1 ? 's' : ''}` : 'Terminer' }}</button>
        <button type="button" class="mt-2 w-full py-1 text-sm text-gray-600" @click="finish">Plus tard</button>
      </div>
    </div>
  </BottomSheet>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BottomSheet from '../mobile/BottomSheet.vue'
import LocationPicker from '../LocationPicker.vue'
import ZoneSelect from '../ZoneSelect.vue'

const props = defineProps({
  open: Boolean,
  merchantId: { type: Number, required: true },
  department: { type: Object, default: null },
  zones: { type: Array, default: () => [] },
  // Comptes du client (choix du responsable, étape « qui y travaille »)
  users: { type: Array, default: () => [] },
  departments: { type: Array, default: () => [] },
  settings: { type: Object, default: () => ({}) },
  // Personnel de l'agence (notes internes)
  staff: Boolean,
})
const emit = defineEmits(['close', 'saved'])

const FIELDS = ['name', 'code', 'manager_name', 'phone', 'email', 'location', 'pickup_zone_id', 'pickup_address', 'pickup_landmark', 'cost_center', 'monthly_quota', 'is_main', 'active', 'notes']
const form = ref({})
const ownAddress = ref(false)
const point = ref(null)
const busy = ref(false)
const error = ref('')
const errors = ref({})
const moreOpen = ref(false)
const codeTouched = ref(false)
const step = ref('details')
const created = ref(null)
const managerId = ref(null)
const typedManager = ref(false)
const showAllAccounts = ref(false)
const members = ref({})
const heads = ref({})

// Comptes qui peuvent être responsables ou rattachés : pas les propriétaires (ils voient déjà tout)
const accounts = computed(() => props.users)
const people = computed(() => props.users.filter((u) => !u.owner))
const chosenCount = computed(() => Object.values(members.value).filter(Boolean).length)

watch(() => [props.open, props.department], () => {
  if (!props.open) return
  const d = props.department || {}
  form.value = Object.fromEntries(FIELDS.map((k) => [k, d[k] ?? (k === 'active' ? true : k === 'is_main' ? false : null)]))
  ownAddress.value = !!d.own_address
  point.value = d.pickup_lat != null && d.pickup_lng != null ? { lat: Number(d.pickup_lat), lng: Number(d.pickup_lng) } : null
  moreOpen.value = !!(d.cost_center || d.monthly_quota || d.email || d.notes || d.id)
  codeTouched.value = !!d.code
  managerId.value = props.users.find((u) => d.manager_name && u.name === d.manager_name)?.id ?? null
  typedManager.value = !!d.manager_name && managerId.value === null
  showAllAccounts.value = false
  step.value = 'details'
  created.value = null
  error.value = ''
  errors.value = {}
}, { immediate: true })

const choiceClass = (on) => ['rounded-2xl border-2 p-2.5 text-left', on ? 'border-[var(--app-color,#e11d2a)] bg-rose-50' : 'border-slate-200']
const initials = (name) => (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase()
const otherDepartments = (u) => props.departments.filter((d) => u.department_ids?.includes(d.id)).map((d) => d.code || d.name).join(', ')

// « Ressources humaines » → RH ; « Finance » → FIN ; seulement tant que le code n'a pas été changé à la main
const SMALL_WORDS = ['de', 'des', 'du', 'la', 'le', 'les', 'et', 'l', 'd', 'à', 'au', 'aux', 'en']
function suggestCode() {
  errors.value.name = ''
  if (codeTouched.value) return
  const words = (form.value.name || '').normalize('NFD').replace(/[̀-ͯ]/g, '').split(/[\s'’-]+/)
    .filter((w) => w && !SMALL_WORDS.includes(w.toLowerCase()))
  form.value.code = (words.length > 1 ? words.map((w) => w[0]).join('') : (words[0] || '').slice(0, 3)).toUpperCase().slice(0, 6) || null
}

function chooseManager(u) {
  if (managerId.value === u.id) {
    managerId.value = null
    form.value.manager_name = null
    return
  }
  managerId.value = u.id
  form.value.manager_name = u.name
  if (!form.value.phone && u.phone) form.value.phone = u.phone
}
function typeManager() {
  managerId.value = null
  typedManager.value = true
}

function check() {
  const e = {}
  if (!form.value.name?.trim()) e.name = 'Donnez un nom au département.'
  if (form.value.phone && form.value.phone.replace(/\D/g, '').length < 10) e.phone = 'Numéro incomplet : 10 chiffres, ex. 07 00 00 00 00.'
  if (ownAddress.value && !form.value.pickup_zone_id) e.pickup_zone_id = 'Choisissez la commune du site.'
  if (ownAddress.value && !form.value.pickup_address?.trim()) e.pickup_address = 'Indiquez l\'adresse du site : le livreur en a besoin.'
  errors.value = e
  return Object.keys(e).length === 0
}

async function save() {
  error.value = ''
  if (!check()) return
  busy.value = true
  try {
    const payload = { ...form.value }
    if (ownAddress.value) Object.assign(payload, { pickup_lat: point.value?.lat ?? null, pickup_lng: point.value?.lng ?? null })
    else Object.assign(payload, { pickup_zone_id: null, pickup_address: null, pickup_landmark: null })
    if (!props.staff) delete payload.notes
    const { data } = props.department
      ? await http.put(`/departments/${props.department.id}`, payload)
      : await http.post(`/merchants/${props.merchantId}/departments`, payload)
    // Nouveau département : on passe aux personnes (le responsable choisi est déjà coché)
    if (!props.department && people.value.length) {
      created.value = data.data
      members.value = Object.fromEntries(people.value.map((u) => [u.id, u.id === managerId.value]))
      heads.value = Object.fromEntries(people.value.map((u) => [u.id, u.id === managerId.value && !!props.settings.head_approval]))
      step.value = 'people'
      return
    }
    emit('saved', data.data)
  } catch (e) {
    const fieldErrors = e.response?.status === 422 ? e.response.data?.errors : null
    if (fieldErrors && Object.keys(fieldErrors).some((k) => FIELDS.includes(k))) {
      errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, v[0]]))
      if (errors.value.cost_center || errors.value.monthly_quota || errors.value.email) moreOpen.value = true
    } else {
      error.value = apiErrorMessage(e)
    }
  } finally {
    busy.value = false
  }
}

async function savePeople() {
  busy.value = true
  error.value = ''
  try {
    const id = created.value.id
    for (const u of people.value) {
      if (!members.value[u.id]) continue
      const departmentIds = [...new Set([...(u.department_ids || []), id])]
      const headIds = [...new Set([...(u.head_department_ids || []), ...(heads.value[u.id] ? [id] : [])])]
      await http.put(`/merchants/${props.merchantId}/users/${u.id}/departments`, { department_ids: departmentIds, head_department_ids: headIds })
    }
    finish()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

function finish() {
  emit('saved', created.value)
}
</script>
