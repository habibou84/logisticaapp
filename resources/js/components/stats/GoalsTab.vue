<template>
  <div class="space-y-4">
    <!-- Objectifs du mois : réalisé, projection au rythme actuel -->
    <section class="card space-y-3 p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="font-semibold">🎯 Objectifs de {{ data.current.label }}</h2>
          <p class="text-sm text-slate-500">Jour {{ data.current.elapsed_days }} sur {{ data.current.days_in_month }} · la projection suit le rythme actuel jusqu'à la fin du mois.</p>
        </div>
        <button v-if="canEdit" type="button" class="btn-secondary" @click="editing = !editing">{{ editing ? 'Fermer' : '✏️ Fixer les objectifs' }}</button>
      </div>

      <form v-if="editing" class="grid gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="save">
        <div v-for="g in GOALS" :key="g.key">
          <label class="label" :for="`goal-${g.key}`">{{ g.label }} {{ g.unit ? `(${g.unit})` : '' }}</label>
          <input :id="`goal-${g.key}`" v-model="form.goals[g.key]" type="number" :step="g.key === 'success_rate' ? '0.1' : '1'" class="input" placeholder="Pas d'objectif">
        </div>
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
        <fieldset class="grid gap-3 sm:col-span-2 lg:col-span-4 sm:grid-cols-2 lg:grid-cols-4 rounded-lg border border-slate-200 p-3">
          <legend class="px-1 text-sm font-semibold">🔔 Alertes du matin aux administrateurs</legend>
          <div>
            <label class="label" for="alert-zone">Zone dont la réussite passe sous (%)</label>
            <input id="alert-zone" v-model="form.alerts.zone_success_min" type="number" min="0" max="100" class="input" placeholder="Pas d'alerte">
          </div>
          <div>
            <label class="label" for="alert-client">Client en baisse de plus de (%)</label>
            <input id="alert-client" v-model="form.alerts.client_drop_percent" type="number" min="1" max="100" class="input" placeholder="Pas d'alerte">
          </div>
          <div>
            <label class="label" for="alert-volume">Seulement à partir de (courses)</label>
            <input id="alert-volume" v-model.number="form.alerts.min_volume" type="number" min="1" class="input">
          </div>
          <div class="space-y-2 text-sm">
            <label class="flex items-center gap-2"><input v-model="form.alerts.courier_failures_double" type="checkbox"> Livreur dont les échecs doublent</label>
            <label class="flex items-center gap-2"><input v-model="form.alerts.whatsapp" type="checkbox"> Aussi sur WhatsApp</label>
          </div>
        </fieldset>
        <div class="flex items-end gap-2">
          <button type="submit" class="btn-primary" :disabled="saving">Enregistrer</button>
          <span v-if="error" class="text-sm text-red-700">{{ error }}</span>
        </div>
      </form>

      <p v-if="!data.goals_set && !editing" class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
        Aucun objectif fixé. {{ canEdit ? 'Fixez-en pour suivre le mois en cours et sa projection.' : 'Un administrateur peut les fixer.' }}
      </p>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div v-for="g in shownGoals" :key="g.key" class="rounded-xl border border-slate-200 p-3">
          <p class="text-sm text-slate-500">{{ g.label }}</p>
          <p class="text-2xl font-bold">{{ fmt(g, g.goal.realized) }}</p>
          <template v-if="g.goal.target !== null">
            <div class="mt-2 h-2 rounded-full bg-slate-100" role="progressbar" :aria-valuenow="g.goal.percent" aria-valuemin="0" aria-valuemax="100">
              <div :class="['h-2 rounded-full', g.goal.on_track ? 'bg-emerald-500' : 'bg-amber-500']" :style="{ width: `${Math.min(100, Math.max(0, g.goal.percent || 0))}%` }" />
            </div>
            <p class="mt-1 text-xs text-slate-600">
              objectif {{ fmt(g, g.goal.target) }} · {{ g.goal.percent }} %
              <template v-if="g.key !== 'success_rate'"> · fin de mois estimée {{ fmt(g, g.goal.projection) }}</template>
            </p>
            <p :class="['mt-1 text-xs font-semibold', g.goal.on_track ? 'text-emerald-700' : 'text-amber-700']">{{ g.goal.on_track ? '✓ En bonne voie' : '⚠️ En retard sur l\'objectif' }}</p>
          </template>
          <p v-else class="mt-1 text-xs text-slate-400">pas d'objectif</p>
        </div>
      </div>
    </section>

    <!-- Comparaisons : mois en cours (ramené au mois entier), même mois l'an dernier, moyenne des 3 mois précédents -->
    <section class="card overflow-x-auto">
      <h2 class="p-4 pb-2 font-semibold">Comparer {{ data.current.label }}</h2>
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

const GOALS = [
  { key: 'delivered', label: 'Courses livrées' },
  { key: 'revenue', label: 'Chiffre d\'affaires', unit: 'F', money: true },
  { key: 'success_rate', label: 'Taux de réussite', unit: '%', percent: true },
  { key: 'margin', label: 'Marge nette', unit: 'F', money: true },
]
const shownGoals = computed(() => GOALS.map((g) => ({ ...g, goal: props.data.current.goals[g.key] })).filter((g) => g.goal))
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

// Objectifs et seuils (Réglages de l'entreprise, droit Réglages)
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
    const blank = (v) => (v === '' || v === undefined ? null : v)
    const alerts = { ...form.alerts, zone_success_min: blank(form.alerts.zone_success_min), client_drop_percent: blank(form.alerts.client_drop_percent) }
    await http.patch(`/companies/${companyId}`, { stats_settings: { ...form, goals, alerts } })
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
