<template>
  <div class="space-y-4">
    <div class="flex flex-wrap gap-2" role="tablist" aria-label="Départements">
      <button
        v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
        :class="['rounded-full px-3 py-1.5 text-sm', tab === t.key ? 'bg-slate-900 text-white' : 'bg-white ring-1 ring-slate-300']"
        @click="tab = t.key"
      >
        {{ t.label }}
      </button>
    </div>

    <p v-if="loaded && !enabled" class="card p-4 bg-amber-50 text-sm text-amber-900">Les départements ne sont pas proposés : voir Réglages › Départements.</p>

    <!-- Départements -->
    <section v-if="tab === 'list'" class="space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-gray-600">Chaque course dit quel département l'envoie ; en courrier interne, vers lequel elle va.</p>
        <button v-if="canManage" type="button" class="btn-primary" @click="edit(null)">+ Nouveau département</button>
      </div>
      <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <article v-for="d in departments" :key="d.id" :class="['card p-4 space-y-2', !d.active && 'opacity-60']">
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="font-semibold">
                <span v-if="d.code" class="mr-1 rounded bg-slate-900 px-1.5 py-0.5 font-mono text-xs text-white">{{ d.code }}</span>{{ d.name }}
                <span v-if="d.is_main" class="ml-1 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">principal</span>
                <span v-if="!d.active" class="ml-1 rounded-full bg-slate-200 px-2 py-0.5 text-xs">désactivé</span>
              </p>
              <p v-if="d.manager_name || d.phone" class="text-sm text-gray-600">👤 {{ d.manager_name || '—' }}<template v-if="d.phone"> · {{ localPhone(d.phone) }}</template></p>
            </div>
            <button v-if="canManage" type="button" class="text-sm text-blue-600" @click="edit(d)">Modifier</button>
          </div>
          <p v-if="d.location" class="text-sm">🏢 {{ d.location }}</p>
          <p class="text-sm" :class="d.own_address ? 'text-sky-800' : 'text-gray-500'">📍 {{ d.own_address ? `${d.pickup_zone_name || ''}${d.pickup_address ? ' · ' + d.pickup_address : ''} (autre site)` : 'À l\'adresse du client' }}</p>
          <p v-if="d.cost_center" class="text-sm text-gray-600">Centre de coûts : <b class="font-mono">{{ d.cost_center }}</b></p>
          <div v-if="d.usage">
            <div class="flex justify-between text-xs text-gray-600">
              <span>Ce mois-ci : {{ d.usage.used }} envoi(s)</span>
              <span v-if="d.usage.quota">quota {{ d.usage.quota }}</span>
            </div>
            <div v-if="d.usage.quota" class="mt-1 h-2 overflow-hidden rounded bg-slate-100">
              <div :class="['h-full', d.usage.percent >= 100 ? 'bg-red-500' : d.usage.percent >= 80 ? 'bg-amber-500' : 'bg-emerald-500']" :style="{ width: `${Math.min(100, d.usage.percent)}%` }" />
            </div>
          </div>
          <p class="text-xs text-gray-500">{{ usersOf(d).length }} compte(s) rattaché(s)<template v-if="d.orders_count"> · {{ d.orders_count }} course(s)</template></p>
          <RouterLink v-if="settings.slips && d.active" :to="`/bordereau/${d.id}`" class="mr-3 text-xs text-blue-600">🧾 Bordereau du jour</RouterLink>
          <button v-if="canManage && d.active && !d.is_main" type="button" class="text-xs text-red-600" @click="remove(d)">{{ d.orders_count ? 'Désactiver' : 'Supprimer' }}</button>
        </article>
        <p v-if="loaded && !departments.length" class="card p-6 text-center text-gray-500 md:col-span-2 xl:col-span-3">
          Aucun département. Ajoutez-en si ce client (une banque, une administration…) envoie ou reçoit pour plusieurs services.
        </p>
      </div>
    </section>

    <!-- Comptes du client et leurs départements -->
    <section v-if="tab === 'users'" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Compte</th><th v-for="d in activeDepartments" :key="d.id" class="p-2 text-center">{{ d.code || d.name }}</th></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="u in users" :key="u.id">
            <td class="p-2"><b>{{ u.name }}</b><span class="block text-xs text-gray-500">{{ u.owner ? 'Gérant : voit tout' : u.department_ids.length ? (settings.staff_own_only ? 'Ne voit que ses départements' : 'Voit tout le client') : 'Sans département : voit tout' }}</span></td>
            <td v-for="d in activeDepartments" :key="d.id" class="p-2 text-center">
              <input type="checkbox" :checked="u.department_ids.includes(d.id)" :disabled="u.owner || !canManage" :aria-label="`${u.name} · ${d.name}`" @change="toggleUser(u, d.id, $event.target.checked)">
            </td>
          </tr>
          <tr v-if="!users.length"><td :colspan="activeDepartments.length + 1" class="p-6 text-center text-gray-500">Aucun compte pour ce client.</td></tr>
        </tbody>
      </table>
    </section>

    <!-- Réglages propres au client -->
    <section v-if="tab === 'settings' && overrides" class="space-y-3">
      <p class="text-sm text-gray-600">Vide : comme l'entreprise (Réglages › Départements).</p>
      <div class="grid gap-4 lg:grid-cols-2">
        <div v-for="g in groups" :key="g" class="card p-4 space-y-3">
          <h3 class="font-semibold">{{ g }}</h3>
          <div v-for="o in DEPARTMENT_OPTIONS.filter((x) => x.group === g)" :key="o.key">
            <label class="block text-sm" :for="`do-${o.key}`">{{ o.label }}</label>
            <select :id="`do-${o.key}`" :value="overrideValue(o.key)" class="input mt-1" @change="setOverride(o, $event.target.value)">
              <option value="">Comme l'entreprise ({{ optionText(o, defaults[o.key]).toLowerCase() }})</option>
              <option v-for="(label, value) in optionChoices(o)" :key="value" :value="value">{{ label }}</option>
            </select>
            <p v-if="o.hint" class="mt-0.5 text-xs text-gray-500">{{ o.hint }}</p>
          </div>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <button type="button" class="btn-primary" :disabled="saving" @click="saveSettings">Enregistrer les réglages</button>
        <span v-if="saved" class="text-sm text-emerald-700">Enregistré ✓</span>
        <span v-if="error" class="field-error">{{ error }}</span>
      </div>
    </section>

    <DepartmentStats v-if="tab === 'stats'" :merchant-id="merchantId" />

    <slot name="extra" :tab="tab" :departments="departments" />

    <DepartmentFormModal :open="formOpen" :merchant-id="merchantId" :department="editing" :zones="zones" :staff="staff" @close="formOpen = false" @saved="formOpen = false; load()" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import DepartmentFormModal from './DepartmentFormModal.vue'
