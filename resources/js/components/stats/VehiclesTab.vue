<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Coût du parc" kind="money" :value="t.cost" :hint="t.cost_per_course ? `${money(t.cost_per_course)} par course (${t.courses} courses)` : ''" />
      <KpiTile title="Carburant" kind="money" :value="t.fuel" :hint="`${t.liters} litres`" />
      <KpiTile title="Entretien et réparations" kind="money" :value="t.maintenance" :hint="`${t.interventions} intervention(s)`" />
      <KpiTile title="Jours d'immobilisation" :value="t.immobilized_days" hint="Entretien, réparations, sinistres" :higher-is-better="false" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card p-4">
        <h2 class="font-semibold">Coût par course, par véhicule</h2>
        <p class="text-xs text-slate-500"><span class="text-amber-500">■</span> carburant <span class="ml-2 text-sky-500">■</span> entretien <span class="ml-2 text-red-500">■</span> réparations, sinistres <span class="ml-2 text-slate-400">■</span> autres</p>
        <ul class="mt-3 space-y-3">
          <li v-for="v in data.vehicles" :key="v.id" class="text-sm">
            <div class="flex justify-between"><RouterLink :to="`/admin/vehicules/${v.id}`"><b class="font-mono">{{ v.plate }}</b> · {{ v.name || VEHICLE_TYPES[v.type]?.label }}</RouterLink>
              <span><b>{{ v.cost_per_course ? money(v.cost_per_course) : '—' }}</b> <span class="text-slate-400">/ course</span></span></div>
            <div class="mt-1 flex h-3 overflow-hidden rounded bg-slate-100">
              <div v-for="part in parts(v)" :key="part.key" :class="part.color" :style="{ width: `${part.width}%` }" :title="`${part.label} : ${money(part.value)}`" />
            </div>
          </li>
          <li v-if="!data.vehicles.length" class="text-slate-500">Aucun véhicule.</li>
        </ul>
      </section>

      <section class="card overflow-x-auto p-4">
        <h2 class="font-semibold">Distance et consommation</h2>
        <table class="mt-2 w-full text-left text-sm">
          <thead class="text-xs uppercase text-slate-500"><tr><th class="p-2">Véhicule</th><th class="p-2">Livreur</th><th class="p-2">Compteur</th><th class="p-2">GPS</th><th class="p-2">Conso.</th><th class="p-2" /></tr></thead>
          <tr v-for="v in data.vehicles" :key="v.id" class="border-t border-slate-100">
            <td class="p-2 whitespace-nowrap font-mono">{{ v.plate }}</td>
            <td class="p-2">{{ v.courier || '—' }}</td>
            <td class="p-2 whitespace-nowrap">{{ v.km ? km(v.km) : '—' }}</td>
            <td class="p-2 whitespace-nowrap">{{ v.gps_km ? km(Math.round(v.gps_km)) : '—' }}<span v-if="v.gps_alert" class="ml-1 rounded-full bg-red-100 px-1.5 text-xs text-red-800">{{ Math.abs(v.gps_gap) > 500 ? (v.gps_gap > 0 ? '> +500' : '< -500') : `${v.gps_gap > 0 ? '+' : ''}${v.gps_gap}` }} %</span></td>
            <td class="p-2 whitespace-nowrap">{{ v.consumption ? `${String(v.consumption).replace('.', ',')} L/100` : '—' }}</td>
            <td class="p-2"><span v-if="v.consumption_alert" class="whitespace-nowrap rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800">+{{ v.consumption_gap }} % : à vérifier</span>
              <span v-else-if="v.consumption" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">normal</span></td>
          </tr>
        </table>
        <p class="mt-2 text-xs text-slate-500">
          Compteur : relevés des états des lieux, des pleins et du carnet, comparés au trajet GPS de l'application (écart signalé au-delà de {{ data.gps_gap_percent }} %).
          Consommation comparée à celle du véhicule les 90 jours d'avant (alerte au-delà de {{ data.fuel_anomaly_percent }} %).
        </p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import KpiTile from './KpiTile.vue'
import { money } from '../../utils/format'
import { VEHICLE_TYPES, km } from '../../utils/vehicles'

// Statistiques › Véhicules : coûts du parc, coût par course, distance et consommation
const props = defineProps({ data: { type: Object, required: true } })
const t = computed(() => props.data.totals)
const max = computed(() => Math.max(1, ...props.data.vehicles.map((v) => v.costs.total)))

function parts(v) {
  const c = v.costs
  return [
    { key: 'fuel', label: 'Carburant', value: c.fuel, color: 'bg-amber-500' },
    { key: 'maintenance', label: 'Entretien', value: c.maintenance, color: 'bg-sky-500' },
    { key: 'repair', label: 'Réparations, sinistres', value: c.repair + c.claim, color: 'bg-red-500' },
    { key: 'other', label: 'Autres', value: c.fine + c.other + c.documents, color: 'bg-slate-400' },
  ].filter((p) => p.value > 0).map((p) => ({ ...p, width: (p.value / max.value) * 100 }))
}
</script>
