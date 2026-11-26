<template>
  <div class="bg-white p-4 rounded shadow flex items-center justify-between">
    <div>
      <p class="text-sm text-slate-600">{{ title }}</p>
      <p class="text-xl font-bold">{{ value }}</p>
      <p v-if="subtitle" class="text-xs text-slate-600">{{ subtitle }}</p>
    </div>
    <div class="text-3xl">{{ icon }}</div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  value: { type: [String, Number], default: '—' },
  icon: { type: String, default: '' },
  subtitle: { type: String, default: '' },
})
</script>
