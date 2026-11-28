<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Livreurs actifs" :value="k.couriers" :previous="k.previous_couriers" />
      <KpiTile title="Livraisons par livreur et par jour" :value="k.per_day" :previous="k.previous_per_day" />
      <KpiTile title="Temps moyen par mission (minutes)" :value="k.minutes" :previous="k.previous_minutes" :higher-is-better="false" :hint="k.minutes === null ? 'Aucune mission chronométrée' : 'minutes'" />
      <KpiTile title="Taux de réussite" :value="k.success_rate" kind="percent" :hint="k.rating === null ? 'Aucune note sur la période' : `Note moyenne : ${k.rating.toLocaleString('fr-FR')} / 5`" />
      <KpiTile title="Paie des livreurs" :value="k.pay" :previous="k.previous_pay" kind="money" :higher-is-better="false" />
      <KpiTile title="Paie par course livrée" :value="k.pay_per_delivery" :previous="k.previous_pay_per_delivery" kind="money" :higher-is-better="false" />
      <KpiTile title="Écarts de caisse" :value="Math.abs(k.cash_gap)" kind="money" hint="Versements inférieurs à l'attendu" />
      <KpiTile title="Retard moyen au pointage" :value="k.late_minutes" :hint="k.late_minutes === null ? 'Aucun pointage sur la période' : `minutes · ${k.staff} agent(s) au bureau`" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <div>
            <h2 class="font-semibold">Charge par zone</h2>
            <p class="text-xs text-slate-600">Livraisons par livreur et par jour. Trait : moyenne des zones ({{ number(data.zones.average) }})</p>
          </div>
          <ul class="flex flex-wrap gap-3 text-xs text-slate-600">
            <li v-for="l in LOADS" :key="l.label" class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: l.color }" />{{ l.label }}</li>
          </ul>
        </div>
        <SignedBars
          class="mt-3" :rows="zones" :color="TEAM" :format="(v) => v.toLocaleString('fr-FR', { minimumFractionDigits: 1 })" value-width="3.5rem"
          :marks="data.zones.average ? [{ value: data.zones.average, label: 'Moyenne des zones' }] : []" empty="Aucune livraison sur la période."
        />
      </section>

      <section class="card p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <div>
            <h2 class="font-semibold">Temps moyen d'une mission, étape par étape</h2>
            <p class="text-xs text-slate-600">De l'affectation à la fin de la mission, en minutes</p>
          </div>
          <ul class="flex flex-wrap gap-3 text-xs text-slate-600">
            <li v-for="s in STEPS" :key="s.key" class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: s.color }" />{{ s.label }}</li>
          </ul>
        </div>
        <ul class="mt-3 space-y-2">
          <li v-for="row in timeRows" :key="row.label" class="grid items-center gap-2 text-sm" style="grid-template-columns: 6.5rem minmax(0, 1fr) 4rem" :title="row.title">
            <span class="text-slate-700">{{ row.label }}</span>
            <div class="flex h-6 gap-0.5" :style="{ width: `${(row.total / maxTime) * 100}%` }">
              <div v-for="s in row.steps" :key="s.key" class="flex min-w-0 items-center justify-center overflow-hidden rounded-sm text-xs font-semibold" :style="{ flex: s.value, background: s.color, color: s.ink }">{{ s.value }}</div>
            </div>
            <span class="text-right font-semibold whitespace-nowrap">{{ row.total }} min</span>
          </li>
          <li v-if="!timeRows.length" class="text-sm text-slate-600">Aucune mission chronométrée sur la période.</li>
        </ul>
        <p v-if="data.times.measured" class="mt-3 border-l-2 border-slate-300 pl-3 text-sm text-slate-600">
          Trajet et remise : {{ data.times.run }} min en moyenne, {{ data.times.median }} min en médiane.
          Mesuré sur {{ number(data.times.measured) }} mission(s) sur {{ number(data.times.missions) }} : les durées de moins d'une minute ou de plus de 4 heures sont écartées (départ non signalé, validation en lot).
        </p>
      </section>
    </div>

    <section class="card p-4">
      <h2 class="font-semibold">Répartition des livreurs par productivité</h2>
      <p class="text-xs text-slate-600">Nombre de livreurs selon leurs livraisons par jour travaillé. Choisissez une tranche pour voir les livreurs concernés.</p>
      <div class="mt-3 grid gap-6 lg:grid-cols-2">
        <ColumnChart :labels="buckets.map((b) => b.label)" :values="buckets.map((b) => b.couriers.length)" label="Livreurs" :color="TEAM" :dates="false" :height="220" aria-label="Nombre de livreurs par tranche de livraisons par jour" />
        <div class="min-w-0 space-y-2">
          <div class="flex flex-wrap gap-1.5" role="group" aria-label="Tranche de livraisons par jour">
            <button
              v-for="(b, i) in buckets" :key="b.label" type="button" :aria-pressed="i === bucket"
              :class="['rounded-full border px-3 py-1 text-xs font-semibold', i === bucket ? 'border-violet-700 bg-violet-700 text-white' : 'bg-white text-slate-700 hover:bg-slate-50']"
              @click="bucket = i"
            >{{ b.label }} · {{ b.couriers.length }}</button>
          </div>
          <p class="text-sm text-slate-600">
            <strong class="text-slate-900">{{ current.couriers.length }} livreur(s)</strong> à {{ current.label.toLowerCase() }} livraisons par jour
            <template v-if="current.couriers.length"> · temps moyen <strong class="text-slate-900">{{ minutes(mean(current.couriers, 'minutes')) }}</strong> · réussite <strong class="text-slate-900">{{ percent(mean(current.couriers, 'success_rate')) }}</strong></template>
          </p>
          <div class="max-h-80 overflow-auto">
            <table class="w-full text-sm">
              <thead class="sticky top-0 bg-slate-50 text-left text-slate-600"><tr><th class="p-2">Livreur</th><th class="p-2 text-right">Par jour</th><th class="p-2 text-right">Jours</th><th class="p-2 text-right">Temps</th><th class="p-2 text-right">Réussite</th></tr></thead>
              <tbody class="divide-y">
                <tr v-for="c in current.couriers" :key="c.id">
                  <td class="p-2 font-medium"><RouterLink :to="`/admin/livreurs/${c.id}`" class="hover:underline">{{ c.name }}</RouterLink></td>
                  <td class="p-2 text-right">{{ number(c.per_day) }}</td><td class="p-2 text-right">{{ c.days_worked }}</td>
                  <td class="p-2 text-right whitespace-nowrap">{{ minutes(c.minutes) }}</td><td class="p-2 text-right">{{ percent(c.success_rate) }}</td>
                </tr>
                <tr v-if="!current.couriers.length"><td colspan="5" class="p-3 text-slate-600">Aucun livreur dans cette tranche.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>

    <section class="card overflow-x-auto">
      <div class="flex flex-wrap items-end justify-between gap-2 p-4 pb-2">
        <div>
          <h2 class="font-semibold">Livreurs</h2>
          <p class="text-xs text-slate-600">
            À féliciter : au moins {{ data.thresholds.success_good }} % de réussite et une productivité au-dessus de la moyenne.
            À accompagner : moins de {{ data.thresholds.success_low }} % de réussite, un écart de caisse, ou moitié moins de livraisons que la moyenne.
          </p>
        </div>
        <select v-model="filter" class="input w-auto" aria-label="Livreurs affichés">
          <option value="">Tous ({{ data.couriers.length }})</option>
          <option v-for="(v, key) in VERDICTS" :key="key" :value="key">{{ v.label }} ({{ data.couriers.filter((c) => c.verdict === key).length }})</option>
        </select>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr>
            <th class="p-2 pl-4">Livreur</th><th class="p-2 text-right">Livrées</th><th class="p-2 text-right">Par jour</th><th class="p-2 text-right">Temps par mission</th>
            <th class="p-2 text-right">Réussite</th><th class="p-2 text-right">Note</th><th class="p-2 text-right">Retard au pointage</th><th class="p-2 text-right">Absences</th>
            <th class="p-2 text-right">Écart de caisse</th><th class="p-2 text-right">Paie par course</th><th class="p-2 pr-4">Situation</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="c in shown" :key="c.id">
            <td class="p-2 pl-4 font-medium"><RouterLink :to="`/admin/livreurs/${c.id}`" class="hover:underline">{{ c.name }}</RouterLink></td>
            <td class="p-2 text-right">{{ number(c.delivered) }}</td>
            <td class="p-2 text-right">{{ number(c.per_day) }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ minutes(c.minutes) }}</td>
            <td class="p-2 text-right font-semibold" :class="c.success_rate !== null && c.success_rate < data.thresholds.success_low ? 'text-red-600' : ''">{{ percent(c.success_rate) }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ c.rating === null ? '—' : `${c.rating.toLocaleString('fr-FR')} / 5` }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ minutes(c.late_minutes) }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ c.absence_days ? `${c.absence_days} j` : '—' }}</td>
            <td class="p-2 text-right whitespace-nowrap" :class="c.cash_gap < 0 ? 'font-semibold text-red-600' : ''">{{ c.cash_gap ? money(c.cash_gap) : '—' }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ money(c.pay_per_delivery) }}</td>
            <td class="p-2 pr-4"><span v-if="c.verdict" :class="['rounded-full border px-2 py-0.5 text-xs font-semibold whitespace-nowrap', VERDICTS[c.verdict].chip]">{{ VERDICTS[c.verdict].label }}</span><span v-else class="text-xs text-slate-600">Trop peu de courses</span></td>
          </tr>
          <tr v-if="!shown.length"><td colspan="11" class="p-4 text-slate-600">Aucun livreur.</td></tr>
        </tbody>
      </table>
    </section>

    <div class="grid gap-4 lg:grid-cols-5">
      <section class="card p-4 lg:col-span-3">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <div>
            <h2 class="font-semibold">Paie des livreurs par mois</h2>
            <p class="text-xs text-slate-600">6 derniers mois, mois en cours compris</p>
          </div>
          <ul class="flex flex-wrap gap-3 text-xs text-slate-600">
            <li v-for="g in PAY" :key="g.key" class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: g.color }" />{{ g.label }}</li>
          </ul>
        </div>
        <StackedColumns class="mt-3" :labels="data.pay_months.map((m) => m.label)" :series="paySeries" :format="(v, full) => (full ? money(v) : compact(v))" aria-label="Paie des livreurs par mois et par type" />
      </section>
      <section class="card p-4 lg:col-span-2">
        <h2 class="font-semibold">Véhicules</h2>
        <p class="text-xs text-slate-600">Coûts payés par l'entreprise sur la période</p>
        <dl class="mt-2 divide-y text-sm">
          <div v-for="row in vehicles" :key="row.label" class="flex justify-between gap-3 py-2"><dt class="text-slate-600">{{ row.label }}</dt><dd class="font-semibold whitespace-nowrap" :class="row.alert ? 'text-amber-800' : ''">{{ row.value }}</dd></div>
        </dl>
      </section>
    </div>

    <section class="card overflow-x-auto">
      <div class="p-4 pb-2">
        <h2 class="font-semibold">Agents du bureau</h2>
        <p class="text-xs text-slate-600">Ce que chacun a traité sur la période. Délai d'affectation : entre la création d'une course et sa première affectation.</p>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr>
            <th class="p-2 pl-4">Agent</th><th class="p-2">Rôle</th><th class="p-2 text-right">Missions affectées</th><th class="p-2 text-right">Délai d'affectation</th>
            <th class="p-2 text-right">Versements reçus</th><th class="p-2 text-right">Écarts des versements</th><th class="p-2 text-right">Clôtures de caisse</th><th class="p-2 pr-4 text-right">Colis réceptionnés</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="s in data.staff" :key="s.id">
            <td class="p-2 pl-4 font-medium">{{ s.name }}</td><td class="p-2 text-slate-600">{{ s.role || '—' }}</td>
            <td class="p-2 text-right">{{ s.assigned ? number(s.assigned) : '—' }}</td>
            <td class="p-2 text-right whitespace-nowrap" :class="s.assign_minutes > 30 ? 'font-semibold text-amber-800' : ''">{{ minutes(s.assign_minutes) }}</td>
            <td class="p-2 text-right">{{ s.remittances ? number(s.remittances) : '—' }}</td>
            <td class="p-2 text-right whitespace-nowrap" :class="s.gap < 0 ? 'font-semibold text-red-600' : ''">{{ s.gap ? money(s.gap) : '—' }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ s.closings ? `${s.closings}${s.closing_gap ? ` · écart ${money(s.closing_gap)}` : ''}` : '—' }}</td>
            <td class="p-2 pr-4 text-right">{{ s.parcels ? number(s.parcels) : '—' }}</td>
          </tr>
          <tr v-if="!data.staff.length"><td colspan="8" class="p-4 text-slate-600">Aucune action du bureau enregistrée sur la période.</td></tr>
        </tbody>
      </table>
    </section>

    <DecisionList :decisions="data.decisions" />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import ColumnChart from '../stats/ColumnChart.vue'
