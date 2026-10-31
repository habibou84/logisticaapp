<template>
  <div class="space-y-4 max-w-6xl">
    <div>
      <h1 v-if="!embedded" class="text-xl font-bold">Colis chez les livreurs</h1>
      <p class="text-sm text-gray-600">
        Colis ramassés, en livraison, en échec ou reportés que les livreurs n'ont pas encore rendus au dépôt.
        <template v-if="alertHours">Au-delà de {{ alertHours }} h, le colis est signalé en rouge et le dispatch est alerté.</template>
      </p>
    </div>


    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
      <StatCard title="Colis chez les livreurs" :value="store.counts.total" icon="📦" />
      <StatCard :title="alertHours ? `Depuis plus de ${alertHours} h` : 'En retard'" :value="store.counts.overdue" icon="⏰" />
      <StatCard title="Livreurs concernés" :value="couriers.length" icon="🛵" />
    </div>


    <div class="flex flex-wrap gap-2 items-center">
      <button v-for="f in filters" :key="f.value" :class="['btn whitespace-nowrap', overdueOnly === f.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']" @click="overdueOnly = f.value; reload()">
        {{ f.label }}
      </button>
      <input v-model="search" type="search" class="input sm:w-64 ml-auto" placeholder="Livreur, code, destinataire, client…" aria-label="Rechercher">
    </div>

    <!-- Un résumé par livreur ; ses colis se chargent quand on l'ouvre, 25 par 25 -->
    <section v-for="c in couriers" :key="c.courier_id" class="card">
      <header class="flex flex-wrap items-center justify-between gap-2 p-4" :class="open[c.courier_id] ? 'border-b' : ''">
        <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" :aria-expanded="!!open[c.courier_id]" @click="toggle(c)">
          <span class="text-lg text-gray-500" aria-hidden="true">{{ open[c.courier_id] ? '▾' : '▸' }}</span>
          <span class="min-w-0">
            <span class="block font-semibold">
              🛵 {{ c.name }}
              <span :class="['ml-1 rounded-full px-2 py-0.5 text-xs', c.is_available ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600']">{{ c.is_available ? 'en service' : 'hors service' }}</span>
            </span>
            <span class="block text-sm text-gray-600">
              {{ c.count }} colis<span v-if="c.overdue" class="text-red-600 font-medium"> dont {{ c.overdue }} en retard</span>
              <template v-if="c.oldest"> · le plus ancien depuis {{ heldFor(c.oldest) }}</template>
            </span>
          </span>
        </button>
        <a v-if="c.phone" :href="telLink(c.phone)" class="text-sm text-blue-600">{{ c.phone }}</a>
        <div v-if="canReceive && open[c.courier_id]" class="flex gap-2">
          <button type="button" class="btn-secondary text-xs" @click="toggleAll(c)">{{ allSelected(c) ? 'Tout décocher' : 'Tout cocher' }}</button>
          <button type="button" class="btn-success" :disabled="!selectedOf(c).length" @click="receive(c)">✓ Rendus au dépôt ({{ selectedOf(c).length }})</button>
        </div>
      </header>
      <template v-if="open[c.courier_id]">
        <p v-if="open[c.courier_id].loading && !open[c.courier_id].parcels.length" class="p-4 text-sm text-gray-500">Chargement…</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-gray-600">
              <tr><th v-if="canReceive" class="p-2 w-8" /><th class="p-2">Colis</th><th class="p-2">Destinataire</th><th class="p-2">Situation</th><th class="p-2 text-right">À encaisser</th><th class="p-2">Chez le livreur</th></tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="p in open[c.courier_id].parcels" :key="p.id" :class="isOverdue(p) ? 'bg-red-50' : ''">
                <td v-if="canReceive" class="p-2">
                  <input v-if="!p.on_the_road" v-model="selected" type="checkbox" :value="p.id" :aria-label="`Colis ${p.tracking_code} rendu`">
                </td>
                <td class="p-2">
                  <RouterLink :to="`/admin/courses/${p.id}`" class="font-mono text-blue-700">{{ p.tracking_code }}</RouterLink>
                  <div class="text-xs text-gray-500">{{ p.merchant }}</div>
                </td>
                <td class="p-2">{{ p.recipient_name || 'Destinataire' }} <a :href="telLink(p.recipient_phone)" class="text-blue-600 text-xs">{{ p.recipient_phone }}</a><div class="text-xs text-gray-500">{{ p.zone }}</div></td>
                <td class="p-2">
                  <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', p.on_the_road ? 'bg-sky-100 text-sky-800' : 'bg-amber-100 text-amber-900']">{{ p.status_label }}</span>
                  <div v-if="p.incident" class="text-xs text-gray-600 mt-0.5">{{ p.incident }}</div>
                  <div v-if="p.rescheduled_to" class="text-xs text-gray-600">📅 {{ date(p.rescheduled_to) }}</div>
                  <div v-if="p.return_requested" class="text-xs text-rose-700 font-medium">↩️ Retour au client à organiser</div>
                  <div v-if="p.kept_for" class="text-xs text-indigo-700 font-medium">🌙 Gardé pour sa livraison du {{ date(p.kept_for) }}</div>
                  <div v-else-if="p.custody_plan === 'depot' && p.status === 'picked_up'" class="text-xs text-slate-700 font-medium">🏢 À rapporter au dépôt (mission ramassage)</div>
                </td>
                <td class="p-2 text-right">{{ p.cod_amount ? money(p.cod_amount) : '—' }}</td>
                <td :class="['p-2 whitespace-nowrap', isOverdue(p) ? 'text-red-700 font-semibold' : 'text-gray-600']">
                  {{ heldFor(p.held_since) }}
                  <div class="text-xs font-normal text-gray-500">{{ dateTime(p.held_since) }}</div>
                  <RouterLink v-if="maybeLost(p)" :to="`/admin/courses/${p.id}`" class="text-xs font-semibold text-red-700 underline">🚨 Peut-être perdu : vérifier</RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="open[c.courier_id].page && open[c.courier_id].page < open[c.courier_id].lastPage" class="border-t p-3 text-center">
          <button type="button" class="btn-secondary" :disabled="open[c.courier_id].loading" @click="loadParcels(c, open[c.courier_id].page + 1)">
            Voir plus ({{ open[c.courier_id].parcels.length }} sur {{ open[c.courier_id].total }})
          </button>
        </div>
      </template>
    </section>

    <div v-if="loaded && !couriers.length" class="card p-8 text-center text-gray-500">
      {{ search.trim() ? 'Aucun colis ne correspond à la recherche.' : overdueOnly ? '🎉 Aucun colis en retard.' : '🎉 Aucun colis chez les livreurs : tout est livré ou au dépôt.' }}
    </div>
  </div>
