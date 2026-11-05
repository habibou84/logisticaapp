<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <RouterLink to="/admin/courses" class="text-sm text-blue-600">‹ Courses</RouterLink>
        <h1 class="text-xl font-bold">⏰ À reporter</h1>
        <p class="text-sm text-gray-600">Date de livraison passée, colis pas encore ramassés. Ils sont cachés chez les livreurs tant que la date n'est pas actualisée.</p>
      </div>
      <RouterLink v-if="auth.can('settings.manage')" to="/admin/reglages/courses" class="text-sm text-blue-600">⚙️ Message du soir</RouterLink>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-if="!data && !error" class="card p-6 text-center text-gray-500">Chargement…</p>

    <template v-if="data">
      <!-- Point -->
      <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
        <div class="card px-3 py-2">
          <p class="text-xs text-gray-500">À reporter</p>
          <p class="text-lg font-bold">{{ data.summary.orders }} colis</p>
          <p class="text-xs text-gray-500">{{ data.summary.merchants }} client{{ data.summary.merchants > 1 ? 's' : '' }}</p>
        </div>
        <div class="card border-amber-300 bg-amber-50 px-3 py-2">
          <p class="text-xs text-amber-800">Pas encore demandé</p>
          <p class="text-lg font-bold text-amber-900">{{ data.summary.not_asked }}</p>
          <p class="text-xs text-amber-800">client{{ data.summary.not_asked > 1 ? 's' : '' }}</p>
        </div>
        <div class="card border-blue-300 bg-blue-50 px-3 py-2">
          <p class="text-xs text-blue-800">En attente de réponse</p>
          <p class="text-lg font-bold text-blue-900">{{ data.summary.waiting }}</p>
          <p class="text-xs text-blue-800">{{ data.settings.no_answer === 'auto_next_day' ? 'report automatique' : 'rappel' }} à 08:00</p>
        </div>
        <div class="card border-emerald-300 bg-emerald-50 px-3 py-2">
          <p class="text-xs text-emerald-800">Répondu aujourd'hui</p>
          <p class="text-lg font-bold text-emerald-900">{{ data.summary.answered_today }}</p>
          <p class="truncate text-xs text-emerald-800">{{ data.answered.slice(0, 2).map((a) => a.merchant_name).join(', ') || '—' }}</p>
        </div>
      </div>

      <p v-if="!data.groups.length" class="card p-6 text-center text-gray-500">✅ Aucun colis à reporter : toutes les dates sont à jour.</p>

      <!-- Une ligne par client -->
      <div v-else class="card divide-y">
        <div v-for="g in data.groups" :key="g.merchant?.id" class="px-3 py-2">
          <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <input
              v-model="checked" type="checkbox" :value="g.merchant?.id" class="h-4 w-4"
              :aria-label="`Choisir ${g.merchant?.business_name}`"
            >
            <button type="button" class="min-w-[12rem] flex-1 text-left" :aria-expanded="open.includes(g.merchant?.id)" @click="toggle(g.merchant?.id)">
              <span class="font-semibold">{{ open.includes(g.merchant?.id) ? '▾' : '▸' }} {{ g.merchant?.business_name }}</span>
              <span class="block text-xs text-gray-500">
                {{ g.merchant?.zone_name }}<template v-if="g.merchant?.address">, {{ g.merchant.address }}</template> ·
                {{ g.merchant?.uses_app ? '📱 Jibiat Marchand' : '💬 WhatsApp' }}
              </span>
            </button>
            <span class="w-24 text-sm"><b>{{ g.count }}</b> colis<span class="block text-xs text-gray-500">{{ money(g.cod_total) }}</span></span>
            <span class="w-28 text-sm">{{ shortDay(g.missed_day) }}<span class="block text-xs text-red-700">{{ since(g.missed_day) }}</span></span>
            <span class="w-44 text-xs">
              <template v-if="g.request">
                <span class="rounded-full bg-blue-100 px-2 py-0.5 font-semibold text-blue-900">⏳ Demandé {{ time(g.request.created_at) }}</span>
                <span v-if="g.request.reminded_at" class="mt-0.5 block text-gray-500">relancé {{ time(g.request.reminded_at) }}</span>
                <span v-else-if="!g.request.whatsapp_sent_at" class="mt-0.5 block text-gray-500">message WhatsApp pas encore parti</span>
              </template>
              <span v-else class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-900">Pas encore demandé</span>
            </span>
            <span class="flex flex-wrap justify-end gap-1 sm:w-52">
              <template v-if="g.request">
                <button type="button" class="btn-secondary !px-2 !py-1 text-xs" :disabled="busy" @click="remind(g)">Relancer</button>
                <button type="button" class="btn-primary !px-2 !py-1 text-xs" @click="openDecide(g)">Trancher</button>
              </template>
              <template v-else>
                <button type="button" class="btn-primary !px-2 !py-1 text-xs" @click="openAsk([g])">Demander</button>
                <button type="button" class="btn-secondary !px-2 !py-1 text-xs" @click="openDecide(g)">Choisir la date</button>
              </template>
            </span>
          </div>

          <div v-if="open.includes(g.merchant?.id)" class="mt-2 divide-y rounded-lg bg-slate-50 text-sm">
            <RouterLink
              v-for="o in g.orders" :key="o.id" :to="`/admin/courses/${o.id}`"
              class="flex flex-wrap items-center gap-x-3 px-3 py-1.5 hover:bg-slate-100"
            >
              <span class="w-28 font-mono text-xs font-semibold">{{ o.tracking_code }}</span>
              <span class="min-w-[10rem] flex-1">{{ o.recipient_name }} · {{ o.delivery_zone_name || '—' }}</span>
              <span class="text-xs text-gray-500">{{ o.courier_name ? `chez ${o.courier_name} (pas commencé)` : 'sans livreur' }}</span>
              <span class="w-20 text-xs text-red-700">{{ shortDay(o.delivery_day) }}</span>
              <span class="w-20 text-right tabular-nums">{{ money(o.cod_amount) }}</span>
            </RouterLink>
          </div>
        </div>
      </div>

      <!-- Actions groupées -->
      <div v-if="checked.length" class="sticky bottom-2 z-10 flex flex-wrap items-center gap-2 rounded-xl bg-slate-900 px-3 py-2 text-sm text-white shadow-lg">
        <span class="flex-1">☑️ {{ checked.length }} client{{ checked.length > 1 ? 's' : '' }} · {{ checkedOrders.length }} colis</span>
        <button type="button" class="rounded-lg bg-white px-3 py-1 font-semibold text-slate-900" @click="openAsk(checkedGroups)">Demander aux {{ checked.length }}</button>
        <button type="button" class="rounded-lg bg-emerald-500 px-3 py-1 font-semibold" :disabled="busy" @click="allToday">Tout livrer aujourd'hui</button>
        <button type="button" class="px-2 text-slate-300" @click="checked = []">Annuler</button>
      </div>
    </template>

    <!-- Demander -->
    <Modal :open="ask.open" :title="ask.groups.length === 1 ? `Demander à ${ask.groups[0].merchant?.business_name}` : `Demander à ${ask.groups.length} clients`" @close="ask.open = false">
      <form class="space-y-3" @submit.prevent="sendAsk">
        <p class="text-sm">
          {{ askCount }} colis n'ont pas pu être ramassés. L'client choisit pour chacun :
          <b>une nouvelle date</b> (jusqu'au {{ shortDay(data?.max_date) }}) ou <b>l'annulation</b>.
        </p>
        <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm">
          <p class="font-semibold">Envoyé par</p>
          <p v-for="g in ask.groups" :key="g.merchant?.id" class="text-gray-700">
            {{ g.merchant?.business_name }} :
            <template v-if="g.merchant?.uses_app && data.settings.channel === 'app_first'">📱 Jibiat Marchand, puis 💬 WhatsApp sans réponse en 30 min</template>
            <template v-else>💬 WhatsApp (lien vers la page de réponse)</template>
          </p>
        </div>
        <p class="text-xs text-gray-500">Sans réponse : {{ data?.settings.no_answer === 'auto_next_day' ? 'livraison reportée toute seule le lendemain à 08:00' : 'rappel le lendemain à 08:00, puis à vous de trancher' }}.</p>
        <div>
          <label class="label" for="ask-note">Mot pour le client (facultatif)</label>
          <textarea id="ask-note" v-model="ask.note" class="input" rows="2" maxlength="300" placeholder="Désolé, le livreur n'a pas pu passer…" />
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="ask.open = false">Fermer</button>
          <button class="btn-primary" :disabled="busy">Envoyer la demande</button>
        </div>
      </form>
    </Modal>

    <!-- Choisir la date / Trancher -->
    <Modal :open="decide.open" :title="`${decide.group?.merchant?.business_name} · ${decide.group?.count} colis`" @close="decide.open = false">
      <form class="space-y-3" @submit.prevent="sendDecide">
        <label :class="['flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm', decide.mode === 'today' ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200']">
          <input v-model="decide.mode" type="radio" value="today" class="mt-1">
          <span><b>Livrer aujourd'hui</b><span class="block text-xs text-gray-500">La date passe au {{ shortDay(data?.today) }} ; le client est prévenu.</span></span>
        </label>
        <label :class="['flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm', decide.mode === 'date' ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200']">
          <input v-model="decide.mode" type="radio" value="date" class="mt-1">
          <span class="flex-1"><b>Une autre date</b><span class="block text-xs text-gray-500">L'client est prévenu.</span>
            <input v-if="decide.mode === 'date'" v-model="decide.date" type="date" class="input mt-1" :min="data?.today" :max="data?.max_date" required aria-label="Nouvelle date">
          </span>
        </label>
        <p class="text-xs text-gray-500">Pour annuler un colis, ouvrez sa fiche (le motif est obligatoire).</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="decide.open = false">Fermer</button>
          <button class="btn-primary" :disabled="busy">Valider</button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useAuthStore } from '../../stores/auth'
