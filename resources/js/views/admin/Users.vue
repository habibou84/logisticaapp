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
          <tr><th class="p-2">Nom</th><th class="p-2">Téléphone</th><th class="p-2">E-mail</th><th class="p-2">Rôle</th><th class="p-2">Statut</th><th class="p-2">Dernière connexion</th><th /></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="u in users" :key="u.id">
            <td class="p-2 font-medium">{{ u.name }}</td>
            <td data-label="Téléphone" class="p-2">{{ u.phone }}</td>
            <td data-label="E-mail" class="p-2">{{ u.email || '—' }}</td>
            <td data-label="Rôle" class="p-2">{{ u.role_label }}</td>
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
import { onMounted, reactive, ref } from 'vue'
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
const form = reactive({ open: false, id: null, data: {}, error: '', saving: false })

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
  }
  form.error = ''
  form.open = true
}

async function save() {
  form.saving = true
  form.error = ''
  try {
    const payload = Object.fromEntries(Object.entries(form.data).filter(([k, v]) => v !== '' && !(k === 'status' && !form.id)))
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
  assignable.value = (await http.get('/roles')).data.data
})
</script>
