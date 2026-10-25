<template>
  <div class="space-y-5 pb-14 lg:pb-0">
    <div>
      <p class="text-2xl font-bold lg:text-3xl">Bonjour {{ firstName }} 👋</p>
      <p class="text-slate-600 first-letter:uppercase">{{ todayLabel }}<span v-if="subtitle"> · {{ subtitle }}</span></p>
    </div>

    <ActivitySwitch @change="reload" />

    <!-- Mobile : à faire, puis les courses, puis le compte. Ordinateur : le compte en haut, les courses à côté du « À faire » -->
    <div class="flex flex-col gap-5 lg:grid lg:grid-cols-3 lg:gap-6">
      <!-- Ce qui attend le client : décisions, lieu de ramassage, facture, stock bas -->
      <section v-if="todoCount" class="space-y-2 lg:col-start-3 lg:row-start-2 lg:self-start">
        <h2 class="px-1 text-base font-bold text-slate-800">À faire</h2>
        <div class="m-card divide-y divide-slate-100 overflow-hidden ring-amber-200">
          <RouterLink v-if="needsPickupLocation" to="/marchand/lieu-de-ramassage" class="tap flex items-center gap-3 p-4 active:bg-slate-50">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-700"><StatusIcon name="pin" class="h-5 w-5" /></span>
            <span class="min-w-0 flex-1"><strong class="block">Indiquez où récupérer vos colis</strong><span class="text-sm text-slate-600">En un geste, depuis votre boutique</span></span>
            <StatusIcon name="right" class="h-5 w-5 shrink-0 text-slate-400" />
          </RouterLink>
          <RouterLink v-for="o in visibleAlerts" :key="o.id" :to="`/marchand/courses/${o.id}`" class="tap flex items-center gap-3 p-4 active:bg-slate-50">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-700"><StatusIcon name="alert" class="h-5 w-5" /></span>
            <span class="min-w-0 flex-1">
              <strong class="block truncate">{{ o.last_incident?.label || o.status_label }}</strong>
              <span class="block truncate text-sm text-slate-600">{{ o.recipient.name || localPhone(o.recipient.phone) }} · {{ o.delivery.zone_name }}</span>
            </span>
            <span class="shrink-0 rounded-lg px-3 py-2 text-sm font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }">Décider</span>
          </RouterLink>
          <button v-if="alerts.length > visibleAlerts.length" type="button" class="w-full py-3 text-sm font-semibold text-amber-800" @click="showAllAlerts = true">
            Voir les {{ alerts.length - visibleAlerts.length }} autres
          </button>
          <RouterLink v-if="invoiceDue" to="/marchand/factures" class="tap flex items-center gap-3 p-4 active:bg-slate-50">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-red-50 text-red-700"><StatusIcon name="file" class="h-5 w-5" /></span>
            <span class="min-w-0 flex-1">
              <strong class="block">{{ invoices.overdue ? 'Facture en retard' : 'Facture à payer' }}</strong>
              <span :class="['text-sm', invoices.overdue ? 'font-medium text-red-700' : 'text-slate-600']">
                {{ money(invoices.to_pay) }}<template v-if="invoices.next_due"> · {{ invoices.overdue ? 'à payer depuis le' : 'avant le' }} {{ date(invoices.next_due) }}</template>
              </span>
            </span>
            <StatusIcon name="right" class="h-5 w-5 shrink-0 text-slate-400" />
          </RouterLink>
          <!-- Notation des livreurs : courses récentes, livreur attitré de la période -->
          <RouterLink v-if="toRate" to="/marchand/a-noter" class="tap flex items-center gap-3 p-4 active:bg-slate-50">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-700"><StatusIcon name="user" class="h-5 w-5" /></span>
            <span class="min-w-0 flex-1"><strong class="block">{{ toRateText }}</strong><span class="text-sm text-slate-600">Votre avis en quelques secondes</span></span>
            <StatusIcon name="right" class="h-5 w-5 shrink-0 text-slate-400" />
          </RouterLink>
          <RouterLink v-if="stock.low" to="/marchand/stock?filtre=bas" class="tap flex items-center gap-3 p-4 active:bg-slate-50">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-700"><StatusIcon name="box" class="h-5 w-5" /></span>
            <span class="min-w-0 flex-1"><strong class="block">{{ stock.low }} produit{{ stock.low > 1 ? 's' : '' }} en stock bas</strong><span class="text-sm text-slate-600">À réapprovisionner</span></span>
            <StatusIcon name="right" class="h-5 w-5 shrink-0 text-slate-400" />
          </RouterLink>
        </div>
      </section>

      <!-- Mes courses : période, compteurs, liste -->
      <section :class="['space-y-3 lg:row-start-2 lg:col-start-1', todoCount ? 'lg:col-span-2' : 'lg:col-span-3']">
        <div class="flex items-center justify-between px-1">
          <h2 class="text-base font-bold text-slate-800">{{ isToday ? 'Mes courses du jour' : 'Mes courses' }}</h2>
          <RouterLink :to="{ path: '/marchand/courses', query: coursesQuery }" class="text-sm font-semibold text-[var(--app-color)]">Tout voir</RouterLink>
        </div>

        <PeriodPicker menu :model-value="period" @update:model-value="setPeriod" />

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
            <p class="text-xs leading-tight text-slate-600">{{ f.short }}</p>
          </button>
        </div>

        <!-- Argent de la période, en une phrase -->
        <p v-if="summary && summary.amounts.collected && space.showsEcommerce" class="m-card px-4 py-3 text-sm text-slate-600">
          Les livreurs ont encaissé <strong class="text-slate-900">{{ money(summary.amounts.collected) }}</strong>,
          moins <strong class="text-slate-900">{{ money(fees) }}</strong> de frais :
          <strong :class="summary.amounts.net_to_merchant < 0 ? 'text-red-600' : 'text-[var(--app-color)]'">{{ money(summary.amounts.net_to_merchant) }} pour vous</strong>.
        </p>

        <SkeletonList v-if="!loaded" :rows="3" />
        <OrderCard v-for="o in orders" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`" />
        <!-- Courses à livrer après aujourd'hui : elles arriveront dans « Aujourd'hui » le jour venu -->
        <button v-if="isToday && laterCount" type="button" class="tap w-full m-card px-4 py-3 flex items-center gap-3 text-sm text-left active:bg-slate-50" @click="setPeriod(laterPeriod())">
          <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-700"><StatusIcon name="calendar" class="h-5 w-5" /></span>
          <span class="flex-1"><strong>Prévues plus tard ({{ laterCount }})</strong><span class="block text-slate-600">Courses à livrer les prochains jours</span></span>
          <StatusIcon name="right" class="h-5 w-5 text-slate-400" />
        </button>
        <button v-if="hasMore" type="button" class="m-btn-secondary" :disabled="loadingMore" @click="loadOrders(page + 1)">Voir plus</button>
        <EmptyState
          v-if="loaded && !orders.length"
          icon="package"
          :title="homeFilter !== 'all' ? 'Aucune course dans cette catégorie' : isToday ? 'Aucune course aujourd\'hui' : 'Aucune course sur cette période'"
          :text="isToday ? 'Créez une course en un instant.' : 'Choisissez d\'autres dates.'"
        >
          <RouterLink v-if="isToday && homeFilter === 'all' && auth.can('orders.create')" to="/marchand/courses/nouvelle" class="m-btn-primary">+ Nouvelle course</RouterLink>
        </EmptyState>
      </section>

      <!-- Mon compte : argent, facture, forfait, lots, livreurs MAD, statistiques, stock -->
      <section class="space-y-2 lg:col-span-3 lg:row-start-1">
        <h2 class="px-1 text-base font-bold text-slate-800 lg:sr-only">Mon compte</h2>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <template v-if="!loaded">
            <div v-for="i in 4" :key="`sk-${i}`" class="m-card space-y-2 p-3.5"><span class="skeleton block h-9 w-9 rounded-lg" /><span class="skeleton block h-3 w-20" /><span class="skeleton block h-5 w-24" /></div>
          </template>
          <AccountTile
            v-if="balance && space.showsEcommerce"
            to="/marchand/paiements" icon="wallet" :tone="balance.available < 0 ? 'red' : 'green'"
            :label="balance.available < 0 ? 'Vous devez à l\'agence' : 'On vous doit'"
            :value="money(Math.abs(balance.available))"
            :sub="balance.pending_cash ? `+ ${money(balance.pending_cash)} chez les livreurs` : 'Voir mes paiements'"
          />
          <AccountTile
            v-if="space.showsBilled && space.space && auth.can('finance.view')"
            to="/marchand/factures" icon="file" :tone="invoices.to_pay ? 'red' : 'slate'"
            :label="invoices.to_pay ? (invoices.overdue ? 'Facture en retard' : 'Facture à payer') : 'Mes factures'"
            :value="invoices.to_pay ? money(invoices.to_pay) : 'À jour'"
            :sub="invoices.to_pay && invoices.next_due ? `${invoices.overdue ? 'depuis le' : 'avant le'} ${date(invoices.next_due)}` : 'aucune facture à payer'"
            :sub-class="invoices.overdue ? 'font-medium text-red-700' : ''"
          />
          <AccountTile
            v-for="p in (space.showsBilled ? space.plans : [])" :key="`plan-${p.id}`"
            icon="box" :tone="p.has_forfait && p.percent >= 80 ? 'amber' : 'violet'"
            :label="p.has_forfait ? `Forfait ${p.activity === 'mad' ? 'MAD' : p.activity_label.toLowerCase()}` : p.formula_label"
            :value="p.has_forfait ? `${p.used} / ${p.included}` : `${p.orders} envoi${p.orders > 1 ? 's' : ''}`"
            :sub="p.has_forfait ? (p.over ? `${p.over} en plus du forfait` : `${p.unit_label} ce mois-ci`) : 'ce mois-ci'"
            :percent="p.has_forfait ? p.percent : null"
            @click="sheet = { type: 'plan', plan: p }"
          />
          <AccountTile
            v-for="l in lots" :key="`lot-${l.id}`"
            icon="package" tone="blue" :label="`Lot · ${l.lot_name}`"
            :value="`${l.delivered} / ${l.lot_quantity}`" :sub="l.done ? 'entièrement distribué' : 'colis livrés'"
            :percent="Math.round(100 * l.delivered / Math.max(1, l.lot_quantity))"
            @click="sheet = { type: 'lot', lot: l }"
          />
          <AccountTile
            v-if="couriers.length"
            icon="phone" tone="violet" :label="couriers.length > 1 ? 'Mes livreurs MAD' : 'Mon livreur MAD'"
            :value="couriers.length > 1 ? `${couriers.length} livreurs` : couriers[0].name" :sub="couriers.length > 1 ? 'les appeler' : couriers[0].status_label"
            @click="sheet = { type: 'couriers' }"
          />
          <AccountTile v-if="loaded" to="/marchand/statistiques" icon="chart" tone="blue" label="Statistiques" :value="month ? `${month.counts.total} course${month.counts.total > 1 ? 's' : ''}` : 'Voir'" sub="ce mois-ci" />
          <AccountTile
            v-if="stock.total" :to="stock.low ? '/marchand/stock?filtre=bas' : '/marchand/stock'" icon="box" :tone="stock.low ? 'amber' : 'slate'"
            label="Mon stock" :value="`${stock.total} produit${stock.total > 1 ? 's' : ''}`" :sub="stock.low ? `${stock.low} en stock bas` : 'voir le stock'"
            :sub-class="stock.low ? 'font-medium text-amber-800' : ''"
          />
        </div>
      </section>
    </div>

    <!-- Installation et notifications : proposées une fois l'application utilisée -->
    <template v-if="month && month.counts.total">
      <InstallBanner app="marchand" />
      <PushInvite />
    </template>

    <!-- Détail d'une tuile -->
    <BottomSheet :open="!!sheet" :title="sheetTitle" @close="sheet = null">
      <ForfaitCard v-if="sheet?.type === 'plan'" :plan="sheet.plan" />
      <div v-else-if="sheet?.type === 'lot'" class="m-card p-4 space-y-2">
        <div class="flex justify-between text-sm">
          <span><strong class="text-lg">{{ sheet.lot.delivered }}</strong> livré(s) sur {{ sheet.lot.lot_quantity }}</span>
          <span class="text-slate-600">{{ sheet.lot.in_progress }} en cours<template v-if="sheet.lot.remaining"> · {{ sheet.lot.remaining }} à envoyer</template></span>
        </div>
        <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
          <div class="h-full rounded-full bg-[var(--app-color)]" :style="{ width: `${Math.min(100, Math.round(100 * sheet.lot.delivered / Math.max(1, sheet.lot.lot_quantity)))}%` }" />
        </div>
        <p v-if="sheet.lot.lot_due_on" class="text-sm text-slate-600">À distribuer avant le {{ date(sheet.lot.lot_due_on) }}</p>
        <p v-if="sheet.lot.over" class="text-sm text-slate-600">{{ sheet.lot.over }} colis en plus du lot, facturés à l'unité.</p>
        <p v-if="sheet.lot.done" class="text-sm font-medium text-emerald-700">Lot entièrement distribué</p>
      </div>
      <MadCouriers v-else-if="sheet?.type === 'couriers'" :couriers="couriers" />
    </BottomSheet>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cachedGet } from '../../composables/useCachedApi'
import OrderCard from '../../components/mobile/OrderCard.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import SkeletonList from '../../components/mobile/SkeletonList.vue'
import StatusIcon from '../../components/mobile/StatusIcon.vue'
import AccountTile from '../../components/client/AccountTile.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import PushInvite from '../../components/mobile/PushInvite.vue'
import PeriodPicker from '../../components/mobile/PeriodPicker.vue'
import ActivitySwitch from '../../components/client/ActivitySwitch.vue'
import ForfaitCard from '../../components/client/ForfaitCard.vue'
import MadCouriers from '../../components/client/MadCouriers.vue'
import { useClientSpaceStore } from '../../stores/clientSpace'
import { useAuthStore } from '../../stores/auth'
import { orderChanges } from '../../composables/useRealtime'
import { date, localPhone, money, today as todayDate } from '../../utils/format'
import { ORDER_FILTERS, filterParams } from '../../utils/orderFilters'
import { periodFromQuery, periodQuery } from '../../utils/periods'

const auth = useAuthStore()
const space = useClientSpaceStore()
// Activité regardée (sélecteur en haut) : toutes les requêtes la suivent
const scoped = (params = {}) => ({ ...params, activity: space.current || undefined })
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
// Ce que l'agence doit au client (Mon argent), si son compte voit l'argent
const balance = ref(null)
const todayCount = ref(null)

// Résumé du stock (seulement si le client a des produits)
const stock = reactive({ total: 0, low: 0 })
const needsPickupLocation = computed(() => !!auth.user?.merchant && !auth.user.merchant.has_pickup_location)
// Facture à payer (distribution, MAD), lots en cours et livreurs attitrés de l'activité regardée
const invoices = computed(() => space.space?.invoices || {})
const invoiceDue = computed(() => space.showsBilled && auth.can('finance.view') && !!invoices.value.to_pay)
const lots = computed(() => (space.showsBilled && space.current !== 'mad' ? space.space?.lots || [] : []))
const couriers = computed(() => (space.showsBilled && space.current !== 'distribution' ? space.space?.couriers || [] : []))
// Livreurs à noter : courses récentes et livreurs attitrés (MAD) pas encore notés sur la période
const rating = reactive({ orders: 0, mad: [] })
const toRate = computed(() => rating.orders + rating.mad.length)
const toRateText = computed(() => {
  if (rating.mad.length && !rating.orders) return `Notez ${rating.mad.map((m) => m.name).join(', ')} (${rating.mad[0].period})`
  return `${rating.orders} course${rating.orders > 1 ? 's' : ''} à noter${rating.mad.length ? ' et votre livreur attitré' : ''}`
})
async function loadRatings() {
  try {
    const { data } = await cachedGet('/merchant/ratings/pending')
    rating.orders = data.data.length
    rating.mad = (data.mad || []).filter((m) => !m.rating)
  } catch {
    // Notation facultative
  }
}
const todoCount = computed(() => (needsPickupLocation.value ? 1 : 0) + alertsTotal.value + (invoiceDue.value ? 1 : 0) + (stock.low ? 1 : 0) + (toRate.value ? 1 : 0))
// Sous la salutation : ce qui demande l'attention, sinon le nombre de courses du jour
const subtitle = computed(() => {
  if (todoCount.value) return `${todoCount.value} chose${todoCount.value > 1 ? 's' : ''} demande${todoCount.value > 1 ? 'nt' : ''} votre attention`
  if (todayCount.value === null) return ''
  return todayCount.value ? `${todayCount.value} course${todayCount.value > 1 ? 's' : ''} aujourd'hui` : 'aucune course aujourd\'hui'
})
const sheet = ref(null)
const sheetTitle = computed(() => {
  if (sheet.value?.type === 'plan') return `${sheet.value.plan.formula_label} · ${sheet.value.plan.month_label}`
  if (sheet.value?.type === 'lot') return `Lot · ${sheet.value.lot.lot_name}`
  return couriers.value.length > 1 ? 'Mes livreurs MAD' : 'Mon livreur MAD'
})
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
    cachedGet('/orders', { params: scoped({ per_page: 1, ...filterParams(f, period.value) }) }).catch(() => null)))
  ORDER_FILTERS.forEach((f, i) => {
    if (results[i]) counts[f.value] = results[i].data.meta?.total ?? results[i].data.data.length
  })
}

