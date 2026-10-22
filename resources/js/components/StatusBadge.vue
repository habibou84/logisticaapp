<template>
  <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', statusClass(status)]">
    {{ label || STATUS_LABELS[status] || status }}
  </span>
</template>

<script setup>
import { statusClass, STATUS_LABELS } from '../utils/format'

defineProps({
  status: { type: String, required: true },
  label: { type: String, default: '' },
})
</script>
