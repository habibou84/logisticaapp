<template>
  <div class="bg-white p-4 rounded shadow flex items-center justify-between">
    <div>
      <p class="text-sm text-gray-500">{{ title }}</p>
      <p class="text-xl font-bold">{{ value }}</p>
    </div>
    <div class="text-3xl">{{ icon }}</div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  value: { type: [String, Number], default: '—' },
  icon: { type: String, default: '' },
})
</script>
