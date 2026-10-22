<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Formules et tarifs des clients</h1>
    <p class="text-sm text-gray-600">
      Tarifs de la distribution à l'unité et des villes de l'intérieur. La formule de chaque client (contrat de distribution, MAD)
      se règle depuis Clients › « Formule ».
    </p>
    <ClientRates />
  </div>
</template>

<script setup>
import ClientRates from '../../components/ClientRates.vue'
</script>
