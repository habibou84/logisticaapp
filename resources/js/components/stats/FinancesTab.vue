<template>
  <!-- Trésorerie : l'argent en circulation aujourd'hui (le compte de résultat complet est dans Rentabilité) -->
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Chez les livreurs" kind="money" :value="data.circulation.with_couriers" hint="à verser à la caisse" />
      <KpiTile title="À reverser aux clients" kind="money" :value="data.circulation.to_merchants" :hint="`dont ${money(data.circulation.to_merchants_available)} déjà en caisse`" />
      <KpiTile title="Dû par des clients" kind="money" :value="data.circulation.owed_by_merchants" :higher-is-better="false" />
      <KpiTile title="Frais refacturés sur la période" kind="money" :value="data.rebilled" hint="avancés puis refacturés : ni revenu ni dépense" />
    </div>
    <!-- Activité facturée au mois : ce que les clients doivent encore sur leurs factures -->
    <section v-if="data.invoices" class="card space-y-3 p-4">
      <div>
        <h2 class="font-semibold">Factures à encaisser</h2>
        <p class="text-sm text-slate-600">Reste dû aujourd'hui : {{ money(data.invoices.outstanding) }}<template v-if="data.invoices.average_days !== null"> · les clients paient en {{ data.invoices.average_days }} jours en moyenne</template></p>
      </div>
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
        <div v-for="b in BUCKETS" :key="b.key" class="rounded-lg border p-3">
          <p class="text-xs text-slate-600">{{ b.label }}</p>
          <p class="font-bold" :class="b.late && data.invoices.buckets[b.key] ? 'text-amber-800' : ''">{{ money(data.invoices.buckets[b.key]) }}</p>
        </div>
      </div>
    </section>
    <section class="card flex flex-wrap items-center justify-between gap-3 p-4">
      <p class="text-sm text-slate-600">Encaisser, verser, reverser : tout se fait dans la caisse. Le compte de résultat (revenus, coûts, frais fixes, marge) est dans <b>Argent › Rentabilité</b>.</p>
      <RouterLink to="/admin/caisse" class="btn-secondary">Ouvrir la caisse →</RouterLink>
    </section>
  </div>
</template>

<script setup>
import { RouterLink } from 'vue-router'
import { money } from '../../utils/format'
import KpiTile from './KpiTile.vue'

defineProps({
  data: { type: Object, required: true },
})

const BUCKETS = [
  { key: 'not_due', label: 'Pas encore échu' }, { key: '0_30', label: '1 à 30 jours' }, { key: '31_60', label: '31 à 60 jours', late: true },
  { key: '61_90', label: '61 à 90 jours', late: true }, { key: 'over_90', label: 'Plus de 90 jours', late: true },
]
</script>
