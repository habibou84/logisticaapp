<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <h1 class="text-xl font-bold">Statistiques</h1>
      <!-- Filtres : une seule ligne, au-dessus de tous les graphiques -->
      <div class="flex flex-wrap items-end gap-2">
        <div>
          <label class="label" for="st-preset">Période</label>
          <select id="st-preset" v-model="preset" class="input w-auto" @change="applyPreset">
            <option v-for="(p, key) in PRESETS" :key="key" :value="key">{{ p.label }}</option>
            <option value="custom">Dates au choix</option>
          </select>
        </div>
        <div><label class="label" for="st-from">Du</label><input id="st-from" v-model="from" type="date" class="input w-auto" :max="to" @change="custom"></div>
        <div><label class="label" for="st-to">Au</label><input id="st-to" v-model="to" type="date" class="input w-auto" :min="from" :max="today()" @change="custom"></div>
      </div>
    </div>

    <nav ref="tabBar" class="flex gap-1 overflow-x-auto border-b">
      <button
        v-for="t in tabs"
        :key="t.key"
        :data-tab="t.key"
        :class="['-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', tab === t.key ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800']"
        @click="selectTab(t.key)"
      >{{ t.label }}<span v-if="t.locked" class="ml-1" aria-label="non inclus dans votre formule">🔒</span></button>
    </nav>

    <!-- Onglet hors formule : on montre ce qu'il apporte -->
    <section v-if="current?.locked" class="card p-6 text-center space-y-3">
      <p class="text-4xl">🔒</p>
      <h2 class="text-lg font-semibold">Statistiques avancées</h2>
      <p class="mx-auto max-w-md text-slate-600">
        Statistiques par commune (réussite, délais, frais moyen), finances de l'entreprise (revenus, dépenses, marge, argent en circulation)
        et export des tableaux vers Excel. Ce module n'est pas inclus dans votre formule actuelle.
      </p>
      <RouterLink v-if="auth.can('settings.manage')" to="/admin/abonnement" class="btn-primary inline-block">Voir mon abonnement</RouterLink>
    </section>

    <p v-if="error" class="rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
    <p v-if="loading && !data[tab]" class="text-slate-500">Chargement…</p>

    <!-- Vue d'ensemble -->
    <template v-if="tab === 'overview' && data.overview">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiTile title="Courses créées" :value="ov.kpis.created" :previous="ov.previous_kpis.created" />
        <KpiTile title="Courses livrées" :value="ov.kpis.delivered" :previous="ov.previous_kpis.delivered" />
        <KpiTile title="Taux de livraison" kind="percent" :value="ov.kpis.success_rate" :previous="ov.previous_kpis.success_rate" hint="Livrées parmi les courses terminées" />
        <KpiTile title="Échecs de livraison" :value="ov.kpis.failed_attempts" :previous="ov.previous_kpis.failed_attempts" :higher-is-better="false" />
        <template v-if="ov.kpis.revenue !== null">
          <KpiTile title="Chiffre d'affaires (frais de livraison)" kind="money" :value="ov.kpis.revenue" :previous="ov.previous_kpis.revenue" />
          <KpiTile title="Encaissé pour les marchands" kind="money" :value="ov.kpis.collected" :previous="ov.previous_kpis.collected" />
          <KpiTile title="Frais moyen par course" kind="money" :value="ov.kpis.average_fee" :previous="ov.previous_kpis.average_fee" />
        </template>
        <KpiTile title="Retours et annulations" :value="ov.kpis.returned + ov.kpis.cancelled" :previous="ov.previous_kpis.returned + ov.previous_kpis.cancelled" :higher-is-better="false" />
      </div>
      <p class="text-xs text-slate-500">Comparaison avec le {{ date(ov.previous_period.from) }} – {{ date(ov.previous_period.to) }}.</p>

      <section class="card p-4 space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-semibold">Courses par jour</h2>
          <div class="flex gap-2 text-sm">
            <button class="text-blue-600" @click="showTable = !showTable">{{ showTable ? 'Voir le graphique' : 'Voir en tableau' }}</button>
            <button v-if="advanced" class="text-blue-600" @click="exportSeries">Exporter</button>
          </div>
        </div>
        <LineChart
          v-if="!showTable"
          :labels="ov.series.map((d) => d.date)"
          :series="[
            { label: 'Créées', color: SERIES[0], values: ov.series.map((d) => d.created) },
            { label: 'Livrées', color: SERIES[1], values: ov.series.map((d) => d.delivered) },
          ]"
          aria-label="Courses créées et livrées par jour"
        />
        <div v-else class="max-h-80 overflow-y-auto">
          <table class="w-full text-sm">
            <thead class="sticky top-0 bg-slate-50 text-left text-slate-600"><tr><th class="p-2">Jour</th><th class="p-2 text-right">Créées</th><th class="p-2 text-right">Livrées</th><th v-if="ov.kpis.revenue !== null" class="p-2 text-right">Chiffre d'affaires</th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="d in ov.series" :key="d.date"><td class="p-2">{{ shortDate(d.date, true) }}</td><td class="p-2 text-right">{{ d.created }}</td><td class="p-2 text-right">{{ d.delivered }}</td><td v-if="ov.kpis.revenue !== null" class="p-2 text-right">{{ money(d.revenue) }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="grid gap-4 lg:grid-cols-2">
        <section v-if="ov.kpis.revenue !== null" class="card p-4 space-y-2">
          <h2 class="font-semibold">Chiffre d'affaires par jour</h2>
          <ColumnChart :labels="ov.series.map((d) => d.date)" :values="ov.series.map((d) => d.revenue)" label="Chiffre d'affaires" :format="(v, full) => (full ? money(v) : compact(v))" aria-label="Chiffre d'affaires par jour" />
        </section>
        <section class="card p-4 space-y-2">
          <h2 class="font-semibold">Heures de pointe <span class="text-sm font-normal text-slate-500">· courses créées</span></h2>
          <Heatmap :data="ov.heatmap" aria-label="Courses créées par jour de la semaine et par heure" />
        </section>
      </div>
    </template>

    <!-- Livraisons -->
    <template v-if="tab === 'deliveries' && data.deliveries">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiTile title="Réussies au 1er passage" kind="percent" :value="dl.first_attempt_rate" hint="Parmi les courses livrées" />
        <KpiTile title="Passages par course livrée" :value="dl.average_attempts" hint="1 = toujours du premier coup" />
        <KpiTile title="Livrées le jour même" kind="percent" :value="dl.same_day_rate" hint="Hors courses programmées plus tard" />
        <KpiTile title="Échecs de livraison" :value="dl.failed_attempts" hint="Passages ratés chez le destinataire" />
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <section class="card p-4 space-y-3">
          <h2 class="font-semibold">Pourquoi les livraisons échouent</h2>
          <BarList :items="dl.failure_reasons" empty="Aucun échec sur la période. 👏" />
        </section>

        <section class="card p-4 space-y-3">
          <h2 class="font-semibold">Délais habituels <span class="text-sm font-normal text-slate-500">· médiane des courses livrées</span></h2>
          <dl class="grid grid-cols-3 gap-3 text-center">
            <div class="rounded bg-slate-50 p-3"><dt class="text-xs text-slate-500">Création → ramassage</dt><dd class="text-lg font-bold">{{ duration(dl.delays.to_pickup) }}</dd></div>
            <div class="rounded bg-slate-50 p-3"><dt class="text-xs text-slate-500">Ramassage → livraison</dt><dd class="text-lg font-bold">{{ duration(dl.delays.pickup_to_delivery) }}</dd></div>
            <div class="rounded bg-slate-50 p-3"><dt class="text-xs text-slate-500">Délai total</dt><dd class="text-lg font-bold">{{ duration(dl.delays.total) }}</dd></div>
          </dl>
          <p class="text-xs text-slate-500">La médiane : la moitié des courses va plus vite, l'autre moitié plus lentement. Elle n'est pas faussée par quelques courses reportées.</p>

          <h3 class="pt-2 text-sm font-semibold">Issues sur la période</h3>
          <ul class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
            <li v-for="o in outcomes" :key="o.key" class="flex items-center justify-between rounded border px-3 py-2">
              <span class="text-slate-600">{{ o.icon }} {{ o.label }}</span><strong>{{ dl.outcomes[o.key] }}</strong>
            </li>
          </ul>
        </section>
      </div>
    </template>

    <!-- Livreurs -->
    <template v-if="tab === 'couriers' && data.couriers">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiTile title="Livreurs actifs sur la période" :value="cr.totals.active_couriers" />
        <KpiTile title="Courses livrées" :value="cr.totals.delivered" />
        <KpiTile title="Échecs" :value="cr.totals.failed" />
        <KpiTile title="Taux de réussite" kind="percent" :value="cr.totals.success_rate" hint="Livrées / (livrées + échecs)" />
      </div>

      <section class="card overflow-x-auto">
        <div class="flex items-center justify-between p-4 pb-2">
          <h2 class="font-semibold">Classement des livreurs</h2>
          <button v-if="advanced" class="text-sm text-blue-600" @click="exportCouriers">Exporter</button>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-slate-600">
            <tr>
              <th v-for="c in courierColumns" :key="c.key" :class="['p-2 cursor-pointer select-none whitespace-nowrap', c.align === 'right' ? 'text-right' : '']" @click="sortBy(c.key)">
                {{ c.label }}<span v-if="sort.key === c.key">{{ sort.dir === 'desc' ? ' ▾' : ' ▴' }}</span>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="r in sortedCouriers" :key="r.id" :class="r.active ? '' : 'text-slate-400'">
              <td class="p-2 font-medium">{{ r.name }}<span v-if="!r.active" class="ml-1 text-xs">(inactif)</span></td>
              <td class="p-2 text-right font-semibold">{{ r.delivered }}</td>
              <td class="p-2 text-right">{{ r.pickups }}</td>
              <td class="p-2 text-right">{{ r.failed }}</td>
              <td class="p-2 text-right">
                <span v-if="r.success_rate !== null" :class="r.success_rate >= 90 ? 'text-emerald-700' : r.success_rate < 75 ? 'text-red-600' : ''">{{ r.success_rate }} %</span>
                <span v-else>—</span>
              </td>
              <td class="p-2 text-right">{{ duration(r.delivery_minutes) }}</td>
              <td class="p-2 text-right">{{ r.days_worked }}</td>
              <td class="p-2 text-right">{{ r.per_day === null ? '—' : r.per_day.toLocaleString('fr-FR') }}</td>
              <template v-if="withAmounts">
                <td class="p-2 text-right">{{ money(r.collected) }}</td>
                <td :class="['p-2 text-right', r.cash_gap < 0 ? 'text-red-600' : 'text-slate-400']">{{ r.cash_gap ? money(r.cash_gap) : '—' }}</td>
                <td class="p-2 text-right">{{ money(r.earnings) }}</td>
              </template>
            </tr>
            <tr v-if="!cr.couriers.length"><td :colspan="courierColumns.length" class="p-6 text-center text-slate-500">Aucun livreur.</td></tr>
          </tbody>
        </table>
      </section>
      <p class="text-xs text-slate-500">Délai : médiane entre le ramassage et la livraison. Écart de caisse : manques constatés aux versements de la période. Gains : paie enregistrée sur la période.</p>
    </template>

    <MerchantsTab v-if="tab === 'merchants' && data.merchants" :data="data.merchants" :with-amounts="withAmounts" :exportable="advanced" />
    <ZonesTab v-if="tab === 'zones' && data.zones" :data="data.zones" :with-amounts="withAmounts" :exportable="advanced" />
    <FinancesTab v-if="tab === 'finances' && data.finances" :data="data.finances" />
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BarList from '../../components/stats/BarList.vue'
import ColumnChart from '../../components/stats/ColumnChart.vue'
import Heatmap from '../../components/stats/Heatmap.vue'
import FinancesTab from '../../components/stats/FinancesTab.vue'
import KpiTile from '../../components/stats/KpiTile.vue'
import LineChart from '../../components/stats/LineChart.vue'
import MerchantsTab from '../../components/stats/MerchantsTab.vue'
import ZonesTab from '../../components/stats/ZonesTab.vue'
import { SERIES, compact, downloadCsv, shortDate } from '../../components/stats/chart'
import { useAuthStore } from '../../stores/auth'
import { date, money, today, waitDuration } from '../../utils/format'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

