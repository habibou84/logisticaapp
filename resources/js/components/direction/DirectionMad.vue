<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Chiffre d'affaires" :value="k.revenue" :previous="k.previous_revenue" kind="money" />
      <KpiTile title="Marge nette (estimée)" :value="k.margin" :previous="k.previous_margin" kind="money" />
      <KpiTile title="Forfaits du mois" :value="k.monthly_fees" kind="money" :hint="`${k.contracts} contrat(s), ${k.couriers} livreur(s) mis à disposition`" />
      <KpiTile title="Marge par livreur mis à disposition" :value="k.margin_per_courier" kind="money" hint="Sur la période" />
      <KpiTile title="Présence" :value="k.presence" kind="percent" hint="Jours faits sur jours prévus" />
      <KpiTile title="Retard moyen au pointage" :value="k.late_minutes" :hint="k.late_minutes === null ? 'Aucun pointage sur la période' : 'minutes'" />
      <KpiTile title="Courses au-delà des forfaits" :value="k.over_forfait" hint="Par mois, tous contrats" />
      <KpiTile title="Contrats à renouveler" :value="k.to_renew" hint="Échéance sous 60 jours" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <div>
            <h2 class="font-semibold">Consommation du forfait par contrat</h2>
            <p class="text-xs text-slate-600">Courses par mois sur courses incluses. Trait plein : forfait. Pointillés : seuils réglés ({{ data.thresholds.forfait_low }} % et {{ data.thresholds.forfait_high }} %)</p>
          </div>
        </div>
        <SignedBars
          class="mt-3" :rows="usage" :color="COLORS.mad" :format="(v) => `${v} %`" :at-least="120" label-width="9rem" value-width="4rem"
          :marks="usageMarks" empty="Aucun contrat avec un forfait de courses."
        />
      </section>
      <section class="card p-4">
        <h2 class="font-semibold">Présence par contrat</h2>
        <p class="text-xs text-slate-600">Jours faits sur jours prévus, absences déduites. Pointillés : 90 %</p>
        <SignedBars
          class="mt-3" :rows="presence" :color="COLORS.mad" :format="percent" :at-least="100" label-width="9rem" value-width="4rem"
          :marks="[{ value: 90, label: 'Seuil de 90 %', dashed: true }]" empty="Aucun jour prévu sur la période."
        />
      </section>
    </div>

    <section class="card overflow-x-auto">
      <div class="p-4 pb-2">
        <h2 class="font-semibold">Contrats MAD</h2>
        <p class="text-xs text-slate-600">Marge : prix du contrat moins la paie et le véhicule des livreurs mis à disposition</p>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr>
            <th class="p-2 pl-4">Client</th><th class="p-2 text-right">Livreurs</th><th class="p-2 text-right">Forfait du mois</th><th class="p-2 text-right">Courses par mois</th>
            <th class="p-2">Consommation</th><th class="p-2">Situation</th><th class="p-2 text-right">Présence</th><th class="p-2 text-right">Retard moyen</th>
            <th class="p-2 text-right">Marge</th><th class="p-2 text-right">Taux</th><th class="p-2 pr-4">Échéance</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="c in data.contracts" :key="c.id">
            <td class="p-2 pl-4 font-medium" :title="c.courier_names.join(', ')">{{ c.merchant }}</td>
            <td class="p-2 text-right">{{ c.couriers }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ money(c.monthly_fee) }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ number(c.used_per_month) }}{{ c.included ? ` / ${number(c.included)}` : '' }}</td>
            <td class="p-2"><Meter v-if="c.usage !== null" :ratio="c.usage / 100" :mark="1" :scale="1.5" :color="USAGE[c.verdict]?.color || COLORS.mad" /><span v-else class="text-slate-600">—</span></td>
            <td class="p-2"><span v-if="c.verdict" :class="['rounded-full border px-2 py-0.5 text-xs font-semibold whitespace-nowrap', USAGE[c.verdict].chip]">{{ USAGE[c.verdict].label }} · {{ c.usage }} %</span></td>
            <td class="p-2 text-right" :class="c.presence !== null && c.presence < 90 ? 'font-semibold text-red-600' : ''">{{ percent(c.presence) }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ minutes(c.late_minutes) }}</td>
            <td class="p-2 text-right whitespace-nowrap" :class="c.margin < 0 ? 'font-semibold text-red-600' : ''">{{ money(c.margin) }}</td>
            <td class="p-2 text-right font-semibold">{{ percent(c.margin_rate) }}</td>
            <td class="p-2 pr-4 whitespace-nowrap">
              <span v-if="c.renew" class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">{{ date(c.ends_on) }}</span>
              <template v-else>{{ c.ends_on ? date(c.ends_on) : 'Sans échéance' }}</template>
            </td>
          </tr>
          <tr v-if="!data.contracts.length"><td colspan="11" class="p-4 text-slate-600">Aucun contrat MAD en cours sur la période.</td></tr>
        </tbody>
      </table>
    </section>

    <section class="card p-4">
      <h2 class="font-semibold">Chiffre d'affaires MAD par mois</h2>
      <p class="text-xs text-slate-600">12 derniers mois, mois en cours compris</p>
      <ColumnChart class="mt-3" :labels="data.months.map((m) => m.label)" :values="data.months.map((m) => m.revenue)" label="Chiffre d'affaires" :color="COLORS.mad" :dates="false" :format="(v, full) => (full ? money(v) : compact(v))" aria-label="Chiffre d'affaires MAD par mois" />
    </section>

    <DecisionList :decisions="data.decisions" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import ColumnChart from '../stats/ColumnChart.vue'
import KpiTile from '../stats/KpiTile.vue'
import { compact } from '../stats/chart'
import { date, money } from '../../utils/format'
import DecisionList from './DecisionList.vue'
import Meter from './Meter.vue'
import SignedBars from './SignedBars.vue'
import { COLORS, TONES, USAGE, minutes, number, percent } from './shared'

const props = defineProps({ data: { type: Object, required: true } })
const k = computed(() => props.data.kpis)

const usage = computed(() => props.data.contracts.filter((c) => c.usage !== null).map((c) => ({
  label: c.merchant, value: c.usage, color: USAGE[c.verdict]?.color || COLORS.mad,
  title: `${c.merchant} : ${c.used_per_month} course(s) par mois pour ${c.included} incluses (${c.usage} %)`,
})))
const usageMarks = computed(() => [
  { value: 100, label: 'Forfait' },
  { value: props.data.thresholds.forfait_low, label: `Sous-utilisé sous ${props.data.thresholds.forfait_low} %`, dashed: true },
  ...(props.data.thresholds.forfait_high > 100 ? [{ value: props.data.thresholds.forfait_high, label: `À revoir au-delà de ${props.data.thresholds.forfait_high} %`, dashed: true }] : []),
])
const presence = computed(() => props.data.contracts.filter((c) => c.presence !== null).map((c) => ({
  label: c.merchant, value: c.presence, color: c.presence < 90 ? TONES.bad : COLORS.mad,
  title: `${c.merchant} : ${c.done_days} jour(s) faits sur ${c.planned_days} prévus`,
})).sort((a, b) => a.value - b.value))
</script>
