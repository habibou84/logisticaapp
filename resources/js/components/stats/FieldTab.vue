<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Événements signalés" :value="t.events" :hint="`${t.urgent} urgent(s) · ${t.blocking} bloquant(s)`" />
      <KpiTile title="Prise en charge" :text="t.take_minutes === null ? '—' : waitDuration(t.take_minutes)" hint="Délai moyen avant qu'un membre de l'équipe s'en occupe" />
      <KpiTile title="Résolution" :text="t.resolve_minutes === null ? '—' : waitDuration(t.resolve_minutes)" hint="Délai moyen jusqu'au règlement" />
      <KpiTile title="Immobilisation des livreurs" :text="t.immobilised_minutes ? waitDuration(t.immobilised_minutes) : '0'" :hint="t.cost ? `Dépannages déclarés : ${money(t.cost)}` : 'Temps sans pouvoir rouler'" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Par type</h2>
        <BarList :items="data.by_type" empty="Aucun événement sur la période. 👏" />
      </section>
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Zones à problème <span class="text-sm font-normal text-slate-500">· destination des courses touchées</span></h2>
        <BarList :items="data.by_zone" :color="SERIES[2]" empty="Aucune course touchée." />
      </section>
    </div>

    <section class="card overflow-x-auto">
      <h2 class="font-semibold p-4 pb-2">Livreurs les plus touchés</h2>
      <table class="w-full text-sm">
        <thead class="text-left text-slate-500">
          <tr><th class="p-2 pl-4">Livreur</th><th class="p-2">Événements</th><th class="p-2">Dont bloquants</th><th class="p-2">Immobilisation</th><th class="p-2">Dépannages</th><th class="p-2">Types</th></tr>
        </thead>
        <tbody>
          <tr v-for="c in data.by_courier" :key="c.id" class="border-t">
            <td class="p-2 pl-4 font-medium">{{ c.name }}</td>
            <td class="p-2">{{ c.count }}</td>
            <td class="p-2">{{ c.blocking }}</td>
            <td class="p-2">{{ c.immobilised_minutes ? waitDuration(c.immobilised_minutes) : '—' }}</td>
            <td class="p-2">{{ c.cost ? money(c.cost) : '—' }}</td>
            <td class="p-2 text-slate-600">{{ Object.entries(c.types).map(([k, n]) => `${k} (${n})`).join(', ') }}</td>
          </tr>
          <tr v-if="!data.by_courier.length"><td colspan="6" class="p-4 text-center text-slate-500">Aucun événement sur la période.</td></tr>
        </tbody>
      </table>
    </section>

    <p class="text-sm"><RouterLink to="/admin/terrain?onglet=events" class="text-blue-600">Voir les événements dans Remontées terrain →</RouterLink></p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import BarList from './BarList.vue'
import KpiTile from './KpiTile.vue'
import { SERIES } from './chart'
import { money, waitDuration } from '../../utils/format'

// Statistiques › Terrain : événements signalés par les livreurs (panne, accident, contrôle…)
const props = defineProps({ data: { type: Object, required: true } })
const t = computed(() => props.data.totals)
</script>
