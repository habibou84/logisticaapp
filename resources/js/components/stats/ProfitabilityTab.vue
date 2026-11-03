<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Revenus" kind="money" :value="t.revenue" :hint="revenueHint" />
      <KpiTile title="Coûts directs" kind="money" :value="t.direct_costs" hint="paie, véhicules, frais, pertes" />
      <KpiTile title="Frais fixes" kind="money" :value="t.fixed_costs" hint="loyer, équipe… (au prorata)" />
      <KpiTile title="Marge nette" kind="money" :value="t.margin" :hint="t.margin_rate === null ? '' : `${t.margin_rate.toLocaleString('fr-FR')} % des revenus`" />
      <KpiTile title="Coût par course livrée" kind="money" :value="t.cost_per_delivery" :hint="`${t.delivered} courses livrées`" />
      <KpiTile title="Revenu par course" kind="money" :value="t.revenue_per_delivery" />
      <KpiTile title="Marge par course" kind="money" :value="t.margin_per_delivery" />
      <KpiTile title="Livraisons par livreur et par jour" :value="t.deliveries_per_courier_day" :hint="`${t.courier_days} journées de livreur`" />
    </div>

    <section class="card space-y-3 p-4">
      <h2 class="font-semibold">D'où vient la marge</h2>
      <ul class="space-y-2 text-sm">
        <li v-for="i in bars" :key="i.label">
          <div class="flex justify-between gap-2"><span class="text-slate-700">{{ i.label }}</span><span :class="['whitespace-nowrap font-semibold', i.cost ? 'text-red-700' : '']">{{ i.cost ? '− ' : '' }}{{ money(i.amount) }}</span></div>
          <div class="mt-1 h-2 rounded bg-slate-100"><div class="h-2 rounded" :style="{ width: `${scale ? Math.max(0, i.amount) / scale * 100 : 0}%`, background: i.cost ? SERIES[1] : SERIES[0] }" /></div>
        </li>
      </ul>
      <p :class="['rounded-lg px-3 py-2 font-semibold', t.margin >= 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700']">
        {{ t.margin >= 0 ? 'Bénéfice' : 'Perte' }} sur la période : {{ money(Math.abs(t.margin)) }}
      </p>
    </section>

    <div class="flex flex-wrap gap-2 text-sm" role="group" aria-label="Rentabilité par">
      <button
        v-for="v in VIEWS" :key="v.key" type="button"
        :class="['rounded-full px-3 py-1 ring-1', view === v.key ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
        :aria-pressed="view === v.key" @click="view = v.key"
      >
        {{ v.label }}
      </button>
    </div>

    <!-- Clients : rentables, à renégocier, à perte -->
    <template v-if="view === 'clients'">
      <div class="flex flex-wrap gap-2 text-sm">
        <span v-for="v in VERDICTS" :key="v.key" :class="['rounded-full px-3 py-1', v.class]">{{ v.label }} : {{ data.clients.filter((c) => c.verdict === v.key).length }}</span>
        <span class="text-xs text-slate-500 self-center">« À renégocier » : marge sous {{ data.settings.renegotiate_margin }} % (réglable dans Objectifs)</span>
      </div>
      <SortableTable title="Rentabilité par client" :columns="clientColumns" :rows="data.clients" initial-sort="margin" filename="rentabilite-clients.csv" empty="Aucun client sur la période.">
        <template #verdict="{ row }"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', verdict(row.verdict).class]">{{ verdict(row.verdict).label }}</span></template>
      </SortableTable>
    </template>

    <!-- Zones : avec simulation d'un nouveau tarif -->
    <template v-if="view === 'zones'">
      <SortableTable title="Rentabilité par zone de livraison" :columns="zoneColumns" :rows="zonesWithSimulation" initial-sort="margin" filename="rentabilite-zones.csv" empty="Aucune livraison sur la période.">
        <template #simulate="{ row }">
          <input
            v-if="row.id" type="number" min="0" step="100" class="input w-28 py-1 text-right" :aria-label="`Nouveau tarif moyen pour ${row.name}`"
            :placeholder="row.average_fee ?? ''" :value="simulation[row.id] ?? ''" @input="simulate(row.id, $event.target.value)"
          >
        </template>
      </SortableTable>
      <p class="text-xs text-slate-500">
        Simulation : tapez un nouveau tarif moyen pour une zone, la marge est recalculée avec le même nombre de livraisons.
        <template v-if="simulatedGain"> Effet sur la période : <b :class="simulatedGain > 0 ? 'text-emerald-700' : 'text-red-700'">{{ simulatedGain > 0 ? '+' : '' }}{{ money(simulatedGain) }}</b>.</template>
      </p>
    </template>

    <SortableTable v-if="view === 'couriers'" title="Coût et rendement par livreur" :columns="courierColumns" :rows="data.couriers" initial-sort="deliveries" filename="rentabilite-livreurs.csv" empty="Aucune mission sur la période." />

    <!-- Contrats : utilisation du forfait, MAD -->
    <template v-if="view === 'contracts'">
      <SortableTable title="Contrats et forfaits" :columns="contractColumns" :rows="data.contracts" initial-sort="usage" filename="contrats.csv" empty="Aucun contrat sur la période.">
        <template #verdict="{ row }">
          <span v-if="row.verdict" :class="['rounded-full px-2 py-0.5 text-xs font-semibold', CONTRACT[row.verdict].class]">{{ CONTRACT[row.verdict].label }}</span>
        </template>
      </SortableTable>
      <p class="text-xs text-slate-500">
        Forfait utilisé à plus de {{ data.settings.forfait_high }} % : à revoir à la hausse ; sous {{ data.settings.forfait_low }} % : sous-utilisé (risque de perdre le client).
        MAD : prix du contrat sur la période comparé au coût des livreurs mis à disposition (paie sans course et véhicule).
      </p>
    </template>

    <FixedCostsPanel v-if="view === 'fixed'" @changed="emit('reload')" />

    <p class="text-xs text-slate-500">
      Estimation : la paie sans course (fixe, primes, journées MAD) et les coûts des véhicules sont répartis sur les missions de chaque livreur ;
      les frais fixes au nombre de courses livrées. Revenus : frais e-commerce de la période, montant facturé des courses de distribution et de MAD livrées,
      forfaits au prorata des jours et lots commencés sur la période.
    </p>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { money } from '../../utils/format'
