<template>
  <div class="space-y-4">
    <!-- Respect des délais -->
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Livrées à l'heure" kind="percent" :value="data.delays.rate" :hint="`${data.delays.on_time} sur ${data.delays.total} livrées`" />
      <KpiTile v-for="a in data.delays.by_activity" :key="a.key" :title="`À l'heure · ${a.label}`" kind="percent" :value="a.rate" :hint="`${a.late} en retard sur ${a.total}`" />
    </div>
    <p class="text-xs text-slate-500">
      À l'heure : e-commerce livré au plus tard le jour prévu ; distribution et MAD livrées avant leur délai interne (sans délai : le jour prévu).
    </p>

    <div class="flex flex-wrap gap-2 text-sm" role="group" aria-label="Délais par">
      <button
        v-for="v in DELAY_VIEWS" :key="v.key" type="button"
        :class="['rounded-full px-3 py-1 ring-1', delayView === v.key ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
        :aria-pressed="delayView === v.key" @click="delayView = v.key"
      >
        {{ v.label }}
      </button>
    </div>
    <SortableTable :title="`Respect des délais ${DELAY_VIEWS.find((v) => v.key === delayView).label.toLowerCase()}`" :columns="delayColumns" :rows="delayRows" initial-sort="rate" filename="delais.csv" empty="Aucune livraison sur la période." />

    <div class="grid gap-4 lg:grid-cols-2">
      <section v-if="data.lots.length" class="card space-y-2 p-4">
        <h2 class="font-semibold">📦 Lots arrivés à échéance</h2>
        <ul class="divide-y text-sm">
          <li v-for="l in data.lots" :key="l.id" class="flex flex-wrap items-center justify-between gap-2 py-1.5">
            <span>{{ l.merchant }} · {{ l.name }} <span class="text-xs text-slate-500">échéance {{ date(l.due_on) }}</span></span>
            <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', LOT[l.status].class]">{{ LOT[l.status].label }}<template v-if="l.finished_on"> le {{ date(l.finished_on) }}</template> · {{ l.delivered }}/{{ l.quantity }}</span>
          </li>
        </ul>
      </section>
      <section v-if="data.mad.length" class="card space-y-2 p-4">
        <h2 class="font-semibold">🤝 MAD : jours de présence</h2>
        <ul class="divide-y text-sm">
          <li v-for="m in data.mad" :key="m.id" class="flex flex-wrap items-center justify-between gap-2 py-1.5">
            <span>{{ m.merchant }} <span class="text-xs text-slate-500">{{ m.couriers.join(', ') || 'aucun livreur' }}</span></span>
            <span :class="['font-semibold', m.rate !== null && m.rate < 100 ? 'text-amber-700' : 'text-emerald-700']">{{ m.done_days }} / {{ m.planned_days }} jours<template v-if="m.rate !== null"> ({{ m.rate }} %)</template></span>
          </li>
        </ul>
      </section>
    </div>

    <!-- Échecs -->
    <h2 class="pt-2 text-lg font-semibold">❌ Échecs de livraison <span class="text-sm font-normal text-slate-500">· {{ data.failures.total }} sur la période</span></h2>
    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card space-y-2 p-4">
        <h3 class="font-semibold">Motifs</h3>
        <BarList :items="data.failures.reasons" :color="SERIES[1]" empty="Aucun échec sur la période." />
      </section>
      <section class="card space-y-2 p-4">
        <h3 class="font-semibold">Heure des échecs</h3>
        <ColumnChart :labels="HOURS" :dates="false" :values="data.failures.hours" label="Échecs" :color="SERIES[1]" aria-label="Échecs par heure" />
      </section>
    </div>
    <section v-if="data.failures.matrix.rows.length" class="card overflow-x-auto">
      <h3 class="p-4 pb-2 font-semibold">Motifs par zone</h3>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr><th class="p-2">Zone</th><th v-for="r in data.failures.matrix.reasons" :key="r" class="p-2 text-right">{{ r }}</th><th class="p-2 text-right">Total</th></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="row in data.failures.matrix.rows" :key="row.zone">
            <td class="p-2">{{ row.zone }}</td>
            <td v-for="(n, i) in row.counts" :key="i" class="p-2 text-right" :style="{ background: n ? `rgba(235,104,52,${Math.min(0.6, n / maxCell)})` : '' }">{{ n || '' }}</td>
            <td class="p-2 text-right font-semibold">{{ row.total }}</td>
          </tr>
        </tbody>
      </table>
    </section>
    <div class="flex flex-wrap gap-2 text-sm" role="group" aria-label="Échecs par">
      <button
        v-for="v in FAILURE_VIEWS" :key="v.key" type="button"
        :class="['rounded-full px-3 py-1 ring-1', failureView === v.key ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
        :aria-pressed="failureView === v.key" @click="failureView = v.key"
      >
        {{ v.label }}
      </button>
    </div>
    <SortableTable title="Échecs" :columns="failureColumns" :rows="data.failures[failureView].map((r) => ({ ...r, id: r.key }))" initial-sort="failures" filename="echecs.csv" empty="Aucun échec sur la période." />

    <!-- Distribution -->
    <template v-if="data.distribution && (data.distribution.courses || data.distribution.lots.length)">
      <h2 class="pt-2 text-lg font-semibold">✉️ Distribution</h2>
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiTile title="Plis et chèques remis" :value="data.distribution.units" :hint="`${data.distribution.courses} courses`" />
        <KpiTile title="Accusés manquants" :value="data.distribution.missing_receipts" :higher-is-better="false" />
        <KpiTile title="Temps médian par pli" :text="data.distribution.minutes_per_unit === null ? '—' : duration(data.distribution.minutes_per_unit)" />
        <KpiTile title="Lots en cours" :value="data.distribution.lots.length" />
      </div>
      <section class="card space-y-2 p-4">
        <h3 class="font-semibold">Plis remis par jour</h3>
        <ColumnChart :labels="data.distribution.by_day.map((d) => d.date)" :values="data.distribution.by_day.map((d) => d.units)" label="Plis" aria-label="Plis remis par jour" />
      </section>
      <div class="grid gap-4 lg:grid-cols-2">
        <section v-for="l in data.distribution.lots" :key="l.id" class="card space-y-2 p-4">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-semibold">{{ l.merchant }} · {{ l.name }}</h3>
            <span :class="['text-sm font-semibold', l.gap < 0 ? 'text-red-700' : 'text-emerald-700']">{{ l.delivered }} / {{ l.quantity }} · {{ l.gap < 0 ? `${-l.gap} en retard sur la courbe` : 'dans les temps' }}</span>
          </div>
          <LineChart
            :labels="l.points.map((p) => p.date)"
            :series="[
              { label: 'Idéal jusqu\'à l\'échéance', color: SERIES[3], values: l.points.map((p) => p.ideal) },
              { label: 'Livrés', color: SERIES[0], values: l.points.map((p) => p.delivered) },
            ]"
            :height="180" :aria-label="`Avancement du lot ${l.name}`"
          />
          <p class="text-xs text-slate-500">Échéance le {{ date(l.due_on) }}</p>
        </section>
      </div>
    </template>

    <!-- Historique -->
    <section class="card space-y-2 p-4">
      <h2 class="font-semibold">⏳ Courses non traitées dans le temps</h2>
      <LineChart
        v-if="data.history.length > 1"
        :labels="data.history.map((h) => h.date)"
        :series="[
          { label: 'Non traitées', color: SERIES[1], values: data.history.map((h) => h.untreated) },
          { label: 'Délai interne dépassé', color: SERIES[3], values: data.history.map((h) => h.internal_late) },
          { label: 'Sans livreur', color: SERIES[0], values: data.history.map((h) => h.unassigned) },
        ]"
        aria-label="Courses non traitées chaque soir"
      />
      <p v-else class="text-sm text-slate-500">Relevé chaque soir à 23 h 50 : la courbe apparaît dès le deuxième soir.<template v-if="data.history.length"> Dernier relevé ({{ date(data.history[0].date) }}) : {{ data.history[0].untreated }} course(s) non traitée(s).</template></p>
    </section>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { date, waitDuration } from '../../utils/format'