import { useDispatchCountStore } from '../../stores/dispatchCounts'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'

/**
 * File « À reporter » : colis pas ramassés dont la date de livraison est passée, par client. Demander une
 * nouvelle date (WhatsApp ou page du lien), relancer, ou trancher (aujourd'hui, autre date).
 */
const auth = useAuthStore()
const toasts = useToastStore()
const dispatchCounts = useDispatchCountStore()
const data = ref(null)
const error = ref('')
const busy = ref(false)
const open = ref([])
const checked = ref([])
const ask = reactive({ open: false, groups: [], note: '' })
const decide = reactive({ open: false, group: null, mode: 'today', date: '' })

const checkedGroups = computed(() => (data.value?.groups || []).filter((g) => checked.value.includes(g.merchant?.id)))
const checkedOrders = computed(() => checkedGroups.value.flatMap((g) => g.orders))
const askCount = computed(() => ask.groups.reduce((n, g) => n + g.count, 0))

const shortDay = (d) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' }) : '')
const time = (v) => (v ? new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '')
function since(day) {
  if (!day || !data.value) return ''
  const days = Math.round((new Date(`${data.value.today}T12:00:00`) - new Date(`${day}T12:00:00`)) / 86400000)
  return days <= 1 ? 'hier' : `depuis ${days} jours`
}

