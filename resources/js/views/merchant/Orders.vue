<template>
  <div class="space-y-3">
    <!-- Recherche et filtres restent en haut pendant le défilement -->
    <div class="sticky z-20 -mx-4 -mt-4 space-y-2 bg-slate-50/95 px-4 pt-3 pb-2 backdrop-blur lg:-mx-8 lg:-mt-8 lg:px-8 lg:pt-6" style="top: calc(3.5rem + env(safe-area-inset-top))">
      <div class="relative">
        <input v-model="search" type="search" class="m-input pl-11" placeholder="Nom, téléphone ou n° de course" aria-label="Rechercher une course" @input="debouncedLoad">
        <StatusIcon name="search" class="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-slate-400" />
      </div>

      <!-- Période (aujourd'hui par défaut, comme sur l'accueil) et activité : deux menus sur une ligne -->
      <div class="flex gap-2">
        <PeriodPicker menu class="min-w-0 flex-1" :model-value="period" @update:model-value="setPeriod" />
        <ActivitySwitch v-if="space.activities.length > 1" menu class="min-w-0 flex-1" @change="reload" />
        <!-- Étiquettes : choisir les courses à imprimer -->
        <button v-if="auth.can('orders.create') && auth.user?.merchant?.label_settings?.client_print !== 'no'" type="button" :class="['tap shrink-0 rounded-xl px-3 text-sm font-semibold ring-1', printMode ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']" :style="printMode ? { backgroundColor: 'var(--app-color)' } : {}" :aria-pressed="printMode" @click="togglePrint">🏷️ Étiquettes</button>
      </div>

      <!-- Catégories, avec le nombre de courses de chacune (sur la période) -->
      <div class="flex gap-2 overflow-x-auto no-scrollbar -mx-4 px-4 lg:mx-0 lg:px-0" role="radiogroup" aria-label="Catégorie">
        <button
          v-for="f in filters.filter((x) => !x.hideEmpty || counts[x.value] || filter === x.value)"
          :key="f.value"
          type="button"
          role="radio"
          :aria-checked="filter === f.value"
          :class="['tap shrink-0 rounded-full px-4 py-2 text-sm font-medium ring-1', filter === f.value ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']"
          :style="filter === f.value ? { backgroundColor: 'var(--app-color)' } : {}"
          @click="setFilter(f.value)"
        >
          {{ f.label }}
          <span
            v-if="counts[f.value] !== undefined"
            :class="['ml-1 rounded-full px-1.5 text-xs', filter === f.value ? 'bg-white/25' : f.warn && counts[f.value] ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600']"
          >{{ counts[f.value] }}</span>
        </button>
      </div>

      <!-- Client à départements : courses envoyées ou reçues par l'un d'eux -->
      <div v-if="departments.senders.value.length > 1" class="flex gap-2 overflow-x-auto no-scrollbar -mx-4 px-4 lg:mx-0 lg:px-0" role="radiogroup" aria-label="Département">
        <button
          v-for="d in [{ id: null, label: 'Tous les départements' }, ...departments.senders.value.map((x) => ({ id: x.id, label: x.code || x.name }))]" :key="d.id ?? 'all'"
          type="button" role="radio" :aria-checked="department === d.id"
          :class="['tap shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold ring-1', department === d.id ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-200 text-slate-700']"
          @click="department = d.id; reload()"
        >
          🏢 {{ d.label }}
        </button>
      </div>
    </div>

    <!-- Courses de la période choisie -->
    <SkeletonList v-if="loading && !orders.length" />
    <!-- Groupées par jour de livraison quand la période en couvre plusieurs -->
    <section v-for="g in groups" :key="g.day" class="space-y-2">
      <h2 v-if="groups.length > 1" class="px-1 pt-1 text-xs font-bold uppercase tracking-wide text-slate-600">{{ g.label }}</h2>
      <!-- Mode étiquettes : on coche les courses à imprimer -->
      <template v-if="printMode">
        <label v-for="o in g.orders" :key="o.id" :class="['m-card flex items-center gap-3 p-4 cursor-pointer', printable(o) ? '' : 'opacity-50']">
          <input v-model="picked" type="checkbox" :value="o.id" :disabled="!printable(o)" class="h-5 w-5 shrink-0">
          <span class="min-w-0 flex-1">
            <span class="block font-semibold truncate">{{ o.recipient.name || o.recipient.phone }}</span>
            <span class="block text-sm text-slate-600 truncate">{{ o.delivery.zone_name }}<template v-if="o.department"> · 🏢 {{ o.department.code || o.department.name }}</template></span>
          </span>
          <span class="text-right">
            <span class="block font-mono text-xs text-slate-500">{{ o.tracking_code }}</span>
            <span v-if="o.label?.printed_at" class="text-xs text-emerald-700">✓ imprimée</span>
          </span>
        </label>
      </template>
      <OrderCard v-for="o in (printMode ? [] : g.orders)" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`">
        <template v-if="needsDecision(o)" #actions>
          <a :href="telLink(o.recipient.phone)" class="tap flex-1 rounded-xl py-2.5 text-center text-sm font-semibold ring-1 ring-slate-200 active:bg-slate-50 inline-flex items-center justify-center gap-1.5"><StatusIcon name="phone" class="h-4 w-4" />Appeler</a>
          <RouterLink v-if="o.status === 'delivery_failed'" :to="`/marchand/courses/${o.id}?action=reporter`" class="tap flex-1 rounded-xl py-2.5 text-center text-sm font-semibold ring-1 ring-slate-200 active:bg-slate-50 inline-flex items-center justify-center gap-1.5"><StatusIcon name="calendar" class="h-4 w-4" />Reporter</RouterLink>
          <RouterLink :to="`/marchand/courses/${o.id}`" class="tap flex-1 rounded-xl py-2.5 text-center text-sm font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }">Décider</RouterLink>
        </template>
      </OrderCard>
    </section>

    <EmptyState v-if="!loading && !orders.length" icon="search" :title="search ? 'Aucune course trouvée' : emptyTitle" :text="search ? 'Essayez un autre nom, numéro ou n° de course.' : emptyText">
      <RouterLink v-if="!search && filter === 'all' && auth.can('orders.create')" to="/marchand/courses/nouvelle" class="m-btn-primary">+ Nouvelle course</RouterLink>
    </EmptyState>
    <p v-if="loading && orders.length" class="text-center text-slate-500 py-6">Chargement…</p>
    <button v-if="hasMore && !loading" class="m-btn-secondary" @click="load(page + 1)">Voir plus</button>

    <!-- Barre d'impression : tout, à ramasser, imprimer -->
    <div v-if="printMode" class="sticky bottom-20 z-20 m-card flex flex-wrap items-center gap-2 p-3 shadow-lg lg:bottom-4">
      <button type="button" class="tap rounded-xl px-3 py-2 text-sm font-semibold ring-1 ring-slate-200" @click="pickAll">Tout cocher</button>
      <button type="button" class="tap rounded-xl px-3 py-2 text-sm font-semibold ring-1 ring-slate-200" @click="pickToCollect">À ramasser</button>
      <button type="button" class="tap rounded-xl px-3 py-2 text-sm font-semibold ring-1 ring-slate-200" @click="pickUnprinted">Pas imprimées</button>
      <button v-if="picked.length" type="button" class="tap rounded-xl px-3 py-2 text-sm text-slate-600" @click="picked = []">Aucune</button>
      <RouterLink :to="`/etiquettes?ids=${picked.join(',')}`" :class="['tap ml-auto rounded-xl px-4 py-2 text-sm font-semibold text-white', picked.length ? '' : 'pointer-events-none opacity-50']" :style="{ backgroundColor: 'var(--app-color)' }">
        🖨️ Imprimer {{ picked.length || '' }}
      </RouterLink>
    </div>

  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cachedGet } from '../../composables/useCachedApi'
import OrderCard from '../../components/mobile/OrderCard.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import SkeletonList from '../../components/mobile/SkeletonList.vue'
import StatusIcon from '../../components/mobile/StatusIcon.vue'
import { orderChanges } from '../../composables/useRealtime'
import { useAuthStore } from '../../stores/auth'
import { telLink, today } from '../../utils/format'
import { needsDecision } from '../../utils/merchantStatus'
import PeriodPicker from '../../components/mobile/PeriodPicker.vue'
import ActivitySwitch from '../../components/client/ActivitySwitch.vue'
import { useClientSpaceStore } from '../../stores/clientSpace'
import { ORDER_FILTERS, filterParams } from '../../utils/orderFilters'
import { useDepartments } from '../../composables/useDepartments'
import { periodFromQuery, periodQuery } from '../../utils/periods'

const auth = useAuthStore()
const space = useClientSpaceStore()

// Étiquettes : courses cochées (annulées et refusées exclues), « À ramasser » : pas encore récupérées
const printMode = ref(false)
const picked = ref([])
const TO_COLLECT = ['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress']
const printable = (o) => !['cancelled', 'rejected'].includes(o.status)
function togglePrint() {
  printMode.value = !printMode.value
  picked.value = []
}
const pickAll = () => { picked.value = orders.value.filter(printable).map((o) => o.id) }
const pickUnprinted = () => { picked.value = orders.value.filter((o) => printable(o) && !o.label?.printed_at).map((o) => o.id) }
const pickToCollect = () => { picked.value = orders.value.filter((o) => TO_COLLECT.includes(o.status)).map((o) => o.id) }

// En cours, reports compris (décision prise, nouvelle livraison prévue)
const filters = ORDER_FILTERS

const EMPTY = {
  all: ['Aucune course sur ces dates', 'Choisissez d\'autres dates.'],
  active: ['Aucune course en cours', 'Toutes vos courses sont terminées.'],
  problems: ['Rien à régler', 'Aucun colis n\'attend votre décision. 👍'],
  delivered: ['Aucune course livrée', 'Les courses livrées apparaîtront ici.'],
  other: ['Aucune course retournée ou annulée', ''],
}

const route = useRoute()
const router = useRouter()
// Anciennes adresses (?filtre=active…) toujours valables
const filter = ref(filters.some((f) => f.value === route.query.filtre) ? route.query.filtre : 'all')
const period = ref(periodFromQuery(route.query, 'today'))
const search = ref('')
const orders = ref([])
const counts = reactive({})
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)
// Département choisi (client à départements)
const department = ref(null)
const departments = useDepartments(computed(() => auth.user?.merchant_id), { mine: true })

// Jour de livraison d'une course : date prévue, sinon jour de création
function dayOf(o) {
  if (o.delivery?.scheduled_date) return o.delivery.scheduled_date
  const d = new Date(o.created_at)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function shiftDay(iso, days) {
  const d = new Date(`${iso}T12:00:00`)
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}
function dayLabel(day) {
  const now = today()
  if (day === now) return 'Aujourd\'hui'
  if (day === shiftDay(now, -1)) return 'Hier'
  if (day === shiftDay(now, 1)) return 'Demain'
  return new Date(`${day}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
}
const groups = computed(() => {
  const list = []
  for (const o of orders.value) {
    const day = dayOf(o)
    let g = list.find((x) => x.day === day)
    if (!g) list.push(g = { day, label: dayLabel(day), orders: [] })
    g.orders.push(o)
  }
  return list
})