// Courses à livrer après aujourd'hui (bandeau sous la liste du jour)
const laterCount = ref(0)
const laterPeriod = () => periodFromQuery({ periode: 'later' })
async function loadLater() {
  try {
    const { data } = await cachedGet('/orders', { params: scoped({ per_page: 1, ...filterParams(ORDER_FILTERS[0], laterPeriod()) }) })
    laterCount.value = data.meta?.total ?? data.data.length
  } catch {
    laterCount.value = 0
  }
}

async function loadOrders(p = 1) {
  loadingMore.value = p > 1
  const filter = ORDER_FILTERS.find((f) => f.value === homeFilter.value)
  try {
    const { data } = await cachedGet('/orders', { params: scoped({ ...filterParams(filter, period.value), per_page: 10, page: p }) })
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
      cachedGet('/reports/summary', { params: scoped({ from: period.value.from || undefined, to: period.value.to || undefined, date_basis: 'delivery' }) }),
      cachedGet('/reports/summary', { params: scoped({ from: `${d.slice(0, 8)}01`, to: d, date_basis: 'delivery' }) }),
      cachedGet('/orders', { params: scoped({ queue: 'to_decide', per_page: 10 }) }),
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
  space.load()
})

// Autre activité choisie : courses, compteurs et cartes recalculés
function reload() {
  load()
}
async function loadStock() {
  // Stock : seulement si la formule de l'entreprise l'inclut
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
  // Reversements : seulement l'e-commerce
  if (!auth.can('finance.view') || !auth.user?.merchant_id || !space.hasEcommerce) return
  try {
    balance.value = (await cachedGet(`/finance/merchants/${auth.user.merchant_id}/ledger`, { params: { per_page: 1 } })).data.summary
  } catch {
    // Facultatif : l'accueil reste utilisable sans le solde
  }
}

onMounted(() => {
  load()
  loadRatings()
  loadStock()
  loadBalance()
  space.load()
})
</script>
