<template>
  <div class="space-y-4">
    <section class="card p-4">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div>
          <h2 class="font-semibold">Chiffre d'affaires : 12 mois passés et 3 mois à venir</h2>
          <p class="text-xs text-slate-600">Les mois marqués d'une étoile sont estimés : le mois en cours au rythme actuel, puis la tendance des derniers mois.</p>
        </div>
        <ul class="flex flex-wrap gap-3 text-xs text-slate-600">
          <li v-for="a in data.activities" :key="a.value" class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: COLORS[a.value] }" />{{ a.label }}</li>
        </ul>
      </div>
      <StackedColumns class="mt-3" :labels="labels" :series="series" :format="(v, full) => (full ? money(v) : compact(v))" aria-label="Chiffre d'affaires passé et prévu, par activité" />
    </section>

    <section class="card overflow-x-auto">
      <div class="p-4 pb-2">
        <h2 class="font-semibold">Prévision par activité</h2>
        <p class="text-xs text-slate-600">Jour {{ data.elapsed_days }} sur {{ data.days_in_month }} du mois en cours. Fourchette : environ 8 mois sur 10 devraient tomber dedans.</p>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr>
            <th class="p-2 pl-4">Activité</th>
            <th class="p-2 text-right">{{ first.current.label }} (à ce jour)</th>
            <th class="p-2 text-right">{{ first.current.label }} (estimé)</th>
            <th v-for="m in months" :key="m.month" class="p-2 text-right">{{ m.label }}</th>
            <th class="p-2 pr-4 text-right">Tendance par mois</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="a in data.activities" :key="a.value">
            <td class="p-2 pl-4 font-medium whitespace-nowrap"><span class="mr-2 inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: COLORS[a.value] }" />{{ a.label }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ money(a.current.revenue) }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ money(a.current.estimate) }}</td>
            <template v-if="a.forecast.length">
              <td v-for="f in a.forecast" :key="f.month" class="p-2 text-right whitespace-nowrap">
                <strong>{{ money(f.value) }}</strong>
                <div class="text-xs text-slate-600">{{ compact(f.low) }} à {{ compact(f.high) }}</div>
                <div v-if="f.signed" class="text-xs text-slate-600">dont {{ compact(f.signed) }} de forfaits signés</div>
              </td>
              <td class="p-2 pr-4 text-right whitespace-nowrap" :class="changeClass(a.trend)">{{ a.trend > 0 ? '▲ +' : a.trend < 0 ? '▼ −' : '= ' }}{{ money(Math.abs(a.trend)) }}</td>
            </template>
            <td v-else :colspan="months.length + 1" class="p-2 pr-4 text-slate-600">Pas assez d'historique : il faut {{ data.min_months }} mois terminés avec du chiffre d'affaires ({{ a.months_used }} pour l'instant).</td>
          </tr>
          <tr v-if="data.activities.length > 1 && total" class="font-semibold">
            <td class="p-2 pl-4">Total</td>
            <td class="p-2 text-right whitespace-nowrap">{{ money(total.revenue) }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ money(total.estimate) }}</td>
            <td v-for="f in total.forecast" :key="f.month" class="p-2 text-right whitespace-nowrap">{{ money(f.value) }}<div class="text-xs font-normal text-slate-600">{{ compact(f.low) }} à {{ compact(f.high) }}</div></td>
            <td class="p-2 pr-4" />
          </tr>
        </tbody>
      </table>
      <p class="p-4 pt-2 text-xs text-slate-600">
        Méthode : la tendance des mois terminés (6 au plus), prolongée sur 3 mois, sans effet de saison. MAD et distribution ne descendent jamais sous les forfaits des contrats déjà signés pour le mois.
        C'est une estimation pour préparer une décision, pas un engagement.
      </p>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import StackedColumns from '../stats/StackedColumns.vue'
import { compact } from '../stats/chart'
import { money } from '../../utils/format'
import { COLORS, changeClass } from './shared'

const props = defineProps({ data: { type: Object, required: true } })

const first = computed(() => props.data.activities[0])
// Les mois prévus : ceux de la première activité qui a une prévision
const months = computed(() => props.data.activities.find((a) => a.forecast.length)?.forecast.map((f) => ({ month: f.month, label: f.label })) ?? [])
const labels = computed(() => [...first.value.history.map((m) => m.label), `${first.value.current.label} *`, ...months.value.map((m) => `${m.label} *`)])
const series = computed(() => props.data.activities.map((a) => ({
  label: a.label, color: COLORS[a.value],
  values: [...a.history.map((m) => m.revenue), a.current.estimate, ...months.value.map((m) => a.forecast.find((f) => f.month === m.month)?.value ?? 0)],
})))
const sum = (field, pick) => props.data.activities.reduce((t, a) => t + (pick(a)?.[field] ?? 0), 0)
const total = computed(() => (months.value.length ? {
  revenue: sum('revenue', (a) => a.current), estimate: sum('estimate', (a) => a.current),
  forecast: months.value.map((m) => {
    const pick = (a) => a.forecast.find((f) => f.month === m.month)
    return { month: m.month, value: sum('value', pick), low: sum('low', pick), high: sum('high', pick) }
  }),
} : null))
</script>