function toggle(id) {
  open.value = open.value.includes(id) ? open.value.filter((x) => x !== id) : [...open.value, id]
}

async function load() {
  try {
    data.value = (await http.get('/orders/to-reschedule')).data.data
    checked.value = checked.value.filter((id) => data.value.groups.some((g) => g.merchant?.id === id))
    dispatchCounts.fetchCounts()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function openAsk(groups) {
  Object.assign(ask, { open: true, groups: groups.filter((g) => !g.request || groups.length === 1), note: '' })
}

async function sendAsk() {
  busy.value = true
  try {
    const sent = (await http.post('/reschedule-requests', { merchant_ids: ask.groups.map((g) => g.merchant.id), note: ask.note || null })).data.data.sent
    toasts.success(`Demande envoyée à ${sent} client${sent > 1 ? 's' : ''}.`)
    ask.open = false
    checked.value = []
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function remind(g) {
  busy.value = true
  try {
    await http.post(`/reschedule-requests/${g.request.id}/remind`)
    toasts.success(`${g.merchant.business_name} relancé par WhatsApp.`)
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

function openDecide(g) {
  Object.assign(decide, { open: true, group: g, mode: 'today', date: '' })
}

async function setDate(orderIds, day) {
  busy.value = true
  try {
    const updated = (await http.post('/orders/reschedule', { order_ids: orderIds, date: day })).data.data.updated
    toasts.success(`${updated} colis : nouvelle date le ${shortDay(day)}. Client prévenu.`)
    await load()
    return true
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return false
  } finally {
    busy.value = false
  }
}

async function sendDecide() {
  const day = decide.mode === 'today' ? data.value.today : decide.date
  if (await setDate(decide.group.orders.map((o) => o.id), day)) decide.open = false
}

async function allToday() {
  if (await setDate(checkedOrders.value.map((o) => o.id), data.value.today)) checked.value = []
}

onMounted(load)
</script>
