<template>
  <div class="card p-4">
    <p class="text-sm text-gray-500">{{ title }}</p>
    <p :class="['mt-1 text-2xl font-bold', tone]">{{ value }}</p>
    <p v-if="hint" class="text-xs text-gray-400">{{ hint }}</p>
  </div>
</template>

<script setup>
// Chiffre clé des écrans véhicules (titre, valeur, précision)
defineProps({
  title: { type: String, required: true },
  value: { type: [String, Number], default: '—' },
  hint: { type: String, default: '' },
  tone: { type: String, default: 'text-slate-900' },
})
</script>
