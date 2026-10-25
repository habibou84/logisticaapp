<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Utilisateurs</h1>
      <div class="flex flex-wrap gap-2">
        <select v-model="role" class="input w-auto" aria-label="Rôle" @change="load">
          <option value="">Tous les rôles</option>
          <option v-for="r in catalog.roles" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
        <RouterLink to="/admin/roles" class="btn-secondary">🔐 Rôles</RouterLink>
        <button v-if="auth.can('users.manage')" class="btn-primary" @click="openForm()">+ Nouveau</button>
      </div>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm stack-table">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Nom</th><th class="p-2">Téléphone</th><th class="p-2">E-mail</th><th class="p-2">Rôle</th><th class="p-2">Périmètre</th><th class="p-2">Statut</th><th class="p-2">Dernière connexion</th><th /></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="u in users" :key="u.id">
            <td class="p-2 font-medium">{{ u.name }}</td>
            <td data-label="Téléphone" class="p-2">{{ u.phone }}</td>
            <td data-label="E-mail" class="p-2">{{ u.email || '—' }}</td>
            <td data-label="Rôle" class="p-2">
              {{ u.role_label }}
              <span v-if="u.role_custom" class="ml-1 rounded-full bg-indigo-50 px-1.5 text-[10px] text-indigo-700">sur mesure</span>
              <div v-if="u.extra_permissions?.length || u.denied_permissions?.length" class="text-xs text-gray-500">
                Droits ajustés<template v-if="u.extra_permissions?.length"> · +{{ u.extra_permissions.length }}</template><template v-if="u.denied_permissions?.length"> · −{{ u.denied_permissions.length }}</template>
              </div>
            </td>
            <td data-label="Périmètre" class="p-2">
              <template v-if="isStaffRole(u.role)">
                <span v-if="u.role === 'admin' || !scopeParts(u).length" class="text-gray-500">Tout</span>
                <span v-for="p in scopeParts(u)" v-else :key="p" class="mr-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ p }}</span>
              </template>
              <span v-else class="text-gray-400">—</span>
            </td>
            <td data-label="Statut" class="p-2"><span :class="u.status === 'active' ? 'text-emerald-700' : 'text-red-600'">{{ u.status === 'active' ? 'Actif' : 'Suspendu' }}</span></td>
            <td data-label="Dernière connexion" class="p-2 text-xs text-gray-500">{{ dateTime(u.last_login_at) }}</td>
            <td class="p-2 text-right whitespace-nowrap space-x-3">
              <button v-if="isStaffRole(u.role)" type="button" class="text-gray-600" title="Voir comme ce compte" @click="preview = u.id">👁 Voir comme</button>
              <button v-if="canEdit(u)" class="text-blue-600" @click="openForm(u)">Modifier</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal :open="form.open" wide :title="form.id ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur'" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <div v-if="form.error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ form.error }}</div>
        <div><label class="label" for="u-name">Nom *</label><input id="u-name" v-model="form.data.name" class="input" required></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label" for="u-phone">Téléphone *</label><input id="u-phone" v-model="form.data.phone" class="input" required inputmode="tel"></div>
          <div><label class="label" for="u-email">E-mail</label><input id="u-email" v-model="form.data.email" type="email" class="input"></div>
        </div>
        <div>
          <label class="label" for="u-role">Rôle *</label>
          <select id="u-role" v-model="form.data.role" class="input" required @change="resetTuning">
            <optgroup label="Rôles intégrés">
              <option v-for="r in assignable.filter((r) => !r.custom)" :key="r.value" :value="r.value">{{ r.label }}</option>
            </optgroup>
            <optgroup v-if="assignable.some((r) => r.custom)" label="Rôles sur mesure">
              <option v-for="r in assignable.filter((r) => r.custom)" :key="r.value" :value="r.value">{{ r.label }}</option>
            </optgroup>
          </select>
          <p v-if="currentRole?.description" class="text-xs text-gray-500 mt-1">{{ currentRole.description }}</p>
        </div>
        <!-- Équipe : périmètre propre au compte et réglage fin des droits -->
        <template v-if="isStaffRole(form.data.role) && form.data.role !== 'admin'">
          <details class="border rounded p-3" :open="hasOwnScope">
            <summary class="text-sm font-medium cursor-pointer">
              Périmètre <span class="font-normal text-gray-500">({{ hasOwnScope ? 'propre à ce compte' : roleScopeText || 'tout' }})</span>
            </summary>
            <ScopeFields v-model="form.data.scope" class="mt-2" hint="Rien de coché : le périmètre du rôle s'applique." />
          </details>
          <details class="border rounded p-3" :open="form.tuned">
            <summary class="text-sm font-medium cursor-pointer">Droits <span class="font-normal text-gray-500">(ceux du rôle, ajustables un à un)</span></summary>
            <PermissionPicker v-model="form.data.permissions" :base="rolePermissions" class="mt-2" />
          </details>
        </template>
        <p v-else-if="form.data.role === 'admin'" class="text-sm text-gray-600">L'administrateur a tous les droits et voit tout.</p>
        <div v-if="form.id">
          <label class="label" for="u-status">Statut</label>
          <select id="u-status" v-model="form.data.status" class="input">
            <option value="active">Actif</option>
            <option value="suspended">Suspendu (déconnecté de tous ses appareils)</option>
          </select>
        </div>
        <div>
          <label class="label" for="u-password">{{ form.id ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe * (8 caractères min.)' }}</label>
          <input id="u-password" v-model="form.data.password" type="text" class="input" :required="!form.id" autocomplete="new-password">
        </div>
        <button class="btn-primary w-full" :disabled="form.saving">Enregistrer</button>
      </form>
    </Modal>

    <AccessPreview :user-id="preview" @close="preview = null" />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import PermissionPicker from '../../components/access/PermissionPicker.vue'
import ScopeFields from '../../components/access/ScopeFields.vue'
import AccessPreview from '../../components/access/AccessPreview.vue'
import { useAccessCatalog } from '../../composables/useAccessCatalog'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const { catalog, load: loadCatalog, role: findRole, isStaffRole } = useAccessCatalog()
const users = ref([])
const role = ref(route.query.role || '')
const preview = ref(null)
const form = reactive({ open: false, id: null, data: {}, error: '', saving: false, tuned: false })

// Rôles que le compte connecté peut attribuer
const assignable = computed(() => catalog.roles.filter((r) => r.assignable))
const currentRole = computed(() => findRole(form.data.role))
const rolePermissions = computed(() => currentRole.value?.permissions || [])
const hasOwnScope = computed(() => Object.values(form.data.scope || {}).some((v) => v.length))
const activityLabel = (a) => catalog.activities.find((x) => x.value === a)?.label || a
const zoneName = (id) => catalog.zones.find((z) => z.id === id)?.name
const hubName = (id) => catalog.hubs.find((h) => h.id === id)?.name

function scopeText(scope) {
  const parts = [...(scope.activities || []).map(activityLabel), ...(scope.zone_ids || []).map(zoneName), ...(scope.hub_ids || []).map(hubName)]
  return parts.filter(Boolean)
}
const roleScopeText = computed(() => {
  const parts = currentRole.value ? scopeText(currentRole.value) : []
  return parts.length ? `celui du rôle : ${parts.join(', ')}` : ''
})
// Liste : périmètre effectif (compte, sinon rôle)
const scopeParts = (u) => scopeText(u.scope || {})

// Un compte modifiable : jamais le sien, jamais un client, jamais plus puissant que soi
const canEdit = (u) => auth.can('users.manage') && u.id !== auth.user.id && !u.merchant_id && (!u.role || assignable.value.some((r) => r.value === u.role))

function resetTuning() {
  form.data.permissions = [...rolePermissions.value]
}

async function load() {
  users.value = (await http.get('/users', { params: { role: role.value || undefined, per_page: 100 } })).data.data
}

function openForm(user = null) {
  const r = findRole(user?.role ?? 'courier')
  const base = r?.permissions || []
  const extra = user?.extra_permissions || []
  const denied = user?.denied_permissions || []
  form.id = user?.id ?? null
  form.data = {
    name: user?.name ?? '',
    phone: user?.phone ?? '',
    email: user?.email ?? '',
    role: user?.role ?? 'courier',
    status: user?.status ?? 'active',
    password: '',
    scope: { activities: [...(user?.activities || [])], zone_ids: [...(user?.zone_ids || [])], hub_ids: [...(user?.hub_ids || [])] },
    // Droits effectifs : ceux du rôle, plus les ajoutés, moins les retirés
    permissions: [...new Set([...base, ...extra])].filter((p) => !denied.includes(p)),
  }
  form.tuned = !!(extra.length || denied.length)
  form.error = ''
  form.open = true
}

async function save() {
  form.saving = true
  form.error = ''
  try {
    const { scope, permissions, ...fields } = form.data
    const payload = Object.fromEntries(Object.entries(fields).filter(([k, v]) => v !== '' && !(k === 'status' && !form.id)))
    const staff = isStaffRole(form.data.role) && form.data.role !== 'admin'
    // Réglage fin : l'écart avec le rôle
    payload.extra_permissions = staff ? permissions.filter((p) => !rolePermissions.value.includes(p)) : []
    payload.denied_permissions = staff ? rolePermissions.value.filter((p) => !permissions.includes(p)) : []
    payload.activities = staff ? scope.activities : []
    payload.zone_ids = staff ? scope.zone_ids : []
    payload.hub_ids = staff ? scope.hub_ids : []
    if (!form.data.email && form.id) payload.email = null
    if (form.id) await http.patch(`/users/${form.id}`, payload)
    else await http.post('/users', payload)
    form.open = false
    toasts.success('Utilisateur enregistré.')
    load()
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

onMounted(async () => {
  load()
  await loadCatalog()
})
</script>
