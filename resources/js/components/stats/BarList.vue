<template>
  <ul class="space-y-2">
    <li v-for="item in items" :key="item.label" class="text-sm" :title="`${item.label} : ${item.count} (${share(item.count)} %)`">
      <div class="flex justify-between gap-2">
        <span class="text-slate-700">{{ item.label }}</span>
        <span class="font-semibold text-slate-900 whitespace-nowrap">{{ item.count.toLocaleString('fr-FR') }} <span class="font-normal text-slate-400">· {{ share(item.count) }} %</span></span>
      </div>
      <div class="mt-1 h-2 rounded bg-slate-100">
        <div class="h-2 rounded" :style="{ width: `${(item.count / max) * 100}%`, background: color }" />
      </div>
    </li>
    <li v-if="!items.length" class="text-sm text-slate-500">{{ empty }}</li>
  </ul>
</template>

<script setup>
import { computed } from 'vue'
import { SERIES } from './chart'

const props = defineProps({
  // [{ label, count }]
  items: { type: Array, required: true },
  color: { type: String, default: SERIES[0] },
  empty: { type: String, default: 'Rien sur la période.' },
})

const max = computed(() => Math.max(1, ...props.items.map((i) => i.count)))
const total = computed(() => props.items.reduce((sum, i) => sum + i.count, 0))
function share(count) {
  return total.value ? Math.round((count / total.value) * 100) : 0
}
</script>