</template>

<script setup>
// Onglet de « Activité des livreurs » : le titre de la page n'est pas répété
defineProps({ embedded: { type: Boolean, default: false } })
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import StatCard from '../../components/StatCard.vue'
import { orderChanges } from '../../composables/useRealtime'
import { useAuthStore } from '../../stores/auth'
import { useHeldParcelStore } from '../../stores/heldParcels'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime, money, telLink } from '../../utils/format'

const auth = useAuthStore()
const store = useHeldParcelStore()
const toasts = useToastStore()
const couriers = ref([])
const alertHours = ref(24)
const loaded = ref(false)
const overdueOnly = ref(false)
const search = ref('')
const selected = ref([])
const filters = [{ value: false, label: 'Tous les colis' }, { value: true, label: '⏰ En retard' }]
const canReceive = computed(() => auth.can('orders.dispatch') || auth.can('finance.manage'))

// Livreurs ouverts : leurs colis déjà chargés, page par page
const open = reactive({})
const params = () => ({ ...(overdueOnly.value ? { overdue: 1 } : {}), ...(search.value.trim() ? { q: search.value.trim() } : {}) })

// Au-delà de trois fois le délai : à retrouver ou à déclarer perdu (fiche de la course)
const maybeLost = (p) => !p.kept_for && alertHours.value > 0 && Date.now() - new Date(p.held_since).getTime() >= 3 * alertHours.value * 3600000
// Gardé pour sa livraison à venir (mission ramassage) : pas en retard
const isOverdue = (p) => !p.kept_for && alertHours.value > 0 && Date.now() - new Date(p.held_since).getTime() >= alertHours.value * 3600000
function heldFor(since) {
  const hours = Math.floor((Date.now() - new Date(since).getTime()) / 3600000)
  if (hours < 1) return "moins d'1 h"
  return hours >= 24 ? `${Math.floor(hours / 24)} j ${hours % 24} h` : `${hours} h`
}

const receivable = (c) => (open[c.courier_id]?.parcels || []).filter((p) => !p.on_the_road).map((p) => p.id)
const selectedOf = (c) => receivable(c).filter((id) => selected.value.includes(id))
const allSelected = (c) => receivable(c).length > 0 && selectedOf(c).length === receivable(c).length
function toggleAll(c) {
  const ids = receivable(c)
  selected.value = allSelected(c) ? selected.value.filter((id) => !ids.includes(id)) : [...new Set([...selected.value, ...ids])]
}

function toggle(c) {
  if (open[c.courier_id]) {
    const ids = receivable(c)
    selected.value = selected.value.filter((id) => !ids.includes(id))
    delete open[c.courier_id]
  } else {
    loadParcels(c, 1)
  }
}

async function loadParcels(c, page) {
  if (!open[c.courier_id]) open[c.courier_id] = { parcels: [], page: 0, lastPage: 1, total: 0, loading: false }
  const state = open[c.courier_id]
  state.loading = true
  try {
    const { data } = await http.get(`/couriers/${c.courier_id}/parcels`, { params: { ...params(), page } })
    state.parcels = page === 1 ? data.data : [...state.parcels, ...data.data]
    state.page = data.meta.current_page
    state.lastPage = data.meta.last_page
    state.total = data.meta.total
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    state.loading = false
  }
}


async function load() {
  const { data } = await http.get('/parcels/held', { params: params() })
  couriers.value = data.data
  alertHours.value = data.meta.alert_hours
  store.setCounts(data.meta)
  loaded.value = true
  // Les livreurs déjà ouverts sont rechargés (jusqu'à la page déjà vue), les autres refermés
  const present = new Set(data.data.map((c) => c.courier_id))
  for (const id of Object.keys(open)) {
    if (!present.has(Number(id))) delete open[id]
  }
  await Promise.all(data.data.filter((c) => open[c.courier_id]).map((c) => loadParcels(c, 1)))
  const ids = new Set(Object.values(open).flatMap((o) => o.parcels.map((p) => p.id)))
  selected.value = selected.value.filter((id) => ids.has(id))
}

// Filtre ou recherche changés : tout est refermé et recompté
function reload() {
  for (const id of Object.keys(open)) delete open[id]
  selected.value = []
  load()
}
let searchTimer
watch(search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(reload, 400) })

async function receive(c) {
  const ids = selectedOf(c)
  try {
    await http.post(`/couriers/${c.courier_id}/parcels/receive`, { order_ids: ids })
    toasts.success(`${ids.length} colis de ${c.name} reçu(s) au dépôt.`)
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

// Changements de courses et alertes en direct
let timer
watch([orderChanges, () => store.version], () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    load()
  }, 500)
})

onMounted(load)
</script>
