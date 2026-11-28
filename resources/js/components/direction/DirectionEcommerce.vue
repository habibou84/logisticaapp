<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Colis livrés" :value="k.delivered" :previous="k.previous_delivered" />
      <KpiTile title="Taux de livraison" :value="k.success_rate" :previous="k.previous_success_rate" kind="percent" />
      <KpiTile title="Taux de retour" :value="k.return_rate" :previous="k.previous_return_rate" kind="percent" :higher-is-better="false" />
      <KpiTile title="Chiffre d'affaires" :value="k.revenue" :previous="k.previous_revenue" kind="money" />
      <KpiTile title="Revenu par colis livré" :value="k.revenue_per_delivery" :previous="k.previous_revenue_per_delivery" kind="money" />
      <KpiTile title="Marge nette par colis (estimée)" :value="k.margin_per_delivery" :previous="k.previous_margin_per_delivery" kind="money" />
      <KpiTile title="Encaissé à la livraison" :value="k.collected" :previous="k.previous_collected" kind="money" />
      <KpiTile title="Encore chez les livreurs" :value="data.cash.with_couriers" kind="money" :hint="`${money(data.cash.to_merchants)} à reverser aux clients`" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card p-4">
        <h2 class="font-semibold">Parcours des colis</h2>
        <p class="text-xs text-slate-600">Nombre de colis à chaque étape, sur la période</p>
        <SignedBars class="mt-3" :rows="funnel" label-width="11.5rem" value-width="4.5rem" :format="(v) => v.toLocaleString('fr-FR')" />
        <p class="mt-3 border-l-2 border-slate-300 pl-3 text-sm text-slate-600">
          {{ percent(k.first_attempt_rate) }} des colis livrés le sont au premier passage. {{ data.failed_attempts.toLocaleString('fr-FR') }} tentative(s) ont échoué sur la période.
        </p>
      </section>
      <section class="card p-4">
        <h2 class="font-semibold">Motifs d'échec à la livraison</h2>
        <p class="text-xs text-slate-600">{{ data.failed_attempts.toLocaleString('fr-FR') }} tentative(s) échouée(s)</p>
        <BarList class="mt-3" :items="data.failure_reasons" :color="COLORS.ecommerce" empty="Aucun échec sur la période." />
      </section>
    </div>

    <section class="card p-4">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div>
          <h2 class="font-semibold">Marge nette par colis et par zone</h2>
          <p class="text-xs text-slate-600">Revenu moins paie du livreur, véhicule, frais de course et part des frais fixes (estimation)</p>
        </div>
        <ul class="flex gap-3 text-xs text-slate-600">
          <li class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: COLORS.ecommerce }" />Rentable</li>
          <li class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: TONES.bad }" />À perte</li>
        </ul>
      </div>
      <SignedBars class="mt-3" :rows="zones" :color="COLORS.ecommerce" :format="money" empty="Aucune zone livrée sur la période." />
    </section>

    <section class="card overflow-x-auto">
      <div class="p-4 pb-2">
        <h2 class="font-semibold">Clients principaux</h2>
        <p class="text-xs text-slate-600">{{ data.clients.length }} sur {{ data.clients_total }} client(s), du plus gros chiffre d'affaires au plus petit</p>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr>
            <th class="p-2 pl-4">Client</th><th class="p-2 text-right">Colis livrés</th><th class="p-2 text-right">Chiffre d'affaires</th><th class="p-2 text-right">Part</th>
            <th class="p-2 text-right">Réussite</th><th class="p-2 text-right">Retours</th><th class="p-2 text-right">Marge par colis</th><th class="p-2 pr-4">Situation</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="c in data.clients" :key="c.id">
            <td class="p-2 pl-4 font-medium"><RouterLink :to="{ path: '/admin/marchands', query: { id: c.id } }" class="hover:underline">{{ c.name }}</RouterLink></td>
            <td class="p-2 text-right">{{ number(c.courses) }}</td>
            <td class="p-2 text-right whitespace-nowrap">{{ money(c.revenue) }}</td>
            <td class="p-2 text-right">{{ percent(c.share) }}</td>
            <td class="p-2 text-right">{{ percent(c.success_rate) }}</td>
            <td class="p-2 text-right" :class="c.return_rate >= 15 ? 'font-semibold text-red-600' : ''">{{ percent(c.return_rate) }}</td>
            <td class="p-2 text-right whitespace-nowrap" :class="c.margin < 0 ? 'font-semibold text-red-600' : ''">{{ money(c.margin_per_course) }}</td>
            <td class="p-2 pr-4"><span :class="['rounded-full border px-2 py-0.5 text-xs font-semibold whitespace-nowrap', VERDICTS[c.verdict].chip]">{{ VERDICTS[c.verdict].label }}</span></td>
          </tr>
          <tr v-if="!data.clients.length"><td colspan="8" class="p-4 text-slate-600">Aucun client facturé sur la période.</td></tr>
        </tbody>
      </table>
    </section>

    <section class="card p-4">
      <h2 class="font-semibold">Colis livrés par mois</h2>
      <p class="text-xs text-slate-600">12 derniers mois, mois en cours compris</p>
      <ColumnChart class="mt-3" :labels="data.months.map((m) => m.label)" :values="data.months.map((m) => m.delivered)" label="Colis livrés" :color="COLORS.ecommerce" :dates="false" aria-label="Colis livrés par mois" />
    </section>

    <DecisionList :decisions="data.decisions" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import BarList from '../stats/BarList.vue'
import ColumnChart from '../stats/ColumnChart.vue'
import KpiTile from '../stats/KpiTile.vue'
import { money } from '../../utils/format'
import DecisionList from './DecisionList.vue'
import SignedBars from './SignedBars.vue'
import { COLORS, TONES, number, percent, share } from './shared'

const props = defineProps({ data: { type: Object, required: true } })
const k = computed(() => props.data.kpis)

const VERDICTS = {
  loss: { label: 'À perte', chip: 'border-red-200 bg-red-50 text-red-700' },
  renegotiate: { label: 'À renégocier', chip: 'border-amber-200 bg-amber-50 text-amber-800' },
  profitable: { label: 'Rentable', chip: 'border-emerald-200 bg-emerald-50 text-emerald-700' },
}

const funnel = computed(() => {
  const created = props.data.funnel[0].count
  return props.data.funnel.map((f) => ({
    label: f.label, value: f.count, color: f.key === 'returned' ? TONES.bad : COLORS.ecommerce,
    title: `${f.label} : ${f.count.toLocaleString('fr-FR')}${f.key !== 'created' && created ? ` (${share(f.count, created)} % des colis créés)` : ''}`,
  }))
})
const zones = computed(() => props.data.zones.map((z) => ({
  label: z.name, value: z.margin_per_delivery,
  title: `${z.name} : ${money(z.margin_per_delivery)} par colis, ${z.delivered.toLocaleString('fr-FR')} colis livrés, marge ${money(z.margin)}`,
})))
</script>
