<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Utilisateurs</h1>
      <div class="flex gap-2">
        <select v-model="role" class="input w-auto" @change="load">
          <option value="">Tous les rôles</option>
          <option v-for="r in assignable" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
        <button v-if="auth.can('users.manage')" class="btn-primary" @click="openForm()">+ Nouveau</button>
      </div>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm stack-table">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Nom</th><th class="p-2">Téléphone</th><th class="p-2">E-mail</th><th class="p-2">Rôle</th><th class="p-2">Activités</th><th class="p-2">Statut</th><th class="p-2">Dernière connexion</th><th /></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="u in users" :key="u.id">
            <td class="p-2 font-medium">{{ u.name }}</td>
            <td data-label="Téléphone" class="p-2">{{ u.phone }}</td>
            <td data-label="E-mail" class="p-2">{{ u.email || '—' }}</td>
            <td data-label="Rôle" class="p-2">
              {{ u.role_label }}
              <div v-if="u.extra_permissions?.length || u.denied_permissions?.length" class="text-xs text-gray-500">
                Permissions ajustées<template v-if="u.extra_permissions?.length"> · +{{ u.extra_permissions.length }}</template><template v-if="u.denied_permissions?.length"> · −{{ u.denied_permissions.length }}</template>
              </div>
            </td>
            <td data-label="Activités" class="p-2">
              <template v-if="isStaffRole(u.role)">
                <span v-if="u.role === 'admin' || !u.activities?.length" class="text-gray-500">Toutes</span>
                <span v-for="a in u.activities" v-else :key="a" class="mr-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ activityLabel(a) }}</span>
              </template>
              <span v-else class="text-gray-400">—</span>
            </td>
            <td data-label="Statut" class="p-2"><span :class="u.status === 'active' ? 'text-emerald-700' : 'text-red-600'">{{ u.status === 'active' ? 'Actif' : 'Suspendu' }}</span></td>
            <td data-label="Dernière connexion" class="p-2 text-xs text-gray-500">{{ dateTime(u.last_login_at) }}</td>
            <td class="p-2 text-right">
              <button v-if="auth.can('users.manage') && u.id !== auth.user.id && !u.role?.startsWith('merchant')" class="text-blue-600" @click="openForm(u)">Modifier</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal :open="form.open" :title="form.id ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur'" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <div v-if="form.error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ form.error }}</div>
        <div><label class="label">Nom *</label><input v-model="form.data.name" class="input" required></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Téléphone *</label><input v-model="form.data.phone" class="input" required inputmode="tel"></div>
          <div><label class="label">E-mail</label><input v-model="form.data.email" type="email" class="input"></div>
        </div>
        <div>
          <label class="label">Rôle *</label>
          <select v-model="form.data.role" class="input" required>
            <option v-for="r in assignable" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
        </div>
        <!-- Équipe : activités visibles et réglage fin des permissions -->
        <template v-if="isStaffRole(form.data.role)">
          <fieldset class="border rounded p-3 space-y-2">
            <legend class="text-sm font-medium px-1">Activités</legend>
            <p v-if="form.data.role === 'admin'" class="text-sm text-gray-600">L'administrateur voit toutes les activités.</p>
            <template v-else>
              <p class="text-xs text-gray-500">Ses courses, clients, factures et alertes. Aucune case : toutes les activités.</p>
              <div class="flex flex-wrap gap-4">
                <label v-for="a in catalog.activities" :key="a.value" class="flex items-center gap-2 text-sm">
                  <input v-model="form.data.activities" type="checkbox" :value="a.value"> {{ a.label }}
                </label>
              </div>
            </template>
          </fieldset>
          <details class="border rounded p-3" :open="form.tuned">
            <summary class="text-sm font-medium cursor-pointer">Permissions <span class="font-normal text-gray-500">(celles du rôle, ajustables une à une)</span></summary>
            <ul class="mt-2 space-y-1">
              <li v-for="p in catalog.permissions" :key="p.value" class="flex items-center justify-between gap-2 text-sm">
                <label class="flex items-center gap-2">
                  <input type="checkbox" :checked="hasPermission(p.value)" :disabled="form.data.role === 'admin'" @change="togglePermission(p.value, $event.target.checked)">
                  {{ p.label }}
                </label>
                <span v-if="form.data.extra_permissions.includes(p.value)" class="text-xs text-emerald-700">ajoutée</span>
                <span v-else-if="form.data.denied_permissions.includes(p.value)" class="text-xs text-red-600">retirée</span>
                <span v-else-if="rolePermissions.includes(p.value)" class="text-xs text-gray-400">du rôle</span>
              </li>
            </ul>
          </details>
        </template>
        <div v-if="form.id">
          <label class="label">Statut</label>
          <select v-model="form.data.status" class="input">
            <option value="active">Actif</option>
            <option value="suspended">Suspendu (déconnecté de tous ses appareils)</option>
          </select>
        </div>
        <div>
          <label class="label">{{ form.id ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe * (8 caractères min.)' }}</label>
          <input v-model="form.data.password" type="text" class="input" :required="!form.id" autocomplete="new-password">
        </div>
        <button class="btn-primary w-full" :disabled="form.saving">Enregistrer</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

const auth = useAuthStore()
const toasts = useToastStore()
const users = ref([])
const assignable = ref([])
const role = ref('')
const form = reactive({ open: false, id: null, data: {}, error: '', saving: false, tuned: false })
// Catalogue : permissions et activités ; rôles avec leurs permissions
const catalog = reactive({ permissions: [], activities: [] })
const STAFF_ROLES = ['admin', 'activity_manager', 'dispatcher', 'cashier', 'hub_agent']
const isStaffRole = (r) => STAFF_ROLES.includes(r)
const activityLabel = (a) => catalog.activities.find((x) => x.value === a)?.label || a
const rolePermissions = computed(() => assignable.value.find((r) => r.value === form.data.role)?.permissions || [])

// Réglage fin : coché = permission effective ; écart avec le rôle = ajoutée ou retirée
function hasPermission(p) {
  if (form.data.role === 'admin') return true
  if (form.data.denied_permissions.includes(p)) return false
  return rolePermissions.value.includes(p) || form.data.extra_permissions.includes(p)
}

function togglePermission(p, checked) {
  const fromRole = rolePermissions.value.includes(p)
  form.data.extra_permissions = form.data.extra_permissions.filter((x) => x !== p)
  form.data.denied_permissions = form.data.denied_permissions.filter((x) => x !== p)
  if (checked && !fromRole) form.data.extra_permissions.push(p)
  if (!checked && fromRole) form.data.denied_permissions.push(p)
}

async function load() {
  users.value = (await http.get('/users', { params: { role: role.value || undefined, per_page: 100 } })).data.data
}

function openForm(user = null) {
  form.id = user?.id ?? null
  form.data = {
    name: user?.name ?? '',
    phone: user?.phone ?? '',
    email: user?.email ?? '',
    role: user?.role ?? 'courier',
    status: user?.status ?? 'active',
    password: '',
    activities: [...(user?.activities || [])],
    extra_permissions: [...(user?.extra_permissions || [])],
    denied_permissions: [...(user?.denied_permissions || [])],
  }
  form.tuned = !!(form.data.extra_permissions.length || form.data.denied_permissions.length)
  form.error = ''
  form.open = true
}

async function save() {
  form.saving = true
  form.error = ''
  try {
    const payload = Object.fromEntries(Object.entries(form.data).filter(([k, v]) => v !== '' && !(k === 'status' && !form.id)))
    if (!isStaffRole(form.data.role) || form.data.role === 'admin') {
      payload.activities = []
      payload.extra_permissions = []
      payload.denied_permissions = []
    }
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
  const { data } = await http.get('/roles')
  assignable.value = data.data
  catalog.permissions = data.permissions
  catalog.activities = data.activities
})
</script>
