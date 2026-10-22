<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Communes desservies" :value="data.totals.zones" hint="avec au moins une course sur la période" />
      <KpiTile title="Courses livrées" :value="data.totals.delivered" />
      <KpiTile title="Part de l'expédition" kind="percent" :value="data.totals.shipping_share" hint="Livraisons vers l'intérieur (gares, transporteurs)" />
      <KpiTile title="Commune la plus lente" :text="slowest.name" :hint="slowest.hint" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Courses livrées par commune</h2>
        <BarList :items="byDelivered" empty="Aucune livraison sur la période." />
      </section>
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Échecs par commune <span class="text-sm font-normal text-slate-500">· passages ratés</span></h2>
        <BarList :items="byFailed" :color="SERIES[1]" empty="Aucun échec sur la période. 👏" />
      </section>
    </div>

    <SortableTable :exportable="exportable" title="Détail par commune" :columns="columns" :rows="data.zones" initial-sort="delivered" :filename="`zones_${period}.csv`">
      <template #name="{ row }">
        <span class="font-medium">{{ row.name }}</span>
        <span v-if="row.is_shipping" class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">expédition</span>
      </template>
      <template #success_rate="{ row }">
        <span v-if="row.success_rate !== null" :class="row.success_rate >= 90 ? 'text-emerald-700' : row.success_rate < 75 ? 'text-red-600' : ''">{{ row.success_rate }} %</span>
        <span v-else>—</span>
      </template>
    </SortableTable>
    <p class="text-xs text-slate-500">Les quartiers comptent dans leur commune. Réussite : livrées / (livrées + échecs). Délai : médiane entre le ramassage et la livraison. Frais moyen : à comparer avec votre grille de tarifs pour repérer les zones sous-payées.</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { money, waitDuration } from '../../utils/format'
import BarList from './BarList.vue'
import KpiTile from './KpiTile.vue'
import SortableTable from './SortableTable.vue'
import { SERIES } from './chart'

const props = defineProps({
  data: { type: Object, required: true },
  withAmounts: { type: Boolean, default: false },
  exportable: { type: Boolean, default: true },
})

const period = computed(() => `${props.data.period.from}_${props.data.period.to}`)
const duration = (m) => (m === null || m === undefined ? '—' : waitDuration(m))

const byDelivered = computed(() => props.data.zones.filter((z) => z.delivered).slice(0, 10).map((z) => ({ label: z.name, count: z.delivered })))
const byFailed = computed(() => [...props.data.zones].filter((z) => z.failed).sort((a, b) => b.failed - a.failed).slice(0, 10).map((z) => ({ label: z.name, count: z.failed })))

// Zone la plus lente, parmi celles qui ont au moins 5 livraisons (sinon le chiffre ne veut rien dire)
const slowest = computed(() => {
  const zone = props.data.zones.filter((z) => z.delivered >= 5 && z.delivery_minutes !== null).sort((a, b) => b.delivery_minutes - a.delivery_minutes)[0]
  return zone
    ? { name: zone.name, hint: `${duration(zone.delivery_minutes)} entre ramassage et livraison` }
    : { name: '—', hint: 'Pas encore assez de livraisons (5 par commune)' }
})

const columns = computed(() => [
  { key: 'name', label: 'Commune', asc: true },
  { key: 'created', label: 'Créées', align: 'right' },
  { key: 'delivered', label: 'Livrées', align: 'right' },
  { key: 'failed', label: 'Échecs', align: 'right' },
  { key: 'returned', label: 'Retournées', align: 'right' },
  { key: 'success_rate', label: 'Réussite', align: 'right' },
  { key: 'delivery_minutes', label: 'Délai', align: 'right', asc: true, format: duration },
  ...(props.withAmounts
    ? [
        { key: 'average_fee', label: 'Frais moyen', align: 'right', format: money },
        { key: 'revenue', label: 'Chiffre d\'affaires', align: 'right', format: money },
      ]
    : []),
])
</script>