import DepartmentStats from './DepartmentStats.vue'
import { DEPARTMENT_OPTIONS, optionChoices, optionText } from '../../utils/departments'
import { useToastStore } from '../../stores/toasts'
import { localPhone } from '../../utils/format'

const props = defineProps({
  merchantId: { type: Number, required: true },
  // Personnel de l'agence : réglages du client, notes internes
  staff: Boolean,
  extraTabs: { type: Array, default: () => [] },
})
const emit = defineEmits(['loaded'])
const toasts = useToastStore()

const tab = ref('list')
const departments = ref([])
const users = ref([])
const settings = ref({})
const defaults = ref({})
const overrides = ref(null)
const canManage = ref(false)
const enabled = ref(true)
const loaded = ref(false)
const zones = ref([])
const formOpen = ref(false)
const editing = ref(null)
const saving = ref(false)
const saved = ref(false)
const error = ref('')

const tabs = computed(() => [
  { key: 'list', label: `Départements (${departments.value.filter((d) => d.active).length})` },
  ...(canManage.value ? [{ key: 'users', label: 'Comptes' }] : []),
  ...(departments.value.length ? [{ key: 'stats', label: 'Statistiques' }] : []),
  ...(props.staff ? [{ key: 'settings', label: 'Réglages du client' }] : []),
  ...props.extraTabs,
])
const activeDepartments = computed(() => departments.value.filter((d) => d.active))
const groups = [...new Set(DEPARTMENT_OPTIONS.map((o) => o.group))]
const usersOf = (d) => users.value.filter((u) => u.department_ids.includes(d.id))

async function load() {
  const { data } = await http.get(`/merchants/${props.merchantId}/departments`)
  departments.value = data.data
  users.value = data.users
  settings.value = data.settings
  defaults.value = data.defaults || {}
  overrides.value = data.overrides ? { ...data.overrides } : (props.staff ? {} : null)
  canManage.value = data.can_manage
  enabled.value = data.enabled
  loaded.value = true
  emit('loaded', data)
}

function edit(d) {
  editing.value = d
  formOpen.value = true
}

async function remove(d) {
  if (!confirm(d.orders_count ? `Désactiver ${d.name} ? Ses courses et factures le gardent.` : `Supprimer ${d.name} ?`)) return
  try {
    await http.delete(`/departments/${d.id}`)
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function toggleUser(u, id, on) {
  const ids = on ? [...u.department_ids, id] : u.department_ids.filter((x) => x !== id)
  try {
    const { data } = await http.put(`/merchants/${props.merchantId}/users/${u.id}/departments`, { department_ids: ids })
    u.department_ids = data.data.department_ids
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

const overrideValue = (key) => (overrides.value?.[key] === undefined || overrides.value?.[key] === null ? '' : String(overrides.value[key]))
function setOverride(o, value) {
  const next = { ...overrides.value }
  if (value === '') delete next[o.key]
  else next[o.key] = o.type === 'bool' ? value === 'true' : value
  overrides.value = next
  saved.value = false
}

async function saveSettings() {
  saving.value = true
  error.value = ''
  try {
    await http.put(`/merchants/${props.merchantId}/department-settings`, { department_settings: overrides.value })
    saved.value = true
    load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  load()
  zones.value = (await http.get('/zones')).data.data
})

defineExpose({ load })
</script>
