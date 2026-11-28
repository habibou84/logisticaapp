<template>
  <div class="space-y-4">
    <!-- Seuils des statistiques (les objectifs par activité viendront avec l'aide à la décision) -->
    <section v-if="canEdit" class="card space-y-3 p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="flex items-center gap-1.5 font-semibold"><Icone nom="objectif" />Seuils des statistiques</h2>
          <p class="text-sm text-slate-600">Ils décident de ce qui est signalé : client « à renégocier », forfait « à revoir à la hausse » ou « sous-utilisé ».</p>
        </div>
        <button type="button" class="btn-secondary" @click="editing = !editing"><Icone v-if="!editing" nom="modifier" />{{ editing ? 'Fermer' : 'Modifier les seuils' }}</button>
      </div>

      <form v-if="editing" class="grid gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="save">
        <div>
          <label class="label" for="renegotiate">Client « à renégocier » sous une marge de (%)</label>
          <input id="renegotiate" v-model.number="form.renegotiate_margin" type="number" min="0" max="100" class="input">
        </div>
        <div>
          <label class="label" for="forfait-high">Forfait « à revoir à la hausse » au-delà de (%)</label>
          <input id="forfait-high" v-model.number="form.forfait_high" type="number" min="50" max="300" class="input">
        </div>
        <div>
          <label class="label" for="forfait-low">Forfait « sous-utilisé » en dessous de (%)</label>
          <input id="forfait-low" v-model.number="form.forfait_low" type="number" min="0" max="100" class="input">
        </div>
        <div class="flex items-end gap-2">
          <button type="submit" class="btn-primary" :disabled="saving">Enregistrer</button>
          <span v-if="error" class="text-sm text-red-700">{{ error }}</span>
        </div>
      </form>
    </section>

    <!-- Comparaisons : mois en cours (ramené au mois entier), même mois l'an dernier, moyenne des 3 mois précédents -->
    <section class="card overflow-x-auto">
      <div class="p-4 pb-2">
        <h2 class="font-semibold">Comparer {{ data.current.label }}</h2>
        <p class="text-sm text-slate-600">Jour {{ data.current.elapsed_days }} sur {{ data.current.days_in_month }} · l'estimation suit le rythme actuel jusqu'à la fin du mois.</p>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr><th class="p-2">Indicateur</th><th class="p-2 text-right">Ce mois (à ce jour)</th><th class="p-2 text-right">Ce mois (estimé)</th><th class="p-2 text-right">Même mois l'an dernier</th><th class="p-2 text-right">Moyenne des 3 mois précédents</th></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="row in comparisonRows" :key="row.key">
            <td class="p-2">{{ row.label }}</td>
            <td class="p-2 text-right">{{ fmt(row, row.c.current) }}</td>
            <td class="p-2 text-right font-semibold">{{ fmt(row, row.c.projection) }}</td>
            <td class="p-2 text-right">{{ fmt(row, row.c.last_year) }} <span :class="trendClass(row, row.c.projection, row.c.last_year)">{{ trend(row.c.projection, row.c.last_year) }}</span></td>
            <td class="p-2 text-right">{{ fmt(row, row.c.last_3_months) }} <span :class="trendClass(row, row.c.projection, row.c.last_3_months)">{{ trend(row.c.projection, row.c.last_3_months) }}</span></td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- 13 mois : saisonnalité -->
    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card space-y-2 p-4">
        <h2 class="font-semibold">Courses sur 13 mois</h2>
        <LineChart
          :labels="data.months.map((m) => m.label)" :dates="false"
          :series="[
            { label: 'Créées', color: SERIES[0], values: data.months.map((m) => m.created) },
            { label: 'Livrées', color: SERIES[1], values: data.months.map((m) => m.delivered) },
          ]"
          aria-label="Courses créées et livrées par mois"
        />
      </section>
      <section v-if="withAmounts" class="card space-y-2 p-4">
        <h2 class="font-semibold">Marge sur 13 mois</h2>
        <ColumnChart :labels="data.months.map((m) => m.label)" :dates="false" :values="data.months.map((m) => m.margin)" label="Marge" :format="(v, full) => (full ? money(v) : compact(v))" aria-label="Marge par mois" />
      </section>
    </div>

    <SortableTable title="Mois par mois" :columns="monthColumns" :rows="data.months.map((m) => ({ ...m, id: m.month }))" filename="mois-par-mois.csv" />
  </div>
