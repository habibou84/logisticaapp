<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Tableau de direction</h1>
        <p class="text-sm text-slate-600">Les activités côte à côte, comparées à la période précédente, et les points à décider.</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <div>
          <label class="label" for="dr-preset">Période</label>
          <select id="dr-preset" v-model="preset" class="input w-auto" @change="applyPreset">
            <option v-for="(p, key) in PRESETS" :key="key" :value="key">{{ p.label }}</option>
            <option value="custom">Personnalisée</option>
          </select>
        </div>
        <div><label class="label" for="dr-from">Du</label><input id="dr-from" v-model="from" type="date" class="input w-auto" :max="to" @change="custom"></div>
        <div><label class="label" for="dr-to">Au</label><input id="dr-to" v-model="to" type="date" class="input w-auto" :min="from" :max="today()" @change="custom"></div>
      </div>
    </div>

    <p v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</p>
    <p v-if="loading && !board" class="text-slate-600">Chargement…</p>

    <template v-if="board">
      <!-- Chiffres clés de l'entreprise -->
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <KpiTile title="Chiffre d'affaires" :value="board.totals.revenue" :previous="board.totals.previous_revenue" kind="money" />
        <KpiTile title="Marge nette (estimée)" :value="board.totals.margin" :previous="board.totals.previous_margin" kind="money" />
        <KpiTile title="Taux de marge nette" :value="board.totals.margin_rate" :previous="board.totals.previous_margin_rate" kind="percent" />
        <KpiTile title="Courses livrées" :value="board.totals.delivered" :previous="board.totals.previous_delivered" />
        <KpiTile
          title="Factures en retard de plus de 30 j" :value="board.totals.overdue" kind="money"
          :hint="`${money(board.totals.outstanding)} à encaisser au total`"
        />
      </div>

      <div class="grid gap-4 lg:grid-cols-5">
        <section class="card p-4 lg:col-span-3">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
              <h2 class="font-semibold">Chiffre d'affaires par mois et par activité</h2>
              <p class="text-xs text-slate-600">12 derniers mois, mois en cours compris</p>
            </div>
            <ul class="flex flex-wrap gap-3 text-xs text-slate-600">
              <li v-for="a in board.activities" :key="a.value" class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: COLORS[a.value] }" />{{ a.label }}</li>
            </ul>
          </div>
          <StackedColumns
            class="mt-3" :labels="board.months.map((m) => m.label)" :series="monthSeries"
            :format="(v, full) => (full ? money(v) : compact(v))" aria-label="Chiffre d'affaires par mois, empilé par activité"
          />
        </section>

        <section class="card p-4 lg:col-span-2">
          <h2 class="font-semibold">Part du chiffre d'affaires et de la marge</h2>
          <p class="text-xs text-slate-600">Sur la période choisie</p>
          <div v-for="row in shares" :key="row.label" class="mt-4">
            <p class="text-sm text-slate-600">{{ row.label }}</p>
            <div v-if="row.parts.length" class="mt-1 flex h-7 gap-0.5 overflow-hidden rounded">
              <div
                v-for="p in row.parts" :key="p.value" class="flex items-center justify-center text-xs font-semibold text-white"
                :style="{ width: `${p.share}%`, background: COLORS[p.value] }" :title="`${p.label} : ${money(p.amount)} (${p.share} %)`"
              >
                <span v-if="p.share >= 12">{{ p.share }} %</span>
              </div>
            </div>
            <p v-else class="mt-1 text-sm text-slate-600">{{ row.empty }}</p>
          </div>
          <p v-if="insight" class="mt-4 border-l-2 border-slate-300 pl-3 text-sm text-slate-600">{{ insight }}</p>
        </section>
      </div>

      <!-- Les activités côte à côte -->
      <section class="card overflow-x-auto">
        <div class="p-4 pb-2">
          <h2 class="font-semibold">Les activités côte à côte</h2>
          <p class="text-xs text-slate-600">{{ periodText(board.period) }}, comparé à {{ periodText(board.previous_period) }}</p>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-slate-600">
            <tr>
              <th class="p-2 pl-4">Activité</th>
              <th class="p-2 text-right">Chiffre d'affaires</th>
              <th class="p-2 text-right">Part</th>
              <th class="p-2 text-right">Évolution</th>
              <th class="p-2 text-right">Marge nette</th>
              <th class="p-2 text-right">Taux</th>
              <th class="p-2 text-right">Courses livrées</th>
              <th class="p-2">Qualité</th>
              <th class="p-2 pr-4 text-right">En retard &gt; 30 j</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="a in board.activities" :key="a.value">
              <td class="p-2 pl-4 font-medium whitespace-nowrap"><span class="mr-2 inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: COLORS[a.value] }" />{{ a.label }}</td>
              <td class="p-2 text-right whitespace-nowrap">{{ money(a.revenue) }}</td>
              <td class="p-2 text-right">{{ percent(share(a.revenue, board.totals.revenue)) }}</td>
              <td class="p-2 text-right whitespace-nowrap" :class="changeClass(change(a.revenue, a.previous_revenue))">{{ changeText(change(a.revenue, a.previous_revenue)) }}</td>
              <td class="p-2 text-right whitespace-nowrap" :class="a.margin < 0 ? 'text-red-600' : ''">{{ money(a.margin) }}</td>
              <td class="p-2 text-right font-semibold" :class="a.margin < 0 ? 'text-red-600' : ''">{{ percent(a.margin_rate) }}</td>
              <td class="p-2 text-right">{{ a.delivered.toLocaleString('fr-FR') }}</td>
              <td class="p-2 whitespace-nowrap">{{ a.quality.label }} <strong>{{ percent(a.quality.rate) }}</strong></td>
              <td class="p-2 pr-4 text-right whitespace-nowrap" :class="a.overdue ? 'text-amber-800 font-semibold' : 'text-slate-600'">{{ a.overdue === null ? '—' : money(a.overdue) }}</td>
            </tr>
          </tbody>
        </table>
        <p class="p-4 pt-2 text-xs text-slate-600">
          Marge nette : revenus moins paie des livreurs, véhicules, frais de course et frais fixes. La répartition des coûts entre activités est une estimation.
          <template v-if="board.totals.unallocated_costs"> {{ money(board.totals.unallocated_costs) }} de coûts ne sont rattachés à aucune activité (livreur sans mission sur la période) : ils comptent dans le total, pas dans les lignes.</template>
          E-commerce : les frais sont retenus sur les reversements, il n'y a pas de facture.
        </p>
      </section>

      <!-- À décider -->
      <section class="card p-4">
        <h2 class="font-semibold">À décider</h2>
        <p class="text-xs text-slate-600">{{ board.decisions.length ? `${board.decisions.length} point(s), du plus grave au moins grave` : 'Rien à signaler sur cette période.' }}</p>
        <ul class="mt-3 space-y-2">
          <li v-for="(d, i) in board.decisions" :key="i" :class="['flex flex-wrap items-start gap-3 rounded-lg border px-3 py-3', LEVELS[d.level].box]">
            <span :class="['mt-0.5 inline-flex h-6 w-6 flex-none items-center justify-center rounded-full text-xs font-bold text-white', LEVELS[d.level].dot]" aria-hidden="true">{{ LEVELS[d.level].sign }}</span>
            <div class="min-w-0 flex-1">
              <p :class="['text-xs font-bold uppercase tracking-wide', LEVELS[d.level].text]">{{ LEVELS[d.level].label }} · {{ d.activity_label }} · {{ d.topic }}</p>
              <p class="font-semibold text-slate-900">{{ d.title }}</p>
              <p class="text-sm text-slate-600">{{ d.body }}</p>
            </div>
            <RouterLink :to="d.href" class="rounded-md border bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-slate-50 whitespace-nowrap">{{ d.action }}</RouterLink>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import KpiTile from '../../components/stats/KpiTile.vue'
