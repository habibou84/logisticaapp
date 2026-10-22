<template>
  <div class="space-y-4 max-w-6xl">
    <div>
      <h1 class="text-xl font-bold">Disponibilité des livreurs</h1>
      <p class="text-sm text-gray-600">
        Qui a fini sa tournée (ou presque), où il se trouve, et les colis à ramasser près de lui.
        Une mission ramassage ne change pas la date de livraison : les colis seront prêts pour leur jour prévu.
      </p>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
      <StatCard title="Livreurs libres" :value="counts.free" icon="🟢" />
      <StatCard :title="`Bientôt libres (≤ ${soon} missions)`" :value="counts.soon" icon="🟡" />
      <StatCard title="Avec des colis à ramasser près d'eux" :value="counts.withPickups" icon="📦" />
    </div>

    <div class="flex flex-wrap gap-2 items-center">
      <button v-for="f in filters" :key="f.value" :class="['btn whitespace-nowrap', filter === f.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']" @click="filter = f.value">
        {{ f.label }}
      </button>
    </div>

    <section v-for="c in visible" :key="c.id" class="card p-4 flex flex-wrap items-center gap-4">
      <div class="min-w-0 flex-1">
        <p class="font-semibold">
          🛵 {{ c.name }}
          <span :class="['ml-1 rounded-full px-2 py-0.5 text-xs font-medium', STATES[c.state].class]">{{ STATES[c.state].label }}</span>
        </p>
        <p class="text-sm text-gray-600">
          <a v-if="c.phone" :href="telLink(c.phone)" class="text-blue-600">{{ c.phone }}</a>
          <span v-if="c.zones.length"> · Zones : {{ c.zones.join(', ') }}</span>
        </p>
        <p class="text-sm mt-1">
          <template v-if="c.arrival_zone">📍 Dernier arrêt à <strong>{{ c.arrival_zone.name }}</strong> à {{ time(c.last_stop_at) }}</template>
          <template v-else>📍 Pas encore d'arrêt aujourd'hui</template>
          <span v-if="c.position" class="text-gray-500"> · position GPS il y a {{ ago(c.position.at) }}</span>
        </p>
        <p class="text-sm text-gray-600">
          {{ c.remaining.total ? `${c.remaining.total} mission${c.remaining.total > 1 ? 's' : ''} en cours (${missionsDetail(c)})` : 'Aucune mission en cours' }}
          · {{ c.done_today }} arrêt{{ c.done_today > 1 ? 's' : '' }} fait{{ c.done_today > 1 ? 's' : '' }} aujourd'hui
        </p>
      </div>
      <div class="text-right shrink-0">
        <p v-if="c.nearby.count" class="text-sm">
          <strong>{{ c.nearby.count }} colis</strong> à ramasser près de lui
          <span class="text-gray-500">({{ c.nearby.stops }} boutique{{ c.nearby.stops > 1 ? 's' : '' }}<template v-if="c.nearby.for_tomorrow">, {{ c.nearby.for_tomorrow }} pour demain ou plus tard</template>)</span>
        </p>
        <p v-else class="text-sm text-gray-500">Rien à ramasser près de lui</p>
        <button v-if="c.nearby.count && c.state !== 'off'" type="button" class="btn-primary mt-2" @click="openMission(c)">📦 Mission ramassage</button>
      </div>
    </section>

    <div v-if="loaded && !visible.length" class="card p-8 text-center text-gray-500">
      {{ filter === 'ready' ? 'Aucun livreur libre ou bientôt libre pour le moment.' : 'Aucun livreur actif.' }}
    </div>

    <!-- Mission ramassage : colis proposés, les plus proches d'abord -->
    <Modal :open="!!mission" :title="mission ? `Mission ramassage : ${mission.name}` : ''" @close="mission = null">
      <div v-if="mission" class="space-y-3">
        <p class="text-sm text-gray-600">
          <template v-if="mission.arrival_zone">Dernier arrêt à <strong>{{ mission.arrival_zone.name }}</strong>. </template>
          Cochez les colis à lui confier. La date de livraison de chaque colis reste la même.
        </p>
        <p v-if="!mission.stops" class="text-center text-gray-400 py-4">Chargement…</p>
        <div v-for="s in mission.stops || []" :key="s.key" class="rounded-lg border p-3">
          <label class="flex items-start gap-2">
            <input type="checkbox" class="mt-1" :checked="stopChecked(s)" :aria-label="`Tous les colis de ${s.merchant}`" @change="toggleStop(s)">
            <span class="min-w-0 flex-1">
              <strong>{{ s.merchant }}</strong> · {{ s.count }} colis
              <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ reason(s) }}</span>
              <span class="block text-xs text-gray-500">{{ [s.zone_name, s.address].filter(Boolean).join(' · ') }}</span>
            </span>
          </label>
          <ul class="mt-2 ml-6 space-y-1">
            <li v-for="o in s.orders" :key="o.id" class="flex items-center gap-2 text-sm">
              <input v-model="picked" type="checkbox" :value="o.id" :aria-label="`Colis ${o.tracking_code}`">
              <span class="font-mono text-xs">{{ o.tracking_code }}</span>
              <span class="text-gray-600 truncate">→ {{ o.delivery_zone_name || 'Client' }}</span>
              <span class="ml-auto text-xs text-gray-500 whitespace-nowrap">{{ o.delivery_date ? `livraison ${date(o.delivery_date)}` : 'dès que possible' }}</span>
            </li>
          </ul>
        </div>
        <!-- Après le ramassage : garde ou dépôt (réglage de l'entreprise, ou choix ici) -->
        <div v-if="mission.stops" class="rounded-lg bg-slate-50 p-3 text-sm">
          <template v-if="custodySetting === 'choose'">
            <p class="font-medium mb-1">Après le ramassage, le livreur…</p>
            <label class="flex items-start gap-2 py-0.5">
              <input v-model="custody" type="radio" value="depot" class="mt-1" :disabled="depotClosed">
              <span>rapporte les colis au dépôt<span v-if="depotClosed" class="text-amber-700"> (dépôt fermé depuis {{ depotClosesAt }})</span></span>
            </label>
            <label class="flex items-start gap-2 py-0.5">
              <input v-model="custody" type="radio" value="keep" class="mt-1">
              <span>garde les colis jusqu'à leur livraison, qui lui sera confiée le jour prévu</span>
            </label>
          </template>
          <p v-else-if="custodySetting === 'depot' && !depotClosed">🏢 Le livreur rapporte les colis au dépôt (réglage de l'entreprise).</p>
          <p v-else-if="custodySetting === 'depot'">🌙 Dépôt fermé depuis {{ depotClosesAt }} : le livreur gardera les colis jusqu'au matin.</p>
          <p v-else>🌙 Le livreur garde les colis jusqu'à leur livraison, qui lui sera confiée le jour prévu (réglage de l'entreprise).</p>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button type="button" class="btn-secondary" @click="mission = null">Annuler</button>
          <button type="button" class="btn-primary" :disabled="!picked.length || sending" @click="sendMission">Confier {{ picked.length }} colis</button>
        </div>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import StatCard from '../../components/StatCard.vue'
import { orderChanges } from '../../composables/useRealtime'
import { useToastStore } from '../../stores/toasts'
import { date, telLink } from '../../utils/format'

const STATES = {
  free: { label: 'Libre', class: 'bg-emerald-100 text-emerald-800' },
  soon: { label: 'Bientôt libre', class: 'bg-amber-100 text-amber-900' },
  busy: { label: 'Occupé', class: 'bg-slate-100 text-slate-700' },
  off: { label: 'Hors service', class: 'bg-slate-100 text-slate-400' },
}
// Boutiques cochées d'office dans une mission : les plus proches
const PRESELECTED_STOPS = 3

const route = useRoute()
const router = useRouter()
const toasts = useToastStore()
const couriers = ref([])
const soon = ref(2)
const loaded = ref(false)
const filter = ref('ready')
const filters = [{ value: 'ready', label: 'Libres et bientôt libres' }, { value: 'all', label: 'Tous les livreurs' }]
const mission = ref(null)
const picked = ref([])
const sending = ref(false)
// Garde ou dépôt après la mission : réglage de l'entreprise (« choose » : choix du dispatch)
const custodySetting = ref('choose')
const depotClosesAt = ref(null)
const depotClosed = ref(false)
const custody = ref('depot')

const visible = computed(() => (filter.value === 'all' ? couriers.value : couriers.value.filter((c) => ['free', 'soon'].includes(c.state))))
const counts = computed(() => ({
  free: couriers.value.filter((c) => c.state === 'free').length,
  soon: couriers.value.filter((c) => c.state === 'soon').length,
  // Libres ou presque, avec des colis à ramasser près d'eux : une mission ramassage est possible
  withPickups: couriers.value.filter((c) => ['free', 'soon'].includes(c.state) && c.nearby.count).length,
}))

const time = (iso) => (iso ? new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '')
function ago(iso) {
  const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000))
  return minutes < 1 ? 'moins d\'1 min' : `${minutes} min`
}
function missionsDetail(c) {
  const r = c.remaining
  return [r.pickup && `${r.pickup} ramassage${r.pickup > 1 ? 's' : ''}`, r.delivery && `${r.delivery} livraison${r.delivery > 1 ? 's' : ''}`, r.return && `${r.return} retour${r.return > 1 ? 's' : ''}`].filter(Boolean).join(', ')
}
function reason(s) {
  if (s.reason === 'near') return `📍 à ${String(s.km).replace('.', ',')} km`
  return s.reason === 'arrival_zone' ? 'Zone de son dernier arrêt' : 'Une de ses zones'
}

