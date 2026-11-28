<template>
  <!-- Barres horizontales, valeurs négatives à gauche du zéro ; repère vertical facultatif -->
  <ul class="space-y-1.5">
    <li v-for="row in rows" :key="row.label" class="grid items-center gap-2 text-sm" :style="{ gridTemplateColumns: `${labelWidth} minmax(0, 1fr) ${valueWidth}` }" :title="row.title || `${row.label} : ${format(row.value)}`">
      <span class="truncate text-right text-slate-700">{{ row.label }}</span>
      <div class="relative h-4">
        <div class="absolute inset-y-0 rounded-sm" :style="bar(row)" />
        <div v-if="min < 0" class="absolute inset-y-[-2px] w-px bg-slate-500" :style="{ left: `${pos(0)}%` }" />
        <div v-for="m in marks" :key="m.value" class="absolute inset-y-[-3px] border-l-2" :class="m.dashed ? 'border-dashed border-slate-600' : 'border-slate-900'" :style="{ left: `${pos(m.value)}%` }" :title="m.label" />
      </div>
      <span class="whitespace-nowrap font-semibold tabular-nums text-slate-900">{{ format(row.value) }}</span>
    </li>
    <li v-if="!rows.length" class="text-sm text-slate-600">{{ empty }}</li>
  </ul>
</template>

<script setup>
import { computed } from 'vue'
import { SERIES } from '../stats/chart'

const props = defineProps({
  // [{ label, value, color?, title? }]
  rows: { type: Array, required: true },
  color: { type: String, default: SERIES[0] },
  negativeColor: { type: String, default: '#b91c1c' },
  format: { type: Function, default: (v) => v.toLocaleString('fr-FR') },
  // Repères verticaux : [{ value, label, dashed? }]
  marks: { type: Array, default: () => [] },
  // Échelle au moins jusqu'à cette valeur (ex. 100 pour des pourcentages)
  atLeast: { type: Number, default: 0 },
  labelWidth: { type: String, default: '8rem' },
  valueWidth: { type: String, default: '5.5rem' },
  empty: { type: String, default: 'Rien sur la période.' },
})

const values = computed(() => [...props.rows.map((r) => r.value ?? 0), ...props.marks.map((m) => m.value)])
const min = computed(() => Math.min(0, ...values.value))
const max = computed(() => Math.max(props.atLeast, 0, ...values.value) || 1)
const pos = (v) => ((v - min.value) / (max.value - min.value)) * 100

function bar(row) {
  const v = row.value ?? 0
  const from = pos(Math.min(0, v))
  const to = pos(Math.max(0, v))
  return { left: `${from}%`, width: `${Math.max(0.5, to - from)}%`, background: row.color || (v < 0 ? props.negativeColor : props.color) }
}
</script>
