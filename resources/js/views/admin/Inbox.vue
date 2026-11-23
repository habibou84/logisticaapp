<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">Messages</h1>
        <p class="text-sm text-slate-600">Vos échanges avec les destinataires, les clients et les livreurs : ce qu'ils écrivent au WhatsApp de l'entreprise et tout ce qui leur est envoyé.</p>
      </div>
      <div class="flex flex-wrap gap-2" role="group" aria-label="Filtrer les conversations">
        <button
          v-for="f in FILTERS" :key="f.value" type="button" :aria-pressed="filter === f.value"
          :class="['min-h-10 rounded-full px-3.5 text-sm font-semibold ring-1', filter === f.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-900 ring-slate-300']"
          @click="setFilter(f.value)"
        >
          {{ f.label }}<template v-if="f.value === 'unread' && unread"> · {{ unread }}</template>
        </button>
      </div>
    </div>

    <p v-if="loadError" class="field-error">{{ loadError }}</p>

    <div class="flex flex-wrap items-stretch gap-4">
      <!-- Conversations (cachées sur téléphone quand une conversation est ouverte) -->
      <section :class="['card flex min-w-0 flex-col overflow-hidden', 'w-full md:w-[22rem] md:shrink-0', current ? 'hidden md:flex' : 'flex']" aria-label="Conversations">
        <div class="border-b p-3">
          <input v-model="search" type="search" class="input" placeholder="Nom, numéro ou code de course" aria-label="Chercher une conversation" @input="debouncedLoad">
        </div>
        <p v-if="loading && !conversations.length" class="p-4 text-sm text-slate-600">Chargement…</p>
        <p v-else-if="!conversations.length" class="p-6 text-center text-sm text-slate-600">
          {{ filter === 'all' && !search ? 'Aucun message pour l\'instant. Les conversations apparaissent ici dès qu\'un message part ou arrive.' : 'Aucune conversation ne correspond.' }}
        </p>
        <ul class="max-h-[70vh] divide-y overflow-y-auto">
          <li v-for="c in conversations" :key="c.contact.phone">
            <button
              type="button"
              :class="['w-full px-4 py-3 text-left hover:bg-slate-50', current?.contact.phone === c.contact.phone ? 'border-l-4 border-blue-800 bg-blue-50' : 'border-l-4 border-transparent']"
              @click="open(c.contact.phone)"
            >
              <span class="flex items-baseline justify-between gap-2">
                <span class="truncate font-semibold">{{ c.contact.name }}</span>
                <span :class="['shrink-0 text-xs', c.unread ? 'font-semibold text-blue-800' : 'text-slate-600']">{{ when(c.last.at) }}</span>
              </span>
              <span class="block text-xs font-medium text-slate-600">
                {{ c.contact.kind_label }}<template v-if="c.contact.order"> · course {{ c.contact.order.tracking_code }}</template>
              </span>
              <span class="mt-0.5 flex items-center gap-2">
                <span :class="['flex-1 truncate text-sm', c.unread ? 'font-semibold text-slate-900' : 'text-slate-600']">
                  <template v-if="c.last.direction === 'out'">{{ c.last.automatic ? 'Auto : ' : 'Vous : ' }}</template>{{ c.last.body }}
                </span>
                <span v-if="c.failed" class="shrink-0 rounded-full bg-red-50 px-2 text-xs font-medium text-red-700 ring-1 ring-red-200">échec</span>
                <span v-if="c.unread" class="grid h-5 min-w-5 place-items-center rounded-full bg-red-600 px-1.5 text-xs font-semibold text-white">{{ c.unread }}</span>
              </span>
            </button>
          </li>
        </ul>
      </section>

      <!-- Conversation ouverte -->
      <section v-if="current" class="card flex min-w-0 flex-1 basis-[28rem] flex-col overflow-hidden" :aria-label="`Conversation avec ${current.contact.name}`">
        <div class="flex flex-wrap items-center gap-3 border-b px-4 py-3">
          <button type="button" class="btn-secondary md:hidden" @click="close">‹ Retour</button>
          <div class="min-w-48 flex-1">
            <p class="font-semibold">{{ current.contact.name }}<template v-if="current.contact.name !== current.contact.phone_display"> · {{ current.contact.phone_display }}</template></p>
            <p class="text-sm text-slate-600">
              {{ current.contact.kind_label }}
              <template v-if="current.contact.order">
                · course <RouterLink :to="`/admin/courses/${current.contact.order.id}`" class="text-blue-700 hover:underline">{{ current.contact.order.tracking_code }}</RouterLink>
                <template v-if="current.contact.order.merchant"> · {{ current.contact.order.merchant }}</template>
                <template v-if="current.contact.order.courier"> · livreur {{ current.contact.order.courier }}</template>
              </template>
            </p>
          </div>
          <span v-if="current.contact.order" :class="['rounded-full px-2.5 py-0.5 text-xs font-medium ring-1', statusClass(current.contact.order.status)]">{{ current.contact.order.status_label }}</span>
          <RouterLink v-if="current.contact.order" :to="`/admin/courses/${current.contact.order.id}`" class="btn-secondary">Ouvrir la course</RouterLink>
          <RouterLink v-if="current.contact.kind === 'merchant' && current.contact.merchant_id && auth.can('merchants.view')" :to="{ path: '/admin/marchands', query: { id: current.contact.merchant_id } }" class="btn-secondary">Fiche client</RouterLink>
          <a :href="telLink(current.contact.phone)" class="btn-secondary">Appeler</a>
        </div>

        <div ref="thread" class="flex max-h-[55vh] min-h-64 flex-1 flex-col gap-3 overflow-y-auto bg-slate-50 p-4">
          <template v-for="(m, i) in current.messages" :key="m.id">
            <p v-if="i === 0 || day(m.at) !== day(current.messages[i - 1].at)" class="self-center text-xs font-medium text-slate-600">{{ day(m.at) }}</p>
            <div :class="['max-w-[80%] rounded-xl border px-3.5 py-2.5', m.direction === 'in' ? 'self-start border-slate-200 bg-white' : (m.automatic ? 'self-end border-emerald-200 bg-emerald-50' : 'self-end border-blue-200 bg-blue-50')]">
              <p v-if="m.direction === 'out'" :class="['mb-1 text-xs font-medium', m.automatic ? 'text-emerald-800' : 'text-blue-800']">
                {{ m.automatic ? (m.robot ? 'Réponse du robot' : 'Envoyé automatiquement') : 'Réponse de l\'équipe' }}<template v-if="m.channel !== 'whatsapp'"> · {{ m.channel === 'sms' ? 'SMS' : 'notification' }}</template>
              </p>
              <p class="whitespace-pre-line break-words">{{ m.body }}</p>
              <p :class="['mt-1 text-xs text-slate-600', m.direction === 'out' ? 'text-right' : '']">
                {{ time(m.at) }}<template v-if="m.direction === 'out'"> · <span :class="m.status === 'failed' ? 'font-semibold text-red-700' : ''">{{ m.status_label.toLowerCase() }}</span></template>
              </p>
              <p v-if="m.status === 'failed'" class="mt-1 flex flex-wrap items-center justify-end gap-2 text-xs text-red-700">
                <span v-if="m.error">{{ m.error }}</span>
                <button type="button" class="rounded-lg bg-white px-2.5 py-1 font-semibold text-red-700 ring-1 ring-red-300 hover:bg-red-50" :disabled="retrying === m.message_id" @click="retry(m)">Renvoyer</button>
              </p>
            </div>
          </template>
        </div>

        <!-- Réponse -->
        <form class="space-y-2 border-t px-4 py-3" @submit.prevent="send">
          <template v-if="current.window.open">
            <div class="flex flex-wrap gap-2">
              <button v-for="q in quickReplies" :key="q" type="button" class="min-h-9 rounded-full bg-slate-100 px-3 text-sm text-slate-700 ring-1 ring-slate-300 hover:bg-slate-200" @click="reply = q">{{ q }}</button>
            </div>
            <div class="flex items-end gap-2">
              <textarea v-model="reply" rows="2" class="input flex-1 resize-none" :placeholder="`Écrire une réponse à ${current.contact.name}…`" aria-label="Votre réponse" maxlength="1000" @keydown.enter.exact.prevent="send" />
              <button class="btn-primary min-h-11" :disabled="sending || !reply.trim()">{{ sending ? 'Envoi…' : 'Envoyer' }}</button>
            </div>
            <p v-if="current.window.until" class="text-xs text-slate-600">Réponse libre possible encore {{ remaining(current.window.until) }} (règle de WhatsApp : 24 h après son dernier message).</p>
          </template>
          <p v-else class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ current.window.reason }}</p>
          <p v-if="sendError" class="field-error">{{ sendError }}</p>
        </form>
      </section>

      <section v-else class="card hidden flex-1 basis-[28rem] place-items-center p-10 text-center text-slate-600 md:grid">
        Choisissez une conversation à gauche.
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { statusClass, telLink } from '../../utils/format'

