<template>
  <div class="space-y-5">
    <div>
      <p class="text-2xl font-bold">Bonjour {{ firstName }} 👋</p>
      <p class="text-slate-500 first-letter:uppercase">{{ todayLabel }}<span v-if="todayCount !== null"> · {{ todayCount ? `${todayCount} course${todayCount > 1 ? 's' : ''} aujourd'hui` : 'aucune course aujourd\'hui' }}</span></p>
    </div>

    <!-- L'action principale : créer une course -->
    <div v-if="auth.can('orders.create')" class="flex gap-2">
      <RouterLink to="/marchand/courses/nouvelle" class="tap flex-1 rounded-2xl p-4 text-white flex items-center gap-3 active:opacity-90" :style="{ backgroundColor: 'var(--app-color)' }">
        <span class="h-10 w-10 shrink-0 rounded-xl bg-white/20 grid place-items-center text-2xl leading-none">+</span>
        <span><strong class="block text-base">Nouvelle course</strong><span class="text-xs opacity-85">En quelques secondes</span></span>
      </RouterLink>
      <RouterLink to="/marchand/courses/nouvelle?coller=1" class="tap w-28 shrink-0 m-card p-3 grid place-items-center text-center text-xs font-semibold text-[var(--app-color)] active:bg-slate-50">
        <span class="text-2xl leading-none">💬</span>Coller un message WhatsApp
      </RouterLink>
    </div>

    <!-- Ce qui attend une décision de l'e-commerçant (quelle que soit la date) -->
    <section v-if="todo" class="space-y-2">
      <h2 class="text-xs font-bold uppercase tracking-wide text-slate-500">À faire maintenant</h2>
      <RouterLink
        v-if="needsPickupLocation"
        to="/marchand/lieu-de-ramassage"
        class="tap m-card p-4 flex items-center gap-3 border-l-4 border-amber-400 active:bg-slate-50"
      >
        <span class="h-10 w-10 shrink-0 rounded-xl bg-amber-50 grid place-items-center text-xl">📍</span>
        <span class="flex-1 text-sm"><strong class="block">Indiquez où récupérer vos colis</strong>En un geste, depuis votre boutique : vos ramassages seront plus rapides.</span>
        <span class="text-slate-400">›</span>
      </RouterLink>
      <RouterLink
        v-for="o in visibleAlerts"
        :key="o.id"
        :to="`/marchand/courses/${o.id}`"
        class="tap m-card p-4 flex items-center gap-3 border-l-4 border-amber-400 active:bg-slate-50"
      >
        <span class="h-10 w-10 shrink-0 rounded-xl bg-amber-50 grid place-items-center text-xl">⚠️</span>
        <span class="min-w-0 flex-1 text-sm">
          <strong class="block truncate">{{ o.last_incident?.label || o.status_label }}</strong>
          <span class="block truncate text-slate-500">{{ o.recipient.name || localPhone(o.recipient.phone) }} · {{ o.delivery.zone_name }}</span>
        </span>
        <span class="shrink-0 rounded-lg px-3 py-2 text-xs font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }">Décider</span>
      </RouterLink>
      <button v-if="alerts.length > visibleAlerts.length" type="button" class="w-full text-sm font-medium text-amber-700 py-1" @click="showAllAlerts = true">
        Voir les {{ alerts.length - visibleAlerts.length }} autres →
      </button>
    </section>

    <!-- L'argent : un seul montant, en mots simples -->
    <RouterLink v-if="balance" to="/marchand/paiements" class="tap block rounded-2xl p-5 text-white active:opacity-90" :style="{ backgroundColor: 'var(--app-color)' }">
      <p class="text-sm opacity-85">{{ balance.available < 0 ? 'Vous devez à l\'agence' : 'On vous doit' }}</p>
      <p class="text-3xl font-bold mt-0.5">{{ money(Math.abs(balance.available)) }}</p>
      <div v-if="balance.pending_cash" class="mt-3 pt-3 border-t border-white/20 flex justify-between text-sm">
        <span class="opacity-85">Encore chez les livreurs</span><strong>{{ money(balance.pending_cash) }}</strong>
      </div>
      <p class="mt-2 text-xs font-semibold opacity-90">Voir mes paiements ›</p>
    </RouterLink>

    <!-- Mes courses : période, compteurs, liste -->
    <section class="space-y-3">
      <div class="flex justify-between items-center">
        <h2 class="text-xs font-bold uppercase tracking-wide text-slate-500">Mes courses</h2>
        <RouterLink :to="{ path: '/marchand/courses', query: coursesQuery }" class="text-sm font-semibold text-[var(--app-color)]">Plus de détails</RouterLink>
      </div>

      <PeriodPicker :model-value="period" @update:model-value="setPeriod" />

      <!-- Catégories de la période : on choisit ici, la liste en dessous suit (on reste sur l'accueil) -->
      <div class="grid grid-cols-5 gap-1.5" role="radiogroup" aria-label="Catégorie">
        <button
          v-for="f in ORDER_FILTERS"
          :key="f.value"
          type="button"
          role="radio"
          :aria-checked="homeFilter === f.value"
          :class="['tap rounded-xl bg-white py-2.5 text-center ring-1 transition', homeFilter === f.value ? 'ring-2 ring-[var(--app-color)] bg-[color-mix(in_srgb,var(--app-color)_8%,white)]' : f.warn && counts[f.value] ? 'ring-amber-300' : 'ring-slate-200']"
          @click="setFilter(f.value)"
        >
          <p :class="['text-lg font-bold leading-tight', counts[f.value] ? f.color : 'text-slate-300']">{{ counts[f.value] ?? '–' }}</p>
          <p class="text-[11px] leading-tight text-slate-500">{{ f.short }}</p>
        </button>
      </div>

      <!-- Argent de la période, en une phrase -->
      <p v-if="summary && summary.amounts.collected" class="m-card px-4 py-3 text-sm text-slate-600">
        Les livreurs ont encaissé <strong class="text-slate-900">{{ money(summary.amounts.collected) }}</strong>,
        moins <strong class="text-slate-900">{{ money(fees) }}</strong> de frais :
        <strong :class="summary.amounts.net_to_merchant < 0 ? 'text-red-600' : 'text-[var(--app-color)]'">{{ money(summary.amounts.net_to_merchant) }} pour vous</strong>.
      </p>

      <OrderCard v-for="o in orders" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`" />
      <!-- Courses à livrer après aujourd'hui : elles arriveront dans « Aujourd'hui » le jour venu -->
      <button v-if="isToday && laterCount" type="button" class="tap w-full m-card px-4 py-3 flex items-center gap-3 text-sm text-left active:bg-slate-50" @click="setPeriod(laterPeriod())">
        <span class="text-xl">📅</span>
        <span class="flex-1"><strong>Prévues plus tard ({{ laterCount }})</strong><span class="block text-slate-500">Courses à livrer les prochains jours</span></span>
        <span class="text-slate-400">›</span>
      </button>
      <button v-if="hasMore" type="button" class="m-btn-secondary" :disabled="loadingMore" @click="loadOrders(page + 1)">Voir plus</button>
      <EmptyState
        v-if="loaded && !orders.length"
        icon="📦"
        :title="homeFilter !== 'all' ? 'Aucune course dans cette catégorie' : isToday ? 'Aucune course aujourd\'hui' : 'Aucune course sur cette période'"
        :text="isToday ? 'Créez une course en un instant.' : 'Choisissez d\'autres dates.'"
      >
        <RouterLink v-if="isToday && homeFilter === 'all' && auth.can('orders.create')" to="/marchand/courses/nouvelle" class="m-btn-primary">+ Nouvelle course</RouterLink>
      </EmptyState>
    </section>

    <!-- Pour aller plus loin -->
    <section class="space-y-2">
      <RouterLink to="/marchand/statistiques" class="tap m-card p-4 flex items-center gap-3 text-sm active:bg-slate-50">
        <span class="text-2xl">📊</span>
        <span class="flex-1"><strong class="block">Mes statistiques</strong><span class="text-slate-500">Taux de livraison, motifs d'échec, communes</span></span>
        <span class="text-slate-400">›</span>
      </RouterLink>
      <RouterLink
        v-if="stock.total"
        :to="stock.low ? '/marchand/stock?filtre=bas' : '/marchand/stock'"
        :class="['tap block m-card p-4 flex items-center justify-between text-sm active:bg-slate-50', stock.low ? 'ring-2 ring-amber-300' : '']"
      >
        <span>📦 Mon stock · {{ stock.total }} produit{{ stock.total > 1 ? 's' : '' }}</span>
        <span :class="stock.low ? 'font-semibold text-amber-700' : 'text-slate-500'">{{ stock.low ? `⚠️ ${stock.low} en stock bas` : 'Voir' }} →</span>
      </RouterLink>
    </section>

    <!-- Installation et notifications : proposées une fois l'application utilisée -->
    <template v-if="month && month.counts.total">
      <InstallBanner app="marchand" />
      <PushInvite />
    </template>

  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cachedGet } from '../../composables/useCachedApi'
import OrderCard from '../../components/mobile/OrderCard.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import PushInvite from '../../components/mobile/PushInvite.vue'
import PeriodPicker from '../../components/mobile/PeriodPicker.vue'
import { useAuthStore } from '../../stores/auth'
import { orderChanges } from '../../composables/useRealtime'
import { localPhone, money, today as todayDate } from '../../utils/format'
import { ORDER_FILTERS, filterParams } from '../../utils/orderFilters'
import { periodFromQuery, periodQuery } from '../../utils/periods'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const firstName = computed(() => (auth.user?.name || '').split(' ')[0])
const todayLabel = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })

// Période affichée : aujourd'hui par défaut, ou celle de l'adresse (?periode=…) au retour d'une fiche
const period = ref(periodFromQuery(route.query, 'today'))
const isToday = computed(() => period.value.key === 'today')
// Catégorie choisie sur l'accueil : la liste en dessous la suit, sans quitter la page
const homeFilter = ref('all')
const counts = reactive({})

const summary = ref(null)
const month = ref(null)
const alerts = ref([])
const alertsTotal = ref(0)
const orders = ref([])
const page = ref(1)
const total = ref(0)
const hasMore = ref(false)
const loaded = ref(false)
const loadingMore = ref(false)
// Les alertes ne doivent pas repousser le point du jour hors de l'écran
const showAllAlerts = ref(false)
const visibleAlerts = computed(() => (showAllAlerts.value ? alerts.value : alerts.value.slice(0, 2)))
// Ce que l'agence doit à l'e-commerçant (Mon argent), si son compte voit l'argent
const balance = ref(null)
const todayCount = ref(null)

const needsPickupLocation = computed(() => !!auth.user?.merchant && !auth.user.merchant.has_pickup_location)
const todo = computed(() => needsPickupLocation.value || alerts.value.length > 0)
const fees = computed(() => {
  const a = summary.value?.amounts || {}
  return (a.fees || 0) + (a.shipping_fees || 0) + (a.other_fees || 0)
})

// « Plus de détails » : la liste des courses, avec la même catégorie et les mêmes dates
const coursesQuery = computed(() => ({
  ...(homeFilter.value !== 'all' ? { filtre: homeFilter.value } : {}),
  ...periodQuery(period.value, 'today'),
}))

function setFilter(value) {
  homeFilter.value = value
  loadOrders(1)
}

function setPeriod(p) {
  period.value = p
  router.replace({ query: periodQuery(p, 'today') })
  load()
}

async function loadCounts() {
  const results = await Promise.all(ORDER_FILTERS.map((f) =>
    cachedGet('/orders', { params: { per_page: 1, ...filterParams(f, period.value) } }).catch(() => null)))
  ORDER_FILTERS.forEach((f, i) => {
    if (results[i]) counts[f.value] = results[i].data.meta?.total ?? results[i].data.data.length
  })
}

// Courses à livrer après aujourd'hui (bandeau sous la liste du jour)
const laterCount = ref(0)
const laterPeriod = () => periodFromQuery({ periode: 'later' })
async function loadLater() {
  try {
    const { data } = await cachedGet('/orders', { params: { per_page: 1, ...filterParams(ORDER_FILTERS[0], laterPeriod()) } })
    laterCount.value = data.meta?.total ?? data.data.length
  } catch {
    laterCount.value = 0
  }
}

async function loadOrders(p = 1) {
  loadingMore.value = p > 1
  const filter = ORDER_FILTERS.find((f) => f.value === homeFilter.value)
  try {
    const { data } = await cachedGet('/orders', { params: { ...filterParams(filter, period.value), per_page: 10, page: p } })
    orders.value = p === 1 ? data.data : [...orders.value, ...data.data]
    page.value = p
    total.value = data.meta?.total ?? orders.value.length
    hasMore.value = data.meta ? data.meta.current_page < data.meta.last_page : false
  } finally {
    loadingMore.value = false
  }
}

async function load() {
  const d = todayDate()
  try {
    const [s, m, a] = await Promise.all([
      cachedGet('/reports/summary', { params: { from: period.value.from || undefined, to: period.value.to || undefined, date_basis: 'delivery' } }),
      cachedGet('/reports/summary', { params: { from: `${d.slice(0, 8)}01`, to: d, date_basis: 'delivery' } }),
      cachedGet('/orders', { params: { queue: 'to_decide', per_page: 10 } }),
      loadOrders(1),
      loadCounts(),
      loadLater(),
    ])
    summary.value = s.data.data
    month.value = m.data.data
    alerts.value = a.data.data
    alertsTotal.value = a.data.meta?.total ?? a.data.data.length
    if (isToday.value) todayCount.value = s.data.data.counts.total
  } catch {
    // Hors ligne sans données en cache : l'accueil reste vide, le bandeau hors ligne s'affiche
  } finally {
    loaded.value = true
  }
}

watch(orderChanges, () => {
  load()
  loadBalance()
})
// Résumé du stock (seulement si le marchand a des produits)
const stock = reactive({ total: 0, low: 0 })
async function loadStock() {
  // Stock : seulement si la formule de l'entreprise l'inclut
  if (!auth.hasFeature('stock')) return
  try {
    const [all, low] = await Promise.all([
      cachedGet('/products', { params: { per_page: 1 } }),
      cachedGet('/products', { params: { per_page: 1, low: 1 } }),
    ])
    stock.total = all.data.meta?.total || 0
    stock.low = low.data.meta?.total || 0
  } catch {
    // Facultatif : l'accueil reste utilisable sans le stock
  }
}

async function loadBalance() {
  if (!auth.can('finance.view') || !auth.user?.merchant_id) return
  try {
    balance.value = (await cachedGet(`/finance/merchants/${auth.user.merchant_id}/ledger`, { params: { per_page: 1 } })).data.summary
  } catch {
    // Facultatif : l'accueil reste utilisable sans le solde
  }
}

onMounted(() => {
  load()
  loadStock()
  loadBalance()
})
</script>