import KpiTile from './KpiTile.vue'
import SortableTable from './SortableTable.vue'
import FixedCostsPanel from './FixedCostsPanel.vue'
import { SERIES } from './chart'

const props = defineProps({ data: { type: Object, required: true } })
const emit = defineEmits(['reload'])
const t = computed(() => props.data.totals)

const VIEWS = [
  { key: 'clients', label: '🏪 Par client' },
  { key: 'zones', label: '📍 Par zone' },
  { key: 'couriers', label: '🛵 Par livreur' },
  { key: 'contracts', label: '📑 Contrats et forfaits' },
  { key: 'fixed', label: '🏢 Frais fixes' },
]
const view = ref('clients')
const VERDICTS = [
  { key: 'profitable', label: 'Rentable', class: 'bg-emerald-100 text-emerald-900' },
  { key: 'renegotiate', label: 'À renégocier', class: 'bg-amber-100 text-amber-900' },
  { key: 'loss', label: 'À perte', class: 'bg-red-100 text-red-900' },
]
const verdict = (key) => VERDICTS.find((v) => v.key === key) || VERDICTS[0]
const CONTRACT = {
  raise: { label: 'À revoir à la hausse', class: 'bg-amber-100 text-amber-900' },
  underused: { label: 'Sous-utilisé', class: 'bg-sky-100 text-sky-900' },
  ok: { label: 'Bien dimensionné', class: 'bg-emerald-100 text-emerald-900' },
}
const pct = (v) => (v === null || v === undefined ? '—' : `${v.toLocaleString('fr-FR')} %`)
const m = (v) => (v === null || v === undefined ? '—' : money(v))
const red = (key) => (r) => (r[key] < 0 ? 'font-semibold text-red-700' : '')

const revenueHint = computed(() => {
  const b = t.value.revenue_by_activity
  return [b.ecommerce && `e-commerce ${money(b.ecommerce)}`, b.distribution && `distribution ${money(b.distribution)}`, b.mad && `MAD ${money(b.mad)}`].filter(Boolean).join(' · ')
})
const bars = computed(() => [
  { label: 'Revenus', amount: t.value.revenue },
  { label: 'Paie des livreurs liée aux courses', amount: t.value.costs.courier_pay, cost: true },
  { label: 'Paie sans course (fixe, primes, MAD, indemnités)', amount: t.value.costs.courier_fixed, cost: true },
  { label: 'Véhicules (carburant, entretien, papiers)', amount: t.value.costs.vehicles, cost: true },
  { label: 'Frais de course à la charge de l\'entreprise', amount: t.value.costs.expenses, cost: true },
  { label: 'Colis perdus', amount: t.value.costs.losses, cost: true },
  { label: 'Frais fixes', amount: t.value.fixed_costs, cost: true },
].filter((i) => !i.cost || i.amount))
const scale = computed(() => Math.max(1, ...bars.value.map((i) => i.amount)))