import StackedColumns from '../../components/stats/StackedColumns.vue'
import { SERIES, compact } from '../../components/stats/chart'
import { date, money, today } from '../../utils/format'

// Une couleur par activité, toujours la même (ordre validé des séries)
const COLORS = { ecommerce: SERIES[0], distribution: SERIES[1], mad: SERIES[2] }
const LEVELS = {
  critical: { label: 'Urgent', sign: '!', box: 'border-red-200 bg-red-50', dot: 'bg-red-700', text: 'text-red-700' },
  warning: { label: 'À traiter', sign: '!', box: 'border-amber-200 bg-amber-50', dot: 'bg-amber-700', text: 'text-amber-800' },
  info: { label: 'À surveiller', sign: 'i', box: 'border-blue-200 bg-blue-50', dot: 'bg-blue-700', text: 'text-blue-800' },
  good: { label: 'Bonne nouvelle', sign: '✓', box: 'border-emerald-200 bg-emerald-50', dot: 'bg-emerald-700', text: 'text-emerald-700' },
}

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
  month: { label: 'Ce mois', range: () => [`${today().slice(0, 7)}-01`, today()] },
  lastMonth: {
    label: 'Mois dernier',
    range: () => {
      const d = new Date()
      return [iso(new Date(d.getFullYear(), d.getMonth() - 1, 1)), iso(new Date(d.getFullYear(), d.getMonth(), 0))]
    },
  },
  days30: { label: '30 derniers jours', range: () => [daysAgo(29), today()] },
  days90: { label: '90 derniers jours', range: () => [daysAgo(89), today()] },
}

