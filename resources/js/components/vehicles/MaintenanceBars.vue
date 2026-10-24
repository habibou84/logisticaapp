<template>
  <ul>
    <li v-for="item in items" :key="item.key" class="py-2">
      <div class="flex justify-between gap-2 text-sm">
        <span>{{ item.icon }} {{ item.label }} <span class="text-gray-400">· tous les {{ everyText(item) }}</span></span>
        <span :class="['whitespace-nowrap', item.state === 'due' ? 'font-medium text-red-700' : item.state === 'soon' ? 'text-amber-700' : 'text-gray-500']">{{ remainingText(item) }}</span>
      </div>
      <div class="mt-1 h-2 rounded bg-slate-100"><div :class="['h-2 rounded', COLORS[item.state]]" :style="{ width: `${Math.max(3, item.progress)}%` }" /></div>
    </li>
    <li v-if="!items.length" class="py-2 text-sm text-gray-500">Pas de plan d'entretien.</li>
  </ul>
</template>

<script setup>
import { everyText, remainingText } from '../../utils/vehicles'

// Avancement vers chaque échéance du plan d'entretien (vert, orange à l'approche, rouge à faire)
defineProps({ items: { type: Array, default: () => [] } })
const COLORS = { ok: 'bg-emerald-500', soon: 'bg-amber-500', due: 'bg-red-500' }
</script>