function iso(d) {
  const copy = new Date(d)
  copy.setMinutes(copy.getMinutes() - copy.getTimezoneOffset())
  return copy.toISOString().slice(0, 10)
}
function daysAgo(n) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return iso(d)
}
const PRESETS = {
  today: { label: 'Aujourd\'hui', range: () => [today(), today()] },
  week: { label: '7 derniers jours', range: () => [daysAgo(6), today()] },
  lastWeek: {
    label: 'Semaine dernière',
    range: () => {
      // Lundi → dimanche de la semaine précédente
      const d = new Date()
      const monday = new Date(d.getFullYear(), d.getMonth(), d.getDate() - ((d.getDay() + 6) % 7) - 7)
      return [iso(monday), iso(new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + 6))]
    },
  },
  days30: { label: '30 derniers jours', range: () => [daysAgo(29), today()] },
  month: { label: 'Ce mois', range: () => [`${today().slice(0, 7)}-01`, today()] },
  lastMonth: {
    label: 'Mois dernier',
    range: () => {
      const d = new Date()
      return [iso(new Date(d.getFullYear(), d.getMonth() - 1, 1)), iso(new Date(d.getFullYear(), d.getMonth(), 0))]
    },
  },
}

const advanced = computed(() => auth.hasFeature('advanced_stats'))
const tabs = computed(() => [
  { key: 'overview', label: 'Vue d\'ensemble' },
  { key: 'deliveries', label: 'Livraisons' },
  ...(auth.can('orders.dispatch') ? [{ key: 'couriers', label: 'Livreurs' }] : []),
  ...(auth.can('merchants.view') ? [{ key: 'merchants', label: 'E-commerçants' }] : []),
  // Statistiques avancées (selon la formule) : onglets visibles mais verrouillés sans le module
  { key: 'zones', label: 'Zones', locked: !advanced.value },
  ...(auth.can('finance.view') ? [{ key: 'finances', label: 'Finances', locked: !advanced.value }] : []),
])
const current = computed(() => tabs.value.find((t) => t.key === tab.value))
const ENDPOINTS = Object.fromEntries(['overview', 'deliveries', 'couriers', 'merchants', 'zones', 'finances'].map((k) => [k, `/stats/${k}`]))

