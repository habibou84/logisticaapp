<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Tarifs distribution</h1>
    <p class="text-sm text-gray-600">
      Tarifs des villes de l'intérieur (courses au-delà du forfait, ou toutes si le contrat ne compte pas l'intérieur)
      et de la distribution à l'unité (clients sans contrat). Le contrat de chaque client se règle dans Distribution › Contrats.
    </p>
    <ClientRates />
  </div>
</template>

<script setup>
import ClientRates from '../../components/ClientRates.vue'
</script>