/**
 * Messages : la messagerie de l'entreprise, façon WhatsApp. Une conversation par numéro, avec les messages reçus
 * et envoyés (WhatsApp, SMS, notifications) ; l'équipe lit et répond. Tout se rafraîchit tout seul.
 */
const FILTERS = [
  { value: 'all', label: 'Toutes' },
  { value: 'unread', label: 'Non lues' },
  { value: 'client', label: 'Destinataires' },
  { value: 'merchant', label: 'Clients' },
  { value: 'courier', label: 'Livreurs' },
  { value: 'unknown', label: 'Inconnus' },
  { value: 'failed', label: 'Échecs' },
]
const QUICK = {
  client: ['Bien reçu, nous prévenons le livreur.', 'Le livreur va vous appeler.', 'D\'accord, nous reportons la livraison.'],
  merchant: ['Bien reçu, nous nous en occupons.', 'C\'est noté, merci.', 'Pouvez-vous nous envoyer le numéro du destinataire ?'],
  courier: ['Bien reçu.', 'Rappelle le destinataire, puis indique « échec » s\'il ne répond pas.', 'Rapporte le colis au dépôt.'],
  unknown: ['Bonjour, que pouvons-nous faire pour vous ?', 'Pour une livraison, envoyez l\'adresse de ramassage et celle de livraison.'],
}

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const conversations = ref([])
const unread = ref(0)
const filter = ref(FILTERS.some((f) => f.value === route.query.filtre) ? route.query.filtre : 'all')
const search = ref('')
const loading = ref(false)
const loadError = ref('')
const current = ref(null)
const reply = ref('')
const sending = ref(false)
const sendError = ref('')
const thread = ref(null)
const retrying = ref(null)
const quickReplies = computed(() => QUICK[current.value?.contact.kind] || QUICK.unknown)

