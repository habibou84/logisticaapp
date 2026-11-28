<template>
  <!-- Jour par jour : courses créées et livrées, chiffre d'affaires, heures de pointe -->
  <div class="space-y-4">
    <section class="card space-y-2 p-4">
      <h2 class="font-semibold">Courses par jour</h2>
      <LineChart
        :labels="data.series.map((d) => d.date)"
        :series="[
          { label: 'Créées', color: SERIES[0], values: data.series.map((d) => d.created) },
          { label: 'Livrées', color: SERIES[1], values: data.series.map((d) => d.delivered) },
        ]"
        aria-label="Courses créées et livrées par jour"
      />
    </section>
    <div class="grid gap-4 lg:grid-cols-2">
      <section v-if="data.kpis.revenue !== null" class="card space-y-2 p-4">
        <h2 class="font-semibold">Chiffre d'affaires par jour <span class="text-sm font-normal text-slate-600">· frais de livraison des courses livrées</span></h2>
        <ColumnChart :labels="data.series.map((d) => d.date)" :values="data.series.map((d) => d.revenue)" label="Chiffre d'affaires" :format="(v, full) => (full ? money(v) : compact(v))" aria-label="Chiffre d'affaires par jour" />
      </section>
      <section class="card space-y-2 p-4">
        <h2 class="font-semibold">Heures de pointe <span class="text-sm font-normal text-slate-600">· courses créées</span></h2>
        <Heatmap :data="data.heatmap" aria-label="Courses créées par jour de la semaine et par heure" />
      </section>
    </div>
  </div>
</template>

<script setup>
import { money } from '../../utils/format'
import ColumnChart from './ColumnChart.vue'
import Heatmap from './Heatmap.vue'
import LineChart from './LineChart.vue'
import { SERIES, compact } from './chart'

defineProps({ data: { type: Object, required: true } })
</script>
