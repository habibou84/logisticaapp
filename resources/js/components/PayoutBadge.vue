<template>
  <span :class="['inline-flex rounded-full px-2 py-0.5 text-xs font-medium', classes[status] || 'bg-gray-100 text-gray-700']">{{ label }}</span>
</template>

<script setup>
defineProps({
  status: { type: String, required: true },
  label: { type: String, required: true },
})

const classes = {
  draft: 'bg-amber-100 text-amber-800',
  paid: 'bg-emerald-100 text-emerald-800',
  cancelled: 'bg-gray-200 text-gray-600',
}
</script>
