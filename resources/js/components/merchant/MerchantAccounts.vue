<template>
  <div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-sm text-gray-600">Qui se connecte à l'espace du client, et ce que chacun peut faire.</p>
      <button v-if="assignable.length" type="button" class="btn-primary" @click="open()">+ Ajouter un compte</button>
    </div>

    <p v-if="error" class="field-error">{{ error }}</p>
    <ul class="divide-y rounded-lg border bg-white">
      <li v-for="u in accounts" :key="u.id" class="flex flex-wrap items-center justify-between gap-2 p-3">
        <div class="min-w-0">
          <p class="font-medium">{{ u.name }} <span v-if="u.status !== 'active'" class="text-xs text-red-600">· suspendu</span></p>
          <p class="text-xs text-gray-500">{{ u.phone }} · {{ u.role_label }}</p>
        </div>
        <button v-if="assignable.includes(u.role) && u.id !== auth.user?.id" type="button" class="text-sm text-blue-600" @click="open(u)">Modifier</button>
      </li>
      <li v-if="loaded && !accounts.length" class="p-4 text-sm text-gray-500">Aucun compte.</li>
    </ul>

    <!-- Rôles du client : ce que chacun peut faire -->
    <details class="text-sm">
      <summary class="cursor-pointer text-gray-600">Les rôles</summary>
      <ul class="mt-2 space-y-1">
        <li v-for="r in roles" :key="r.value"><b>{{ r.label }}</b> : {{ r.description }}</li>
      </ul>
    </details>

    <Modal :open="form.open" :title="form.id ? `Modifier ${form.name}` : 'Nouveau compte'" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <p v-if="form.error" class="field-error">{{ form.error }}</p>
        <template v-if="!form.id">
          <div><label class="label" for="ma-name">Nom *</label><input id="ma-name" v-model="form.name" class="input" required maxlength="255"></div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="label" for="ma-phone">Téléphone *</label><input id="ma-phone" v-model="form.phone" class="input" required inputmode="tel"></div>
            <div><label class="label" for="ma-email">E-mail</label><input id="ma-email" v-model="form.email" type="email" class="input"></div>
          </div>
        </template>
        <div>
          <label class="label" for="ma-role">Rôle *</label>
          <select id="ma-role" v-model="form.role" class="input" required>
            <option v-for="r in roles.filter((x) => assignable.includes(x.value))" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
          <p class="text-xs text-gray-500 mt-1">{{ roles.find((r) => r.value === form.role)?.description }}</p>
        </div>
        <div v-if="form.id">
          <label class="label" for="ma-status">Statut</label>
          <select id="ma-status" v-model="form.status" class="input">
            <option value="active">Actif</option>
            <option value="suspended">Suspendu (déconnecté de tous ses appareils)</option>
          </select>
        </div>
        <div>
          <label class="label" for="ma-password">{{ form.id ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe * (8 caractères min.)' }}</label>
          <input id="ma-password" v-model="form.password" type="text" class="input" :required="!form.id" autocomplete="new-password">
        </div>
        <button class="btn-primary w-full" :disabled="form.saving">{{ form.saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../Modal.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

/**
 * Comptes d'un client (propriétaire, gestionnaire, agent, lecteur) : par l'équipe ou par le client lui-même.
 */
const props = defineProps({ merchantId: { type: Number, required: true } })
const auth = useAuthStore()
const toasts = useToastStore()
const accounts = ref([])
const roles = ref([])
const assignable = ref([])
const loaded = ref(false)
const error = ref('')
const form = reactive({ open: false, id: null, name: '', phone: '', email: '', role: 'merchant_staff', status: 'active', password: '', error: '', saving: false })

async function load() {
  try {
    const { data } = await http.get(`/merchants/${props.merchantId}/users`)
    accounts.value = data.data
    roles.value = data.roles
    assignable.value = data.assignable
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loaded.value = true
  }
}

function open(u = null) {
  Object.assign(form, {
    open: true, id: u?.id ?? null, name: u?.name ?? '', phone: '', email: '', role: u?.role ?? 'merchant_staff',
    status: u?.status ?? 'active', password: '', error: '',
  })
}

async function save() {
  form.saving = true
  form.error = ''
  try {
    if (form.id) {
      await http.patch(`/merchants/${props.merchantId}/users/${form.id}`, { role: form.role, status: form.status, password: form.password || undefined })
    } else {
      await http.post(`/merchants/${props.merchantId}/users`, { name: form.name, phone: form.phone, email: form.email || null, role: form.role, password: form.password })
    }
    form.open = false
    toasts.success('Compte enregistré.')
    load()
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

onMounted(load)
</script>