import KpiTile from '../stats/KpiTile.vue'
import StackedColumns from '../stats/StackedColumns.vue'
import { compact } from '../stats/chart'
import { money } from '../../utils/format'
import DecisionList from './DecisionList.vue'
import SignedBars from './SignedBars.vue'
import { TONES, minutes, number, percent } from './shared'

const props = defineProps({ data: { type: Object, required: true } })
const k = computed(() => props.data.kpis)

// Les équipes ont leur couleur (violet), en trois nuances pour les étapes et les types de paie
const TEAM = '#6a4ce2'
const STEPS = [
  { key: 'accept', label: 'Acceptation', color: '#b3a6f2', ink: '#1b1340' },
  { key: 'wait', label: 'Attente avant le départ', color: TEAM, ink: '#fff' },
  { key: 'run', label: 'Trajet et remise', color: '#3f2a9c', ink: '#fff' },
]
const PAY = [
  { key: 'course', label: 'À la course', color: TEAM },
  { key: 'fixed', label: 'Fixe et journées MAD', color: '#b3a6f2' },
  { key: 'other', label: 'Primes, indemnités et retenues', color: '#3f2a9c' },
]
const LOADS = [{ label: 'Surcharge', color: TONES.warn }, { label: 'Normal', color: TEAM }, { label: 'Sous-charge', color: TONES.muted }]
const VERDICTS = {
  praise: { label: 'À féliciter', chip: 'border-emerald-200 bg-emerald-50 text-emerald-700' },
  average: { label: 'Dans la moyenne', chip: 'border-blue-200 bg-blue-50 text-blue-800' },
  support: { label: 'À accompagner', chip: 'border-red-200 bg-red-50 text-red-700' },
}
// Six tranches de même largeur, adaptées au volume de l'entreprise (pas de 1, 2, 3, 5, 10…)
const ranges = computed(() => {
  const max = Math.max(0, ...props.data.couriers.map((c) => c.per_day ?? 0))
  const step = [1, 2, 3, 5, 10, 20, 50].find((s) => s * 6 > max) || 100
  return [0, 1, 2, 3, 4, 5].map((i) => (i === 5 ? [`${i * step} et plus`, i * step, Infinity] : [i ? `${i * step} à ${(i + 1) * step}` : `Moins de ${step}`, i * step, (i + 1) * step]))
})