const clientColumns = [
  { key: 'name', label: 'Client', asc: true },
  { key: 'courses', label: 'Livrées', align: 'right' },
  { key: 'revenue', label: 'Revenus', align: 'right', format: m },
  { key: 'direct_costs', label: 'Coûts directs', align: 'right', format: m },
  { key: 'fixed_share', label: 'Part frais fixes', align: 'right', format: m },
  { key: 'margin', label: 'Marge', align: 'right', format: m, class: red('margin'), asc: true },
  { key: 'margin_rate', label: 'Taux', align: 'right', format: pct },
  { key: 'margin_per_course', label: 'Par course', align: 'right', format: m },
  { key: 'verdict', label: 'Verdict' },
]
const zoneColumns = [
  { key: 'name', label: 'Zone', asc: true },
  { key: 'delivered', label: 'Livrées', align: 'right' },
  { key: 'average_fee', label: 'Tarif moyen', align: 'right', format: m },
  { key: 'cost_per_delivery', label: 'Coût par livraison', align: 'right', format: m },
  { key: 'revenue', label: 'Revenus', align: 'right', format: m },
  { key: 'margin', label: 'Marge', align: 'right', format: m, class: red('margin'), asc: true },
  { key: 'margin_rate', label: 'Taux', align: 'right', format: pct },
  { key: 'simulate', label: 'Nouveau tarif ?', align: 'right' },
  { key: 'simulated_margin', label: 'Marge simulée', align: 'right', format: m, class: red('simulated_margin') },
]
const courierColumns = [
  { key: 'name', label: 'Livreur', asc: true },
  { key: 'missions', label: 'Missions', align: 'right' },
  { key: 'deliveries', label: 'Livraisons', align: 'right' },
  { key: 'days_worked', label: 'Jours', align: 'right' },
  { key: 'per_day', label: 'Livraisons / jour', align: 'right' },
  { key: 'pay', label: 'Paie', align: 'right', format: m },
  { key: 'vehicle', label: 'Véhicule', align: 'right', format: m },
  { key: 'cost_per_mission', label: 'Coût / mission', align: 'right', format: m },
  { key: 'revenue', label: 'Revenus livrés', align: 'right', format: m },
  { key: 'margin', label: 'Marge', align: 'right', format: m, class: red('margin') },
]
const contractColumns = [
  { key: 'merchant', label: 'Client', asc: true },
  { key: 'label', label: 'Contrat' },
  { key: 'monthly_fee', label: 'Forfait / mois', align: 'right', format: m },
  { key: 'included', label: 'Inclus', align: 'right', format: (v) => v ?? '—' },
  { key: 'used_per_month', label: 'Utilisé / mois', align: 'right' },
  { key: 'usage', label: 'Utilisation', align: 'right', format: pct },
  { key: 'verdict', label: 'Verdict' },
  { key: 'revenue', label: 'Revenus', align: 'right', format: m },
  { key: 'mad_cost', label: 'Coût MAD', align: 'right', format: m },
  { key: 'mad_margin', label: 'Marge MAD', align: 'right', format: m, class: red('mad_margin') },
]

// Simulation : nouveau tarif moyen par zone
const simulation = reactive({})
function simulate(id, value) {
  if (value === '' || Number.isNaN(Number(value))) delete simulation[id]
  else simulation[id] = Number(value)
}
const zonesWithSimulation = computed(() => props.data.zones.map((z) => ({
  ...z,
  simulated_margin: z.id && simulation[z.id] !== undefined ? z.margin + (simulation[z.id] - (z.average_fee || 0)) * z.delivered : null,
})))
const simulatedGain = computed(() => zonesWithSimulation.value.reduce((sum, z) => sum + (z.simulated_margin === null ? 0 : z.simulated_margin - z.margin), 0))
</script>
