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
    <section v-if="tab === 'list'" :class="['space-y-3', !staff && 'pb-24']">
      <button
        v-if="canManage" type="button"
        class="flex w-full items-center gap-3 rounded-2xl border-2 border-dashed border-rose-300 bg-white px-4 py-3 text-left font-semibold text-rose-700 hover:bg-rose-50"
        @click="edit(null)"
      >
        <span class="grid h-8 w-8 place-items-center rounded-xl bg-rose-100 text-lg" aria-hidden="true">＋</span>
        Ajouter un département
      </button>
      <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <article v-for="d in departments" :key="d.id" :class="['min-w-0 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200', !d.active && 'opacity-60']">
          <div class="flex items-center gap-3">
            <span class="grid h-11 min-w-[2.75rem] place-items-center rounded-xl bg-slate-900 px-1.5 font-mono text-xs font-bold text-white">{{ d.code || initials(d.name) }}</span>
            <div class="min-w-0 flex-1">
              <p class="font-bold">
                {{ d.name }}
                <span v-if="d.is_main" class="ml-1 whitespace-nowrap rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800">principal</span>
                <span v-if="d.own_address" class="ml-1 whitespace-nowrap rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800">autre site</span>
                <span v-if="!d.active" class="ml-1 rounded-full bg-slate-200 px-2 py-0.5 text-xs">désactivé</span>
              </p>
              <p v-if="d.manager_name || d.phone" class="truncate text-sm text-gray-500">{{ [d.manager_name, d.phone && localPhone(d.phone)].filter(Boolean).join(' · ') }}</p>
            </div>
            <div v-if="canManage || (settings.slips && d.active)" class="relative">
              <button type="button" class="grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-gray-500 hover:bg-slate-50" :aria-label="`Actions pour ${d.name}`" :aria-expanded="menuFor === d.id" @click="menuFor = menuFor === d.id ? null : d.id">⋯</button>
              <div v-if="menuFor === d.id" class="absolute right-0 top-10 z-20 w-56 overflow-hidden rounded-xl bg-white text-sm shadow-lg ring-1 ring-slate-200">
                <button v-if="canManage" type="button" class="block w-full px-4 py-2.5 text-left hover:bg-slate-50" @click="menuFor = null; edit(d)">✏️ Modifier</button>
                <RouterLink v-if="settings.slips && d.active" :to="`/bordereau/${d.id}`" class="block px-4 py-2.5 hover:bg-slate-50">🧾 Bordereau du jour</RouterLink>
                <button v-if="canManage && d.active && !d.is_main" type="button" class="block w-full border-t px-4 py-2.5 text-left text-red-600 hover:bg-red-50" @click="menuFor = null; removing = d">{{ d.orders_count ? '⏸️ Désactiver' : '🗑️ Supprimer' }}</button>
              </div>
            </div>
          </div>
          <div class="mt-3 flex min-w-0 flex-wrap gap-1.5 text-xs [&>span]:max-w-full [&>span]:break-words">
            <span v-if="d.own_address" class="rounded-full bg-sky-50 px-2.5 py-1 text-sky-900">📍 {{ [d.pickup_zone_name, d.pickup_address].filter(Boolean).join(' · ') }}</span>
            <span v-if="d.location" class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">🏢 {{ d.location }}</span>
            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">👥 {{ plural(usersOf(d).length, 'compte', 'aucun compte') }}</span>
            <span v-if="d.cost_center" class="rounded-full bg-slate-100 px-2.5 py-1 font-mono text-slate-700">💳 {{ d.cost_center }}</span>
          </div>
          <template v-if="d.usage">
            <div v-if="d.usage.quota" class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
              <div :class="['h-full', d.usage.percent >= 100 ? 'bg-red-500' : d.usage.percent >= 80 ? 'bg-amber-500' : 'bg-emerald-500']" :style="{ width: `${Math.min(100, d.usage.percent)}%` }" />
            </div>
            <p class="mt-1 flex justify-between text-xs text-gray-500">
              <span>{{ plural(d.usage.used, 'envoi', 'aucun envoi') }} ce mois-ci</span>
              <span v-if="d.usage.quota" :class="d.usage.percent >= 80 ? 'font-semibold text-amber-700' : ''">sur {{ d.usage.quota }}{{ d.usage.percent >= 100 ? ' · atteint' : d.usage.percent >= 80 ? ' · bientôt atteint' : '' }}</span>
            </p>
          </template>
        </article>
        <div v-if="loaded && !departments.length" class="rounded-2xl bg-white p-6 text-center text-gray-500 ring-1 ring-slate-200 md:col-span-2 xl:col-span-3">
          {{ staff
            ? 'Aucun département. Ajoutez-en si ce client (une banque, une administration…) envoie ou reçoit pour plusieurs services.'
            : 'Aucun département. Ajoutez vos services (Finance, Ressources humaines…) si vous envoyez ou recevez pour plusieurs services.' }}
        </div>
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
            <td class="p-2">
              <b>{{ u.name }}</b><span v-if="u.role_label" class="text-xs text-gray-500"> · {{ u.role_label }}</span>
              <span class="block text-xs text-gray-500">{{ u.owner ? 'Voit tout le client et valide ses demandes' : u.department_ids.length ? (settings.staff_own_only ? 'Ne voit que ses départements' : 'Voit tout le client') : 'Sans département : voit tout' }}</span>
            </td>
            <td v-for="d in activeDepartments" :key="d.id" class="p-2 text-center">
              <input type="checkbox" :checked="u.department_ids.includes(d.id)" :disabled="u.owner || !canManage" :aria-label="`${u.name} · ${d.name}`" @change="toggleUser(u, d.id, $event.target.checked)">
              <!-- Chef de service : valide les demandes des agents du département (réglage « validation par le chef ») -->
              <label v-if="settings.head_approval && u.department_ids.includes(d.id) && !u.owner" class="mt-1 flex items-center justify-center gap-1 text-[11px] text-gray-600">
                <input type="checkbox" :checked="(u.head_department_ids || []).includes(d.id)" :disabled="!canManage" @change="toggleHead(u, d.id, $event.target.checked)"> chef
              </label>
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

    <DepartmentFormModal
      :open="formOpen" :merchant-id="merchantId" :department="editing" :zones="zones" :staff="staff"
      :users="users" :departments="activeDepartments" :settings="settings"
      @close="formOpen = false; load()" @saved="formOpen = false; load()"
    />

    <!-- Supprimer ou désactiver : on dit ce qui se passe -->
    <BottomSheet :open="!!removing" :title="removing ? `${removing.orders_count ? 'Désactiver' : 'Supprimer'} ${removing.name} ?` : ''" @close="removing = null">
      <p class="text-sm text-gray-600">
        {{ removing?.orders_count
          ? `Il a déjà ${plural(removing.orders_count, 'course')} : il reste sur ces courses et sur les factures, mais on ne peut plus le choisir pour de nouveaux envois. Vous pourrez le réactiver.`
          : 'Il n\'a aucune course : il sera supprimé.' }}
      </p>
      <button type="button" class="mt-4 w-full rounded-xl bg-red-600 py-3 font-semibold text-white disabled:opacity-60" :disabled="removeBusy" @click="remove">{{ removing?.orders_count ? 'Désactiver' : 'Supprimer' }}</button>
      <button type="button" class="mt-2 w-full py-2 text-sm text-gray-600" @click="removing = null">Annuler</button>
    </BottomSheet>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BottomSheet from '../mobile/BottomSheet.vue'
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