const zones = computed(() => props.data.zones.rows.map((z) => ({
  label: z.name, value: z.per_courier_day ?? 0, color: z.level === 'high' ? TONES.warn : z.level === 'low' ? TONES.muted : TEAM,
  title: `${z.name} : ${z.deliveries} livraison(s), ${z.couriers} livreur(s)${z.minutes !== null ? `, ${z.minutes} min par livraison` : ''}`,
})))

const timeRows = computed(() => [{ label: 'Toutes', ...props.data.times }, ...props.data.times.by_activity].filter((r) => r.total !== null).map((r) => ({
  label: r.label, total: r.total,
  steps: STEPS.map((s) => ({ ...s, value: r[s.key] ?? 0 })).filter((s) => s.value > 0),
  title: STEPS.map((s) => `${s.label} : ${r[s.key] ?? '—'} min`).join(' · '),
})))
const maxTime = computed(() => Math.max(1, ...timeRows.value.map((r) => r.total)))

const buckets = computed(() => ranges.value.map(([label, lo, hi]) => ({
  label, couriers: props.data.couriers.filter((c) => c.per_day !== null && c.per_day >= lo && c.per_day < hi).sort((a, b) => a.per_day - b.per_day),
})))
// La première tranche qui compte des livreurs est ouverte
const bucket = ref(Math.max(0, buckets.value.findIndex((b) => b.couriers.length)))
const current = computed(() => buckets.value[bucket.value])
function mean(list, field) {
  const values = list.map((c) => c[field]).filter((v) => v !== null && v !== undefined)
  return values.length ? Math.round(values.reduce((a, b) => a + b, 0) / values.length) : null
}

const filter = ref('')
const ORDER = { support: 0, praise: 1, average: 2 }
const shown = computed(() => props.data.couriers.filter((c) => !filter.value || c.verdict === filter.value)
  .sort((a, b) => (ORDER[a.verdict] ?? 3) - (ORDER[b.verdict] ?? 3) || b.delivered - a.delivered))

const paySeries = computed(() => PAY.map((g) => ({ label: g.label, color: g.color, values: props.data.pay_months.map((m) => m[g.key]) })))
const vehicles = computed(() => {
  const v = props.data.vehicles
  return [
    { label: 'Véhicules du parc', value: number(v.count) },
    { label: 'Carburant, entretien, réparations', value: money(v.cost) },
    { label: 'Coût par course livrée', value: money(v.cost_per_delivery) },
    { label: 'Jours d\'immobilisation', value: number(v.immobilized_days) },
    { label: 'Papiers qui expirent sous 30 jours', value: number(v.documents_expiring), alert: v.documents_expiring > 0 },
    { label: 'Papiers expirés', value: number(v.documents_expired), alert: v.documents_expired > 0 },
  ]
})
</script>
