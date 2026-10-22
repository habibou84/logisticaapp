<template>
  <div class="space-y-4">
    <DispatchMessages show-order @loaded="(list) => (count = list.length)" />
    <EmptyState v-if="count === 0" icon="💬" title="Aucune consigne" text="Les messages de l'agence sur vos courses s'afficheront ici." />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import DispatchMessages from '../../components/mobile/DispatchMessages.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'

const count = ref(null)
</script>
