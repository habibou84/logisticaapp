<template>
  <div class="space-y-4 max-w-6xl">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Remontées terrain</h1>
        <p class="text-sm text-gray-600">Notes et problèmes enregistrés par les livreurs. Marquez-les comme traités pour que l'équipe sache qui s'en est occupé.</p>
      </div>
      <!-- Place réservée : la page ne bouge pas quand le son s'active au premier clic -->
      <p :class="['text-xs rounded bg-amber-50 text-amber-900 px-2 py-1', alertSound.unlocked || !alertSound.enabled ? 'invisible' : '']" :aria-hidden="alertSound.unlocked">
        🔊 Cliquez n'importe où dans la page pour activer le son des alertes.
      </p>
    </div>

    <!-- État -->
    <div class="flex gap-2 overflow-x-auto">
      <button
        v-for="s in states"
        :key="s.value"
        :class="['btn whitespace-nowrap', filters.state === s.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']"
        @click="filters.state = s.value; load(1)"
      >
        {{ s.label }}<span v-if="s.value === 'open' && store.open.total" class="ml-1 rounded-full bg-red-600 text-white px-1.5 text-xs">{{ store.open.total }}</span>
      </button>
    </div>

    <!-- Filtres -->
    <div class="card p-3 grid sm:grid-cols-2 lg:grid-cols-5 gap-2 items-end">
      <div>
        <label class="label" for="fr-kind">Type</label>
        <select id="fr-kind" v-model="filters.kind" class="input" @change="load(1)">
          <option value="">Tous</option>
          <option v-for="(k, value) in KINDS" :key="value" :value="value">{{ k.icon }} {{ k.label }}{{ store.open[value] ? ` (${store.open[value]} à traiter)` : '' }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="fr-courier">Livreur</label>
        <select id="fr-courier" v-model="filters.courier_id" class="input" @change="load(1)">
          <option value="">Tous</option>
          <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="fr-from">Du</label>
        <input id="fr-from" v-model="filters.from" type="date" class="input" @change="load(1)">
      </div>
      <div>
        <label class="label" for="fr-to">Au</label>
        <input id="fr-to" v-model="filters.to" type="date" class="input" @change="load(1)">
      </div>
      <div>
        <label class="label" for="fr-search">Recherche</label>
        <input id="fr-search" v-model="filters.search" type="search" class="input" placeholder="Code, client, texte…" @input="debouncedLoad">
      </div>
    </div>

    <!-- Liste -->
    <div class="space-y-2">
      <article
        v-for="r in reports"
        :key="r.id"
        :class="['card p-4 border-l-4', r.review ? 'border-l-slate-300 opacity-80' : KINDS[r.kind].border, highlighted.has(r.id) ? 'ring-2 ring-amber-300' : '']"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 space-y-1">
            <p class="text-sm">
              <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', KINDS[r.kind].badge]">{{ KINDS[r.kind].icon }} {{ title(r) }}</span>
              <span class="ml-2 text-gray-500">{{ dateTime(r.created_at) }}</span>
              <span v-if="!r.visible_to_merchant && r.kind === 'note'" class="ml-2 text-xs text-gray-500">· note interne</span>
            </p>
            <p v-if="r.note" class="font-medium">« {{ r.note }} »</p>
            <p v-if="r.rescheduled_to" class="text-sm">📅 Reportée au {{ date(r.rescheduled_to) }}</p>
            <p v-if="r.expense" class="text-sm">💸 {{ money(r.expense) }}</p>
            <p class="text-sm text-gray-600">
              🛵 <strong>{{ r.courier.name }}</strong>
              <a v-if="r.courier.phone" :href="telLink(r.courier.phone)" class="text-blue-600"> · {{ r.courier.phone }}</a>
            </p>
            <p v-if="r.order" class="text-sm text-gray-600">
              📦 <RouterLink :to="`/admin/courses/${r.order.id}`" class="font-mono text-blue-700">{{ r.order.tracking_code }}</RouterLink>
              · {{ r.order.merchant }} → {{ r.order.zone }} · {{ r.order.recipient_name || 'Client' }}
              <a :href="telLink(r.order.recipient_phone)" class="text-blue-600">{{ r.order.recipient_phone }}</a>
              · <span class="text-gray-500">{{ r.order.status_label }}</span>
            </p>
            <p v-if="r.photos" class="text-xs text-gray-500">📷 {{ r.photos }} photo(s) sur la fiche de la course</p>
            <CourierMessageThread
              v-if="r.replies?.length || replying === r.id"
              class="pt-1"
              :order-id="r.order.id"
              :messages="r.replies || []"
              :reply-to="r.id"
              :composer="replying === r.id"
              cancelable
              @sent="(m, handled) => replied(r, m, handled)"
              @cancel="replying = null"
            />
            <p v-if="r.review" class="text-sm text-emerald-700">
              ✓ Traité par {{ r.review.handled_by || '—' }} le {{ dateTime(r.review.handled_at) }}<span v-if="r.review.comment"> : {{ r.review.comment }}</span>
            </p>
          </div>

          <div class="flex flex-col gap-2 shrink-0 w-full sm:w-64">
            <button v-if="r.order && replying !== r.id" type="button" class="btn-primary" @click="replying = r.id">💬 Répondre au livreur</button>
            <template v-if="!r.review">
              <input v-model="comments[r.id]" class="input text-sm" placeholder="Ce qui a été fait (facultatif)" :aria-label="`Commentaire sur la remontée ${r.id}`" @keydown.enter="handle(r)">
              <button type="button" class="btn-success" @click="handle(r)">✓ Marquer comme traité</button>
            </template>
            <button v-else type="button" class="btn-secondary text-xs" @click="reopen(r)">Remettre à traiter</button>
            <RouterLink v-if="r.order" :to="`/admin/courses/${r.order.id}`" class="btn-secondary text-center text-xs">Ouvrir la course</RouterLink>
          </div>
        </div>
      </article>

      <div v-if="loaded && !reports.length" class="card p-8 text-center text-gray-500">
        {{ filters.state === 'open' ? '🎉 Rien à traiter pour le moment.' : 'Aucune remontée pour ces critères.' }}
      </div>
    </div>

    <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between text-sm">
      <span class="text-gray-500">Page {{ meta.current_page }} / {{ meta.last_page }} · {{ meta.total }} remontée(s)</span>
      <div class="flex gap-2">
        <button class="btn-secondary" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">Précédent</button>
        <button class="btn-secondary" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Suivant</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { alertSound } from '../../composables/useAlertSound'
import CourierMessageThread from '../../components/CourierMessageThread.vue'
import { useFieldReportStore } from '../../stores/fieldReports'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime, money, telLink } from '../../utils/format'

