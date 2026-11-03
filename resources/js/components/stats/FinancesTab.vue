<template>
  <!-- Trésorerie : l'argent en circulation aujourd'hui (le compte de résultat complet est dans Rentabilité) -->
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Chez les livreurs" kind="money" :value="data.circulation.with_couriers" hint="à verser à la caisse" />
      <KpiTile title="À reverser aux clients" kind="money" :value="data.circulation.to_merchants" :hint="`dont ${money(data.circulation.to_merchants_available)} déjà en caisse`" />
      <KpiTile title="Dû par des clients" kind="money" :value="data.circulation.owed_by_merchants" :higher-is-better="false" />
      <KpiTile title="Frais refacturés sur la période" kind="money" :value="data.rebilled" hint="avancés puis refacturés : ni revenu ni dépense" />
    </div>
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
</script>
