<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-bold">Frais fixes</h1>
      <p class="text-sm text-slate-600">Loyer, salaires de l'équipe, téléphone, assurances… Ils sont comptés dans la marge nette des statistiques, au prorata des jours de la période.</p>
    </div>
    <FixedCostsPanel />
  </div>
</template>

<script setup>
import FixedCostsPanel from '../../components/stats/FixedCostsPanel.vue'
</script>