const removing = ref(null)
const removeBusy = ref(false)
async function remove() {
  removeBusy.value = true
  try {
    const { data } = await http.delete(`/departments/${removing.value.id}`)
    toasts.success(data.data.archived ? `${removing.value.name} est désactivé.` : `${removing.value.name} est supprimé.`)
    removing.value = null
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    removeBusy.value = false
  }
}

const menuFor = ref(null)
const initials = (name) => (name || '?').split(/\s+/).filter(Boolean).slice(0, 3).map((w) => w[0]).join('').toUpperCase()
// « 1 compte », « 3 comptes », « aucun compte »
const plural = (n, word, none = null) => (!n && none ? none : `${n} ${word}${n > 1 ? 's' : ''}`)

async function saveMembership(u, departmentIds, headIds) {
  try {
    const { data } = await http.put(`/merchants/${props.merchantId}/users/${u.id}/departments`, { department_ids: departmentIds, head_department_ids: headIds })
    u.department_ids = data.data.department_ids
    u.head_department_ids = data.data.head_department_ids
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function toggleUser(u, id, on) {
  const ids = on ? [...u.department_ids, id] : u.department_ids.filter((x) => x !== id)
  return saveMembership(u, ids, (u.head_department_ids || []).filter((x) => ids.includes(x)))
}

function toggleHead(u, id, on) {
  const heads = u.head_department_ids || []
  return saveMembership(u, u.department_ids, on ? [...heads, id] : heads.filter((x) => x !== id))
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