const emptyTitle = computed(() => EMPTY[filter.value][0])
const emptyText = computed(() => EMPTY[filter.value][1])

async function load(p = 1) {
  loading.value = true
  const current = filters.find((f) => f.value === filter.value)
  try {
    const { data } = await cachedGet('/orders', {
      // Une recherche porte sur toutes les dates : on retrouve une course sans connaître son jour
      params: { page: p, per_page: 20, ...filterParams(current, search.value ? null : period.value), search: search.value || undefined, activity: space.current || undefined, department_id: department.value || undefined },
    })
    orders.value = p === 1 ? data.data : [...orders.value, ...data.data]
    page.value = p
    hasMore.value = data.meta.current_page < data.meta.last_page
  } catch {
    // Hors ligne sans cache pour ce filtre : liste inchangée
  } finally {
    loading.value = false
  }
}

// Nombre de courses de chaque catégorie (une page d'une ligne suffit : le total est dans « meta »)
async function loadCounts() {
  await Promise.all(filters.map(async (f) => {
    try {
      const { data } = await cachedGet('/orders', { params: { per_page: 1, ...filterParams(f, period.value), activity: space.current || undefined, department_id: department.value || undefined } })
      counts[f.value] = data.meta?.total ?? 0
    } catch {
      // Compteur facultatif
    }
  }))
}

// Catégorie et période gardées dans l'adresse (retour depuis une fiche)
function syncQuery() {
  router.replace({ query: { ...(filter.value === 'all' ? {} : { filtre: filter.value }), ...periodQuery(period.value, 'today') } })
}

// Autre activité choisie : liste et compteurs recalculés
function reload() {
  load()
  loadCounts()
}

function setFilter(value) {
  filter.value = value
  syncQuery()
  load()
}

function setPeriod(value) {
  period.value = value
  syncQuery()
  load()
  loadCounts()
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(() => load(), 300)
}

watch(orderChanges, () => {
  load()
  loadCounts()
})
onMounted(() => {
  load()
  loadCounts()
})
</script>
