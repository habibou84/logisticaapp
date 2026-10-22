<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Messages WhatsApp, SMS et notifications</h1>
      <RouterLink v-if="auth.can('settings.manage')" to="/admin/whatsapp" class="btn-secondary">Paramètres WhatsApp</RouterLink>
    </div>

    <div class="flex flex-wrap gap-2 items-center">
      <button
        v-for="f in FILTERS"
        :key="f.value"
        :class="['rounded-full px-3 py-1 text-sm', status === f.value ? 'bg-slate-900 text-white' : 'bg-white ring-1 ring-slate-200']"
        @click="status = f.value; load()"
      >
        {{ f.label }}
      </button>
      <select v-model="channel" class="input w-36" aria-label="Canal" @change="load()">
        <option value="">Tous canaux</option>
        <option value="whatsapp">WhatsApp</option>
        <option value="sms">SMS</option>
        <option value="push">Notifications</option>
      </select>
      <input v-model="search" type="search" class="input w-56" placeholder="Téléphone ou code colis" aria-label="Rechercher" @input="debouncedLoad">
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-gray-500">
          <tr>
            <th class="p-3">Date</th>
            <th class="p-3">Pour</th>
            <th class="p-3">Course</th>
            <th class="p-3">Message</th>
            <th class="p-3">Statut</th>
            <th class="p-3" />
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="m in messages" :key="m.id" class="align-top">
            <td class="p-3 whitespace-nowrap text-gray-500">{{ dateTime(m.created_at) }}</td>
            <td class="p-3 whitespace-nowrap">
              <div>{{ m.to }}</div>
              <div class="text-gray-500 text-xs">{{ WHO[m.recipient_type] }}<template v-if="m.merchant"> · {{ m.merchant.business_name }}</template></div>
            </td>
            <td class="p-3 whitespace-nowrap">
              <RouterLink v-if="m.order" :to="`/admin/courses/${m.order.id}`" class="text-blue-700 hover:underline">{{ m.order.tracking_code }}</RouterLink>
            </td>
            <td class="p-3 max-w-md">
              <p class="line-clamp-2" :title="m.body">{{ m.body }}</p>
              <p v-if="m.error" class="text-xs text-red-700 mt-1">{{ m.error }}</p>
            </td>
            <td class="p-3"><MessageStatus :message="m" /></td>
            <td class="p-3 text-right">
              <button v-if="m.status === 'failed'" class="btn-secondary text-xs" @click="retry(m)">Renvoyer</button>
            </td>
          </tr>
          <tr v-if="!loading && !messages.length">
            <td colspan="6" class="p-6 text-center text-gray-500">Aucun message.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <button v-if="hasMore" class="btn-secondary w-full" :disabled="loading" @click="load(page + 1)">Voir plus</button>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import MessageStatus from '../../components/MessageStatus.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

const FILTERS = [
  { value: '', label: 'Tous' },
  { value: 'failed', label: 'Échecs' },
  { value: 'queued', label: 'En attente' },
  { value: 'read', label: 'Lus' },
]
const WHO = { merchant: 'Marchand', recipient: 'Destinataire', test: 'Test' }

const auth = useAuthStore()
const toasts = useToastStore()
const messages = ref([])
const status = ref('')
const channel = ref('')
const search = ref('')
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)

async function load(p = 1) {
  loading.value = true
  try {
    const { data } = await http.get('/messages', {
      params: { page: p, status: status.value || undefined, channel: channel.value || undefined, search: search.value || undefined },
    })
    messages.value = p === 1 ? data.data : [...messages.value, ...data.data]
    page.value = p
    hasMore.value = data.meta.current_page < data.meta.last_page
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(() => load(), 300)
}

async function retry(message) {
  try {
    await http.post(`/messages/${message.id}/retry`)
    toasts.success('Message renvoyé.')
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(() => load())
</script>