const pad = (n) => String(n).padStart(2, '0')
const time = (at) => { const d = new Date(at); return `${pad(d.getHours())} h ${pad(d.getMinutes())}` }
function day(at) {
  const d = new Date(at)
  const today = new Date()
  const yesterday = new Date(Date.now() - 86400000)
  if (d.toDateString() === today.toDateString()) return 'Aujourd\'hui'
  if (d.toDateString() === yesterday.toDateString()) return 'Hier'
  return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
}
function when(at) {
  const d = day(at)
  return d === 'Aujourd\'hui' ? time(at) : d === 'Hier' ? 'hier' : new Date(at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
}
function remaining(until) {
  const minutes = Math.max(0, Math.round((new Date(until).getTime() - Date.now()) / 60000))
  return minutes >= 60 ? `${Math.floor(minutes / 60)} h ${pad(minutes % 60)}` : `${minutes} min`
}

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/inbox', { params: { filter: filter.value === 'all' ? undefined : filter.value, search: search.value || undefined } })
    conversations.value = data.data
    unread.value = data.meta.unread
    loadError.value = ''
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
}

function setFilter(value) {
  filter.value = value
  router.replace({ query: { ...route.query, filtre: value === 'all' ? undefined : value } })
  load()
}

async function scrollDown() {
  await nextTick()
  if (thread.value) thread.value.scrollTop = thread.value.scrollHeight
}

async function open(phone, { quiet = false } = {}) {
  try {
    const { data } = await http.get(`/inbox/${encodeURIComponent(phone)}`)
    const changed = current.value?.contact.phone !== phone || current.value?.messages.length !== data.data.messages.length
    current.value = data.data
    if (!quiet) {
      reply.value = ''
      sendError.value = ''
      if (route.query.n !== phone) router.replace({ query: { ...route.query, n: phone } })
    }
    if (changed) scrollDown()
    // Lue : compteurs de la liste et du menu à jour
    const row = conversations.value.find((c) => c.contact.phone === phone)
    if (row?.unread) {
      unread.value = Math.max(0, unread.value - 1)
      row.unread = 0
      window.dispatchEvent(new Event('inbox-changed'))
    }
  } catch (e) {
    if (!quiet) loadError.value = apiErrorMessage(e)
  }
}

function close() {
  current.value = null
  router.replace({ query: { ...route.query, n: undefined } })
}

async function send() {
  if (!reply.value.trim() || sending.value) return
  sending.value = true
  sendError.value = ''
  try {
    const { data } = await http.post(`/inbox/${encodeURIComponent(current.value.contact.phone)}/reply`, { text: reply.value })
    current.value = data.data
    reply.value = ''
    scrollDown()
  } catch (e) {
    sendError.value = apiErrorMessage(e)
  } finally {
    sending.value = false
  }
}

// Message en échec : renvoyé (SMS de repli ou nouvel essai, selon le message)
async function retry(m) {
  retrying.value = m.message_id
  try {
    await http.post(`/messages/${m.message_id}/retry`)
    await open(current.value.contact.phone, { quiet: true })
    load()
  } catch (e) {
    sendError.value = apiErrorMessage(e)
  } finally {
    retrying.value = null
  }
}

// Rafraîchi toutes les 20 s quand l'onglet est visible
let poll
onMounted(async () => {
  await load()
  if (typeof route.query.n === 'string') await open(route.query.n)
  poll = setInterval(async () => {
    if (document.visibilityState !== 'visible') return
    await load()
    if (current.value) await open(current.value.contact.phone, { quiet: true })
  }, 20000)
})
onBeforeUnmount(() => {
  clearInterval(poll)
  clearTimeout(timer)
})
</script>