const stopIds = (s) => s.orders.map((o) => o.id)
const stopChecked = (s) => stopIds(s).every((id) => picked.value.includes(id))
function toggleStop(s) {
  const ids = stopIds(s)
  picked.value = stopChecked(s) ? picked.value.filter((id) => !ids.includes(id)) : [...new Set([...picked.value, ...ids])]
}

async function load() {
  const { data } = await http.get('/couriers/availability')
  couriers.value = data.data
  soon.value = data.meta.soon
  custodySetting.value = data.meta.custody
  depotClosesAt.value = data.meta.depot_closes_at
  depotClosed.value = data.meta.depot_closed
  loaded.value = true
}

async function openMission(c) {
  mission.value = { ...c, stops: null }
  picked.value = []
  custody.value = depotClosed.value ? 'keep' : 'depot'
  router.replace({ query: { livreur: c.id } })
  try {
    const { data } = await http.get(`/couriers/${c.id}/availability`)
    mission.value = data.data
    picked.value = data.data.stops.slice(0, PRESELECTED_STOPS).flatMap(stopIds)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    mission.value = null
  }
}

async function sendMission() {
  sending.value = true
  try {
    const { data } = await http.post(`/couriers/${mission.value.id}/pickup-mission`, { order_ids: picked.value, custody: custodySetting.value === 'choose' ? custody.value : null })
    const done = data.data.assigned.length
    const failed = Object.values(data.data.errors)
    const then = data.data.custody === 'keep' ? 'Il les garde jusqu\'à leur livraison.' : 'Il les rapporte au dépôt.'
    if (done) toasts.success(`${done} colis confié${done > 1 ? 's' : ''} à ${mission.value.name}. ${then}`)
    if (failed.length) toasts.error(`${failed.length} colis non confié${failed.length > 1 ? 's' : ''} : ${[...new Set(failed)].join(' ')}`)
    mission.value = null
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    sending.value = false
  }
}

watch(mission, (m) => { if (!m && route.query.livreur) router.replace({ query: {} }) })

// Changements de courses en direct (missions terminées, colis validés)
let timer
watch(orderChanges, () => {
  clearTimeout(timer)
  timer = setTimeout(load, 800)
})

onMounted(async () => {
  await load()
  // Arrivée depuis une alerte : la mission du livreur s'ouvre directement
  const id = Number(route.query.livreur)
  const c = couriers.value.find((x) => x.id === id)
  if (c) openMission(c)
})
</script>
