<template>
  <div class="space-y-4">
    <section class="card flex flex-wrap items-end gap-3 p-4">
      <div>
        <label class="label" for="pv-rows">Lignes</label>
        <select id="pv-rows" v-model="rows" class="input w-auto" @change="load">
          <option v-for="d in dimensions" :key="d.value" :value="d.value">{{ d.label }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="pv-cols">Colonnes</label>
        <select id="pv-cols" v-model="cols" class="input w-auto" @change="load">
          <option value="">Aucune</option>
          <option v-for="d in dimensions.filter((x) => x.value !== rows)" :key="d.value" :value="d.value">{{ d.label }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="pv-metric">Indicateur</label>
        <select id="pv-metric" v-model="metric" class="input w-auto" @change="load">
          <option v-for="mt in metrics" :key="mt.value" :value="mt.value">{{ mt.label }}</option>
        </select>
      </div>
      <button v-if="result?.table.length" type="button" class="btn-secondary" @click="exportCsv">Exporter</button>
      <p class="text-xs text-slate-500">Courses créées pendant la période.</p>
    </section>

    <p v-if="error" class="rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
    <p v-if="loading" class="text-slate-500">Chargement…</p>

    <section v-if="result" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr>
            <th class="sticky left-0 bg-slate-50 p-2">{{ dimLabel(result.rows) }}</th>
            <th v-for="c in result.columns" :key="c.key" class="whitespace-nowrap p-2 text-right">{{ c.label }}</th>
            <th class="p-2 text-right">Total</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="r in result.table" :key="r.key">
            <td class="sticky left-0 bg-white p-2">{{ r.label }}</td>
            <td v-for="(v, i) in r.cells" :key="i" class="p-2 text-right tabular-nums" :style="heat(v)">{{ fmt(v) }}</td>
            <td class="p-2 text-right font-semibold tabular-nums">{{ fmt(r.total) }}</td>
          </tr>
          <tr v-if="!result.table.length"><td :colspan="result.columns.length + 2" class="p-6 text-center text-slate-500">Aucune course sur la période.</td></tr>
        </tbody>
        <tfoot v-if="result.table.length" class="bg-slate-50 font-semibold">
          <tr>
            <td class="sticky left-0 bg-slate-50 p-2">Total</td>
            <td v-for="(v, i) in result.totals.cells" :key="i" class="p-2 text-right tabular-nums">{{ fmt(v) }}</td>
            <td class="p-2 text-right tabular-nums">{{ fmt(result.totals.total) }}</td>
          </tr>
        </tfoot>
      </table>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { money } from '../../utils/format'
import { downloadCsv } from './chart'

const props = defineProps({ from: { type: String, required: true }, to: { type: String, required: true }, activity: { type: String, default: '' } })
const auth = useAuthStore()

const ALL_DIMENSIONS = [
  { value: 'client', label: 'Client', permission: 'merchants.view' },
  { value: 'zone', label: 'Zone de livraison' },
  { value: 'courier', label: 'Livreur', permission: 'orders.dispatch' },
  { value: 'activity', label: 'Activité' },
  { value: 'weekday', label: 'Jour de la semaine' },
  { value: 'month', label: 'Mois' },
  { value: 'hour', label: 'Heure de création' },
]
const ALL_METRICS = [
  { value: 'courses', label: 'Courses' },
  { value: 'delivered', label: 'Livrées' },
  { value: 'failed', label: 'Échecs (tentatives)' },
  { value: 'success_rate', label: 'Taux de réussite' },
  { value: 'units', label: 'Plis et colis' },
  { value: 'revenue', label: 'Chiffre d\'affaires', permission: 'finance.view' },
]
const dimensions = computed(() => ALL_DIMENSIONS.filter((d) => !d.permission || auth.can(d.permission)))
const metrics = computed(() => ALL_METRICS.filter((d) => !d.permission || auth.can(d.permission)))
const rows = ref(dimensions.value[0]?.value || 'zone')
const cols = ref('weekday')
const metric = ref('courses')
const result = ref(null)
const loading = ref(false)
const error = ref('')

const dimLabel = (key) => ALL_DIMENSIONS.find((d) => d.value === key)?.label || ''
const fmt = (v) => {
  if (v === null || v === undefined) return '—'
  if (metric.value === 'revenue') return money(v)
  if (metric.value === 'success_rate') return `${v} %`
  return v.toLocaleString('fr-FR')
}
const max = computed(() => Math.max(1, ...(result.value?.table || []).flatMap((r) => r.cells).filter((v) => typeof v === 'number')))
const heat = (v) => (typeof v === 'number' && v > 0 ? { background: `rgba(42,120,214,${Math.min(0.55, (v / max.value) * 0.55)})` } : {})

async function load() {
  if (cols.value === rows.value) cols.value = ''
  loading.value = true
  error.value = ''
  try {
    const { data } = await http.get('/stats/pivot', { params: { from: props.from, to: props.to, activity: props.activity || undefined, rows: rows.value, cols: cols.value || undefined, metric: metric.value } })
    result.value = data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function exportCsv() {
  const r = result.value
  downloadCsv(`analyse-${r.rows}${r.cols ? `-${r.cols}` : ''}-${r.metric}.csv`, [
    [dimLabel(r.rows), ...r.columns.map((c) => c.label), 'Total'],
    ...r.table.map((row) => [row.label, ...row.cells, row.total]),
    ['Total', ...r.totals.cells, r.totals.total],
  ])
}

watch(() => [props.from, props.to, props.activity], load)
onMounted(load)
</script>