const KINDS = {
  incident: { label: 'Problème', icon: '⚠️', border: 'border-l-red-500', badge: 'bg-red-100 text-red-800' },
  note: { label: 'Note', icon: '📝', border: 'border-l-sky-500', badge: 'bg-sky-100 text-sky-800' },
  refusal: { label: 'Mission refusée', icon: '🚫', border: 'border-l-amber-500', badge: 'bg-amber-100 text-amber-800' },
  expense: { label: 'Frais déclarés', icon: '💸', border: 'border-l-violet-500', badge: 'bg-violet-100 text-violet-800' },
}
const states = [
  { value: 'open', label: 'À traiter' },
  { value: 'handled', label: 'Traitées' },
  { value: 'all', label: 'Toutes' },
]

const store = useFieldReportStore()
const toasts = useToastStore()
const reports = ref([])
const meta = ref(null)
const loaded = ref(false)
const couriers = ref([])
const comments = reactive({})
const highlighted = ref(new Set())
const replying = ref(null)
const filters = reactive({ state: 'open', kind: '', courier_id: '', from: '', to: '', search: '' })

function title(r) {
  if (r.kind === 'incident') return r.incident || r.status_label || 'Problème'
  return KINDS[r.kind].label
}

async function load(page = 1) {
  const params = Object.fromEntries(Object.entries({ ...filters, page }).filter(([, v]) => v !== '' && v !== null))
  const { data } = await http.get('/field-reports', { params })
  const known = new Set(reports.value.map((r) => r.id))
  reports.value = data.data
  meta.value = data.meta
  store.setCounts(data.open)
  // Les remontées arrivées en direct sont mises en évidence
  if (loaded.value) highlighted.value = new Set(data.data.filter((r) => !known.has(r.id)).map((r) => r.id))
  loaded.value = true
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(() => load(1), 300)
}

async function handle(r) {
  try {
    const { data } = await http.post(`/field-reports/${r.id}/handle`, { comment: comments[r.id] || null })
    store.setCounts(data.open)
    delete comments[r.id]
    if (filters.state === 'open') reports.value = reports.value.filter((x) => x.id !== r.id)
    else Object.assign(r, data.data)
    toasts.success('Remontée marquée comme traitée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function replied(r, message, handled) {
  r.replies = [...(r.replies || []), message]
  replying.value = null
  toasts.success('Consigne envoyée au livreur.')
  if (handled && !r.review) {
    if (filters.state === 'open') reports.value = reports.value.filter((x) => x.id !== r.id)
    store.fetchCounts().catch(() => {})
  }
}

async function reopen(r) {
  const { data } = await http.post(`/field-reports/${r.id}/reopen`)
  store.setCounts(data.open)
  if (filters.state === 'handled') reports.value = reports.value.filter((x) => x.id !== r.id)
  else Object.assign(r, data.data)
}

// Nouvelle remontée reçue en direct
watch(() => store.version, () => load(meta.value?.current_page || 1))

onMounted(async () => {
  load()
  couriers.value = (await http.get('/couriers')).data.data
})
</script>
