<template>
  <div v-if="event" class="space-y-4">
    <div class="m-card p-4 space-y-3">
      <div class="flex items-start gap-3">
        <span class="h-12 w-12 shrink-0 rounded-2xl grid place-items-center text-2xl bg-slate-100">{{ event.icon }}</span>
        <div class="min-w-0 flex-1">
          <p class="font-semibold text-lg">{{ event.type_label }}</p>
          <p class="text-sm text-slate-500">{{ dateTime(event.created_at) }} · {{ capacityText }}</p>
        </div>
        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', SEVERITY_STYLES[event.severity]]">{{ event.severity_label }}</span>
      </div>

      <!-- Suivi : envoyé, pris en charge, réglé -->
      <ol class="space-y-2">
        <li v-for="s in steps" :key="s.label" class="flex items-center gap-3 text-sm">
          <span :class="['h-6 w-6 shrink-0 rounded-full grid place-items-center text-xs text-white', s.done ? 'bg-emerald-600' : 'bg-slate-300']">{{ s.done ? '✓' : '' }}</span>
          <span :class="s.done ? 'text-slate-900' : 'text-slate-400'">{{ s.label }}</span>
        </li>
      </ol>

      <p v-if="event.note" class="rounded-xl bg-slate-50 px-3 py-2 text-sm">« {{ event.note }} »</p>
      <p v-if="event.resolution" class="text-sm text-emerald-700">✓ {{ event.resolution }}</p>
      <div v-if="media.length" class="flex flex-wrap items-center gap-2">
        <template v-for="m in media" :key="m.id">
          <img v-if="m.kind === 'photo'" :src="m.src" alt="Photo jointe" class="h-20 w-20 rounded-xl object-cover ring-1 ring-slate-200">
          <audio v-else :src="m.src" controls class="h-9 w-52" />
        </template>
      </div>
      <p v-if="event.orders?.length" class="text-sm text-slate-600">📦 {{ event.orders.length }} course{{ event.orders.length > 1 ? 's' : '' }} touchée{{ event.orders.length > 1 ? 's' : '' }} : {{ event.orders.map((o) => o.tracking_code).join(', ') }}</p>
    </div>

    <!-- Échange avec l'agence -->
    <div class="m-card p-4 space-y-3">
      <p class="font-semibold">Échange avec l'agence</p>
      <p v-if="!event.messages.length" class="text-sm text-slate-500">L'agence peut vous écrire ici. Vous pouvez aussi lui donner des nouvelles.</p>
      <div v-for="m in event.messages" :key="m.id" :class="['max-w-[85%] rounded-2xl px-3 py-2 text-sm', m.from_courier ? 'ml-auto bg-[var(--app-color)] text-white' : 'bg-slate-100']">
        <p v-if="!m.from_courier" class="text-xs font-semibold text-slate-500">{{ m.sender }}</p>
        <p class="whitespace-pre-line">{{ m.body }}</p>
        <p :class="['text-[11px] text-right', m.from_courier ? 'text-white/70' : 'text-slate-400']">{{ time(m.created_at) }}</p>
      </div>
      <div v-if="event.status !== 'resolved'" class="flex gap-2">
        <input v-model="body" class="m-input flex-1" placeholder="Votre message" aria-label="Votre message" @keydown.enter="sendMessage">
        <button type="button" class="m-btn-primary w-auto px-4" :disabled="busy || !body.trim()" @click="sendMessage">Envoyer</button>
      </div>
    </div>

    <button v-if="event.status !== 'resolved'" type="button" class="m-btn-success text-lg py-4" :disabled="busy" @click="resume">✅ C'est réglé, je reprends</button>
    <a v-if="companyPhone" :href="`tel:${companyPhone}`" class="m-btn-secondary">📞 Appeler l'agence</a>
  </div>
  <p v-else-if="error" class="text-center text-slate-600 py-10">{{ error }}</p>
  <p v-else class="text-center text-slate-400 py-10">Chargement…</p>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { authImage } from '../../composables/useAuthImage'
import { SEVERITY_STYLES } from '../../composables/useFieldEvents'
import { useAuthStore } from '../../stores/auth'
import { useNotificationStore } from '../../stores/notifications'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const notifications = useNotificationStore()
const event = ref(null)
const media = ref([])
const error = ref('')
const body = ref('')
const busy = ref(false)
const companyPhone = computed(() => auth.user?.company?.phone)

const time = (v) => new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const capacityText = computed(() => {
  const e = event.value
  if (e.capacity === 'blocked' && e.blocked_until) return `bloqué jusqu'à ${time(e.blocked_until)} environ`
  return e.capacity_label
})
const steps = computed(() => [
  { label: 'Envoyé à l\'agence', done: true },
  { label: event.value.taken_by ? `Pris en charge par ${event.value.taken_by}` : 'Pris en charge', done: !!event.value.taken_at || event.value.status === 'resolved' },
  { label: 'Réglé', done: event.value.status === 'resolved' },
])

async function load() {
  try {
    const { data } = await http.get(`/courier/field-events/${route.params.id}`)
    const first = !event.value
    event.value = data.data
    if (first) {
      media.value = (await Promise.all(event.value.files.map(async (f) => ({ ...f, src: await authImage(f.url).catch(() => null) })))).filter((m) => m.src)
    }
  } catch (e) {
    error.value = apiErrorMessage(e, 'Signalement introuvable.')
  }
}

async function sendMessage() {
  if (!body.value.trim()) return
  busy.value = true
  try {
    event.value = (await http.post(`/courier/field-events/${event.value.id}/messages`, { body: body.value })).data.data
    body.value = ''
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function resume() {
  busy.value = true
  try {
    await http.post(`/courier/field-events/${event.value.id}/resume`)
    if (auth.user?.courier) auth.user.courier.is_available = true
    toasts.success('Bonne reprise ! Vous êtes de nouveau en service.')
    router.push('/livreur')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

// Réponse ou prise en charge de l'agence reçue en direct
watch(() => notifications.unread, load)
onMounted(load)
onBeforeUnmount(() => media.value.forEach((m) => URL.revokeObjectURL(m.src)))
</script>