const preset = ref(PRESETS[route.query.periode] ? route.query.periode : 'month')
const [initialFrom, initialTo] = PRESETS[preset.value].range()
const from = ref(initialFrom)
const to = ref(initialTo)
const tab = ref(tabs.value.some((t) => t.key === route.query.onglet) ? route.query.onglet : 'overview')
const data = reactive(Object.fromEntries(Object.keys(ENDPOINTS).map((k) => [k, null])))
const loading = ref(false)
const error = ref('')
const showTable = ref(false)

const ov = computed(() => data.overview)
const dl = computed(() => data.deliveries)
const cr = computed(() => data.couriers)
const withAmounts = computed(() => auth.can('finance.view'))

async function load() {
  if (current.value?.locked) return
  loading.value = true
  error.value = ''
  const wanted = tab.value
  try {
    const { data: res } = await http.get(ENDPOINTS[wanted], { params: { from: from.value, to: to.value } })
    data[wanted] = res.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function reloadAll() {
  Object.keys(data).forEach((k) => { data[k] = null })
  load()
}

function applyPreset() {
  if (preset.value === 'custom') return
  ;[from.value, to.value] = PRESETS[preset.value].range()
  reloadAll()
}
function custom() {
  if (!from.value || !to.value || from.value > to.value) return
  preset.value = 'custom'
  reloadAll()
}
function selectTab(key) {
  tab.value = key
  if (!data[key]) load()
}

// Petit écran : l'onglet actif reste visible dans la barre (défilement horizontal seulement)
const tabBar = ref(null)
async function revealTab() {
  await nextTick()
  const button = tabBar.value?.querySelector(`[data-tab="${tab.value}"]`)
  if (button) tabBar.value.scrollLeft = Math.max(0, button.offsetLeft - tabBar.value.offsetLeft - 16)
}
onMounted(revealTab)
watch(tab, revealTab)

watch([tab, preset], () => router.replace({ query: { ...route.query, onglet: tab.value, periode: preset.value === 'custom' ? undefined : preset.value } }))

function duration(minutes) {
  return minutes === null || minutes === undefined ? '—' : waitDuration(minutes)
}

const outcomes = [
  { key: 'delivered', label: 'Livrées', icon: '✅' },
  { key: 'rescheduled', label: 'Reportées', icon: '📅' },
  { key: 'returned', label: 'Retournées', icon: '↩️' },
  { key: 'cancelled', label: 'Annulées', icon: '✖️' },
  { key: 'lost', label: 'Perdues', icon: '⚠️' },
]

const courierColumns = computed(() => [
  { key: 'name', label: 'Livreur' },
  { key: 'delivered', label: 'Livrées', align: 'right' },
  { key: 'pickups', label: 'Ramassages', align: 'right' },
  { key: 'failed', label: 'Échecs', align: 'right' },
  { key: 'success_rate', label: 'Réussite', align: 'right' },
  { key: 'delivery_minutes', label: 'Délai', align: 'right' },
  { key: 'days_worked', label: 'Jours', align: 'right' },
  { key: 'per_day', label: 'Par jour', align: 'right' },
  ...(withAmounts.value
    ? [
        { key: 'collected', label: 'Encaissé', align: 'right' },
        { key: 'cash_gap', label: 'Écart de caisse', align: 'right' },
        { key: 'earnings', label: 'Gains', align: 'right' },
      ]
    : []),
])
const sort = reactive({ key: 'delivered', dir: 'desc' })
function sortBy(key) {
  if (sort.key === key) sort.dir = sort.dir === 'desc' ? 'asc' : 'desc'
  else Object.assign(sort, { key, dir: key === 'name' || key === 'delivery_minutes' ? 'asc' : 'desc' })
}
const sortedCouriers = computed(() => {
  const rows = [...(cr.value?.couriers ?? [])]
  const factor = sort.dir === 'desc' ? -1 : 1
  return rows.sort((a, b) => {
    const [x, y] = [a[sort.key], b[sort.key]]
    if (x === y) return 0
    if (x === null) return 1
    if (y === null) return -1
    return (typeof x === 'string' ? x.localeCompare(y, 'fr') : x - y) * factor
  })
})

function download(name, rows) {
  downloadCsv(`${name}_${from.value}_${to.value}.csv`, rows)
}
function exportSeries() {
  const amounts = ov.value.kpis.revenue !== null
  download('courses-par-jour', [
    ['Jour', 'Créées', 'Livrées', ...(amounts ? ['Chiffre d\'affaires (F)'] : [])],
    ...ov.value.series.map((d) => [d.date, d.created, d.delivered, ...(amounts ? [d.revenue] : [])]),
  ])
}
function exportCouriers() {
  download('livreurs', [
    courierColumns.value.map((c) => c.label),
    ...sortedCouriers.value.map((r) => courierColumns.value.map((c) => r[c.key])),
  ])
}

load()
</script>