import BarList from './BarList.vue'
import ColumnChart from './ColumnChart.vue'
import KpiTile from './KpiTile.vue'
import LineChart from './LineChart.vue'
import SortableTable from './SortableTable.vue'
import { SERIES } from './chart'

const props = defineProps({ data: { type: Object, required: true } })
const duration = waitDuration
const HOURS = Array.from({ length: 24 }, (_, h) => `${h} h`)
const LOT = {
  on_time: { label: 'Terminé à temps', class: 'bg-emerald-100 text-emerald-900' },
  late: { label: 'En retard', class: 'bg-red-100 text-red-900' },
  in_progress: { label: 'En cours', class: 'bg-sky-100 text-sky-900' },
}
const pct = (v) => (v === null || v === undefined ? '—' : `${v.toLocaleString('fr-FR')} %`)

const DELAY_VIEWS = [
  { key: 'by_client', label: 'Par client' },
  { key: 'by_zone', label: 'Par zone' },
  { key: 'by_courier', label: 'Par livreur' },
]
const delayView = ref('by_client')
const delayRows = computed(() => props.data.delays[delayView.value].map((r) => ({ ...r, id: r.key })))
const delayColumns = [
  { key: 'label', label: 'Nom', asc: true },
  { key: 'total', label: 'Livrées', align: 'right' },
  { key: 'on_time', label: 'À l\'heure', align: 'right' },
  { key: 'late', label: 'En retard', align: 'right' },
  { key: 'rate', label: 'Taux', align: 'right', format: pct, asc: true, class: (r) => (r.rate !== null && r.rate < 80 ? 'font-semibold text-red-700' : '') },
]

const FAILURE_VIEWS = [
  { key: 'by_courier', label: 'Par livreur' },
  { key: 'by_client', label: 'Par client' },
  { key: 'by_zone', label: 'Par zone' },
]
const failureView = ref('by_courier')
const failureColumns = [
  { key: 'label', label: 'Nom', asc: true },
  { key: 'failures', label: 'Échecs', align: 'right' },
  { key: 'delivered', label: 'Livrées', align: 'right' },
  { key: 'failure_rate', label: 'Taux d\'échec', align: 'right', format: pct },
  { key: 'top_reason', label: 'Motif le plus fréquent' },
]
const maxCell = computed(() => Math.max(1, ...props.data.failures.matrix.rows.flatMap((r) => r.counts)))
</script>
