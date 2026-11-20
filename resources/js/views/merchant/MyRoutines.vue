<template>
  <!-- MAD : parcours des livreurs attitrés (routine du matin avant d'arriver chez le client), écrits par le client -->
  <div class="m-card p-4">
    <RoutineList list-url="/merchant/routines" create-url="/merchant/routines" update-url="/merchant/routines" mobile />
  </div>
</template>

<script setup>
import RoutineList from '../../components/routines/RoutineList.vue'
</script>
