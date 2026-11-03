<template>
  <!-- Rapport mensuel imprimable (Enregistrer en PDF depuis l'impression) : à l'en-tête de l'entreprise -->
  <div class="report-page mx-auto max-w-4xl bg-white p-6 text-slate-900">
    <div class="no-print mb-4 flex flex-wrap items-center gap-2">
      <input v-model="month" type="month" class="input w-auto" aria-label="Mois" @change="load">
      <button class="btn-primary" :disabled="loading" @click="print">🖨 Imprimer ou enregistrer en PDF</button>
      <span class="text-sm text-slate-500">Dans la fenêtre d'impression, choisissez « Enregistrer au format PDF ».</span>
    </div>

    <p v-if="loading">Chargement…</p>
    <p v-else-if="error" class="text-red-700">{{ error }}</p>
    <template v-else-if="perf">
      <header class="mb-6 flex items-center justify-between gap-4 border-b pb-4">
        <div class="flex items-center gap-3">
          <BrandLogo class="h-12 w-auto" />
          <div>
            <h1 class="text-2xl font-bold">Rapport mensuel · {{ monthLabel }}</h1>
            <p class="text-sm text-slate-600">{{ auth.user?.company?.name }} · édité le {{ new Date().toLocaleDateString('fr-FR') }}</p>
          </div>
        </div>
      </header>

      <section class="mb-6">
        <h2 class="mb-2 text-lg font-semibold">Chiffres clés</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div v-for="k in keyFigures" :key="k.label" class="rounded-lg border p-3">
            <p class="text-xs text-slate-500">{{ k.label }}</p>
            <p class="text-xl font-bold">{{ k.value }}</p>
            <p v-if="k.hint" class="text-xs text-slate-500">{{ k.hint }}</p>
          </div>
        </div>
      </section>

      <section v-if="goals.length" class="mb-6">
        <h2 class="mb-2 text-lg font-semibold">Objectifs</h2>
        <table class="w-full text-sm">
          <thead><tr class="border-b text-left"><th class="py-1">Objectif</th><th class="py-1 text-right">Visé</th><th class="py-1 text-right">Réalisé</th><th class="py-1 text-right">%</th></tr></thead>
          <tbody><tr v-for="g in goals" :key="g.label" class="border-b"><td class="py-1">{{ g.label }}</td><td class="py-1 text-right">{{ g.target }}</td><td class="py-1 text-right">{{ g.realized }}</td><td class="py-1 text-right">{{ g.percent }}</td></tr></tbody>
        </table>
      </section>

      <section v-if="profit" class="mb-6">
        <h2 class="mb-2 text-lg font-semibold">Rentabilité</h2>
        <table class="w-full text-sm">
          <tbody>
            <tr class="border-b"><td class="py-1">Revenus</td><td class="py-1 text-right font-semibold">{{ money(profit.totals.revenue) }}</td></tr>
            <tr class="border-b"><td class="py-1">Coûts directs (paie, véhicules, frais, pertes)</td><td class="py-1 text-right">− {{ money(profit.totals.direct_costs) }}</td></tr>
            <tr class="border-b"><td class="py-1">Frais fixes</td><td class="py-1 text-right">− {{ money(profit.totals.fixed_costs) }}</td></tr>
            <tr class="border-b font-semibold"><td class="py-1">Marge nette</td><td class="py-1 text-right">{{ money(profit.totals.margin) }}{{ profit.totals.margin_rate === null ? '' : ` (${profit.totals.margin_rate} %)` }}</td></tr>
          </tbody>
        </table>
        <h3 class="mb-1 mt-4 font-semibold">Clients (les 10 plus gros)</h3>
        <table class="w-full text-xs">
          <thead><tr class="border-b text-left"><th class="py-1">Client</th><th class="py-1 text-right">Livrées</th><th class="py-1 text-right">Revenus</th><th class="py-1 text-right">Marge</th><th class="py-1 text-right">Taux</th></tr></thead>
          <tbody>
            <tr v-for="c in topClients" :key="c.id" class="border-b"><td class="py-1">{{ c.name }}</td><td class="py-1 text-right">{{ c.courses }}</td><td class="py-1 text-right">{{ money(c.revenue) }}</td><td :class="['py-1 text-right', c.margin < 0 ? 'text-red-700' : '']">{{ money(c.margin) }}</td><td class="py-1 text-right">{{ c.margin_rate ?? '—' }} %</td></tr>
          </tbody>
        </table>
      </section>

      <section v-if="quality" class="mb-6 break-inside-avoid">
        <h2 class="mb-2 text-lg font-semibold">Qualité de service</h2>
        <table class="w-full text-sm">
          <tbody>
            <tr v-for="a in quality.delays.by_activity" :key="a.key" class="border-b"><td class="py-1">Livrées à l'heure · {{ a.label }}</td><td class="py-1 text-right">{{ a.rate ?? '—' }} % ({{ a.on_time }} / {{ a.total }})</td></tr>
            <tr class="border-b"><td class="py-1">Échecs de livraison</td><td class="py-1 text-right">{{ quality.failures.total }}</td></tr>
            <tr v-for="r in quality.failures.reasons.slice(0, 5)" :key="r.label" class="border-b text-slate-600"><td class="py-1 pl-4">{{ r.label }}</td><td class="py-1 text-right">{{ r.count }}</td></tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import BrandLogo from '../components/auth/BrandLogo.vue'
import { useAuthStore } from '../stores/auth'
import { money } from '../utils/format'

const auth = useAuthStore()
const route = useRoute()
const lastMonth = () => {
  const d = new Date()
  d.setDate(1)
  d.setMonth(d.getMonth() - 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}
const month = ref(/^\d{4}-\d{2}$/.test(route.query.mois || '') ? route.query.mois : lastMonth())
const perf = ref(null)
const profit = ref(null)
const quality = ref(null)
const loading = ref(true)
const error = ref('')
const range = computed(() => {
  const [y, m] = month.value.split('-').map(Number)
  const end = new Date(y, m, 0)
  const today = new Date().toLocaleDateString('sv-SE')
  const to = `${month.value}-${String(end.getDate()).padStart(2, '0')}`
  return { from: `${month.value}-01`, to: to > today ? today : to }
})
const monthLabel = computed(() => new Date(`${month.value}-01T12:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }))
const row = computed(() => perf.value?.months.find((m) => m.month === month.value))
const keyFigures = computed(() => [
  { label: 'Courses créées', value: row.value?.created ?? '—' },
  { label: 'Courses livrées', value: row.value?.delivered ?? '—' },
  { label: 'Taux de réussite', value: row.value?.success_rate === null || row.value?.success_rate === undefined ? '—' : `${row.value.success_rate} %` },
  ...(row.value?.revenue !== null && row.value?.revenue !== undefined ? [{ label: 'Chiffre d\'affaires', value: money(row.value.revenue), hint: `marge ${money(row.value.margin)}` }] : []),
])
// Objectifs : seulement pour le mois en cours (les objectifs sont ceux du mois)
const goals = computed(() => {
  if (!perf.value || perf.value.current.month !== month.value) return []
  const labels = { delivered: 'Courses livrées', revenue: 'Chiffre d\'affaires', success_rate: 'Taux de réussite', margin: 'Marge nette' }
  return Object.entries(perf.value.current.goals).filter(([, g]) => g && g.target !== null).map(([k, g]) => ({
    label: labels[k],
    target: ['revenue', 'margin'].includes(k) ? money(g.target) : g.target,
    realized: ['revenue', 'margin'].includes(k) ? money(g.realized) : g.realized,
    percent: `${g.percent} %`,
  }))
})
const topClients = computed(() => [...(profit.value?.clients || [])].sort((a, b) => b.revenue - a.revenue).slice(0, 10))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = range.value
    const [p, q, r] = await Promise.all([
      http.get('/stats/performance'),
      http.get('/stats/quality', { params }),
      auth.can('finance.view') ? http.get('/stats/profitability', { params }) : null,
    ])
    perf.value = p.data.data
    quality.value = q.data.data
    profit.value = r?.data.data ?? null
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function print() {
  window.print()
}

onMounted(load)
</script>

<style scoped>
@media print {
  .no-print { display: none; }
  .report-page { padding: 0; max-width: none; }
}
</style>
