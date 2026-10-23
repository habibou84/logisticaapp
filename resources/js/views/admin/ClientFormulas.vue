<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Tarifs distribution</h1>
    <p class="text-sm text-gray-600">
      Grille commune des villes de l'intérieur et de la distribution à l'unité, et prix négociés de certains clients, qui la
      remplacent pour eux. Le contrat de chaque client (forfait, zones couvertes) se règle dans Distribution et MAD › Contrats.
    </p>
    <ClientRates :initial-merchant-id="route.query.client || null" />
  </div>
</template>

<script setup>
import { useRoute } from 'vue-router'
import ClientRates from '../../components/ClientRates.vue'

const route = useRoute()
</script>
