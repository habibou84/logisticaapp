<template>
  <!-- Pendant le chargement : la forme des courses à venir, plutôt qu'un écran vide -->
  <div class="space-y-2" aria-busy="true" aria-label="Chargement">
    <div v-for="i in rows" :key="i" class="m-card flex items-center gap-3 p-4">
      <span class="skeleton h-10 w-10 shrink-0 rounded-xl" />
      <span class="flex-1 space-y-2">
        <span class="skeleton block h-3.5 w-2/3" />
        <span class="skeleton block h-3 w-1/2" />
        <span class="skeleton block h-3 w-3/5" />
      </span>
      <span class="skeleton h-4 w-14" />
    </div>
  </div>
</template>

<script setup>
defineProps({ rows: { type: Number, default: 4 } })
</script>