const preset = ref('month')
const [initialFrom, initialTo] = PRESETS.month.range()
const from = ref(initialFrom)
const to = ref(initialTo)
const board = ref(null)
const loading = ref(false)
const error = ref('')

const share = (part, total) => (total > 0 ? Math.round((part / total) * 100) : null)
const change = (now, before) => (before > 0 ? Math.round((now / before - 1) * 100) : null)
const percent = (v) => (v === null || v === undefined ? '—' : `${v.toLocaleString('fr-FR')} %`)
const changeText = (v) => (v === null ? '—' : `${v > 0 ? '▲ +' : v < 0 ? '▼ ' : '= '}${v} %`)
const changeClass = (v) => (!v ? 'text-slate-600' : v > 0 ? 'text-emerald-700' : 'text-red-600')
const periodText = (p) => (p.from === p.to ? date(p.from) : `du ${date(p.from)} au ${date(p.to)}`)

const monthSeries = computed(() => board.value.activities.map((a) => ({
  label: a.label, color: COLORS[a.value], values: board.value.months.map((m) => m.revenue[a.value] ?? 0),
})))

// Parts : seules les activités au montant positif entrent dans la barre
function parts(field) {
  const rows = board.value.activities.filter((a) => a[field] > 0)
  const total = rows.reduce((sum, a) => sum + a[field], 0)
  return rows.map((a) => ({ value: a.value, label: a.label, amount: a[field], share: Math.round((a[field] / total) * 100) }))
}
const shares = computed(() => [
  { label: 'Chiffre d\'affaires', parts: parts('revenue'), empty: 'Pas de chiffre d\'affaires sur la période.' },
  { label: 'Marge nette', parts: parts('margin'), empty: 'Aucune activité n\'a de marge positive sur la période.' },
])
// L'écart le plus fort entre la part du chiffre d'affaires et la part de la marge
const insight = computed(() => {
  const [revenue, margin] = shares.value.map((s) => s.parts)
  if (revenue.length < 2 || !margin.length) return ''
  const gaps = revenue.map((r) => ({ ...r, margin: margin.find((m) => m.value === r.value)?.share ?? 0 })).sort((a, b) => Math.abs(b.margin - b.share) - Math.abs(a.margin - a.share))
  const top = gaps[0]
  if (Math.abs(top.margin - top.share) < 10) return ''
  return `${top.label} : ${top.share} % du chiffre d'affaires, ${top.margin} % de la marge nette.`
})

async function load() {
  if (!from.value || !to.value) return
  loading.value = true
  error.value = ''
  try {
    board.value = (await http.get('/stats/direction', { params: { from: from.value, to: to.value } })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
function applyPreset() {
  if (!PRESETS[preset.value]) return
  ;[from.value, to.value] = PRESETS[preset.value].range()
  load()
}
function custom() {
  preset.value = 'custom'
  load()
}

load()
</script>
