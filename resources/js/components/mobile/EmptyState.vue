<template>
  <div class="text-center py-12 px-6">
    <div class="text-5xl mb-3">{{ icon }}</div>
    <p class="font-semibold text-slate-800">{{ title }}</p>
    <p v-if="text" class="text-sm text-slate-500 mt-1">{{ text }}</p>
    <div v-if="$slots.default" class="mt-5"><slot /></div>
  </div>
</template>

<script setup>
defineProps({
  icon: { type: String, default: '📭' },
  title: { type: String, required: true },
  text: { type: String, default: '' },
})
</script>