</template>

<script setup>
import Icone from '../ui/Icone.vue'
import { computed, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { money } from '../../utils/format'
import ColumnChart from './ColumnChart.vue'
import LineChart from './LineChart.vue'
import SortableTable from './SortableTable.vue'
import { SERIES, compact } from './chart'

const props = defineProps({ data: { type: Object, required: true } })
const emit = defineEmits(['reload'])
const auth = useAuthStore()
const withAmounts = computed(() => auth.can('finance.view'))
const canEdit = computed(() => auth.can('settings.manage'))

const comparisonRows = computed(() => [
  { key: 'created', label: 'Courses créées' },
  { key: 'delivered', label: 'Courses livrées' },
  { key: 'success_rate', label: 'Taux de réussite', percent: true },
  ...(withAmounts.value ? [{ key: 'revenue', label: 'Chiffre d\'affaires', money: true }, { key: 'margin', label: 'Marge nette', money: true }] : []),
].map((r) => ({ ...r, c: props.data.comparisons[r.key] })))

function fmt(kind, v) {
  if (v === null || v === undefined) return '—'
  if (kind.money) return money(v)
  if (kind.percent) return `${Number(v).toLocaleString('fr-FR')} %`
  return Number(v).toLocaleString('fr-FR')
}
const trend = (now, before) => (now === null || before === null || before === undefined || !before ? '' : `${now >= before ? '▲' : '▼'} ${Math.abs(Math.round((now - before) / Math.abs(before) * 100))} %`)
const trendClass = (row, now, before) => (now === null || !before ? '' : now >= before ? 'text-xs text-emerald-700' : 'text-xs text-red-700')

const m = (v) => (v === null || v === undefined ? '—' : money(v))
const monthColumns = [
  { key: 'label', label: 'Mois' },
  { key: 'created', label: 'Créées', align: 'right' },
  { key: 'delivered', label: 'Livrées', align: 'right' },
  { key: 'failed', label: 'Échecs', align: 'right' },
  { key: 'success_rate', label: 'Réussite', align: 'right', format: (v) => (v === null ? '—' : `${v} %`) },
  ...(withAmounts.value ? [
    { key: 'revenue', label: 'Chiffre d\'affaires', align: 'right', format: m },
    { key: 'costs', label: 'Coûts', align: 'right', format: m },
    { key: 'margin', label: 'Marge', align: 'right', format: m, class: (r) => (r.margin < 0 ? 'font-semibold text-red-700' : '') },
  ] : []),
]

// Seuils (Réglages de l'entreprise, droit Réglages) ; les anciens objectifs enregistrés sont renvoyés tels quels
const editing = ref(false)
const saving = ref(false)
const error = ref('')
const form = reactive({ goals: {}, renegotiate_margin: 10, forfait_high: 100, forfait_low: 50, alerts: {} })
const companyId = auth.user?.company_id
async function openForm() {
  const s = (await http.get(`/companies/${companyId}`)).data.data.stats_settings || {}
  Object.assign(form, { goals: { ...s.goals }, renegotiate_margin: s.renegotiate_margin, forfait_high: s.forfait_high, forfait_low: s.forfait_low, alerts: { ...s.alerts } })
}
async function save() {
  saving.value = true
  error.value = ''
  try {
    const goals = Object.fromEntries(Object.entries(form.goals).map(([k, v]) => [k, v === '' || v === null || v === undefined ? null : Number(v)]))
    await http.patch(`/companies/${companyId}`, { stats_settings: { ...form, goals } })
    editing.value = false
    emit('reload')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
if (canEdit.value) openForm().catch(() => {})
</script>
