<template>
  <div ref="box" class="relative" @mouseleave="hover = null">
    <svg :width="width" :height="height" role="img" :aria-label="ariaLabel" class="block select-none">
      <g v-for="t in ticks" :key="t">
        <line :x1="pad.left" :x2="width - pad.right" :y1="y(t)" :y2="y(t)" :stroke="INK.grid" stroke-width="1" />
        <text :x="pad.left - 8" :y="y(t)" text-anchor="end" dominant-baseline="middle" font-size="11" :fill="INK.muted">{{ format(t) }}</text>
      </g>
      <text v-for="i in xLabels" :key="`x${i}`" :x="center(i)" :y="height - 6" text-anchor="middle" font-size="11" :fill="INK.muted">{{ labels[i] }}</text>

      <!-- Une colonne par libellé, un segment par série (2 px d'écart entre segments) -->
      <g v-for="(label, i) in labels" :key="i" :opacity="hover === null || hover === i ? 1 : 0.55">
        <rect v-for="s in segments(i)" :key="s.key" :x="s.x" :y="s.y" :width="s.w" :height="s.h" :fill="s.color" rx="2" />
      </g>

      <rect
        v-for="(label, i) in labels" :key="`h${i}`" :x="pad.left + i * slot" :y="pad.top" :width="slot"
        :height="Math.max(0, height - pad.top - pad.bottom)" fill="transparent"
        @mouseenter="hover = i" @touchstart.passive="hover = i"
      />
    </svg>

    <div v-if="hover !== null" class="pointer-events-none absolute z-10 rounded-lg border bg-white px-3 py-2 text-sm shadow-lg" :style="tooltipStyle">
      <p class="font-medium text-slate-900">{{ labels[hover] }}</p>
      <p v-for="s in series" :key="s.label" class="flex items-center gap-2 text-slate-600 whitespace-nowrap">
        <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: s.color }" />{{ s.label }} :
        <span class="font-semibold text-slate-900">{{ format(s.values[hover], true) }}</span>
      </p>
      <p v-if="series.length > 1" class="mt-1 border-t pt-1 text-slate-600">Total : <span class="font-semibold text-slate-900">{{ format(totals[hover], true) }}</span></p>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { INK, labelIndexes, niceTicks } from './chart'
import { useWidth } from './useWidth'

const props = defineProps({
  labels: { type: Array, required: true },
  // [{ label, color, values }] : empilées dans cet ordre, de bas en haut
  series: { type: Array, required: true },
  height: { type: Number, default: 240 },
  format: { type: Function, default: (v) => v.toLocaleString('fr-FR') },
  ariaLabel: { type: String, default: '' },
})

const box = ref(null)
const width = useWidth(box)
const hover = ref(null)
const pad = { top: 10, right: 12, bottom: 24, left: 48 }

const totals = computed(() => props.labels.map((_, i) => props.series.reduce((sum, s) => sum + Math.max(0, s.values[i] || 0), 0)))
const ticks = computed(() => niceTicks(Math.max(0, ...totals.value)))
const top = computed(() => ticks.value.at(-1) || 1)
const slot = computed(() => (width.value - pad.left - pad.right) / Math.max(1, props.labels.length))
const xLabels = computed(() => labelIndexes(props.labels.length, Math.max(2, Math.floor(width.value / 100))))

function y(v) {
  return pad.top + (1 - v / top.value) * (props.height - pad.top - pad.bottom)
}
function center(i) {
  return pad.left + (i + 0.5) * slot.value
}
function segments(i) {
  const w = Math.max(2, Math.min(36, slot.value * 0.62))
  let sum = 0
  return props.series.map((s, k) => {
    const v = Math.max(0, s.values[i] || 0)
    const y0 = y(sum)
    sum += v
    const h = Math.max(0, y0 - y(sum) - (k ? 2 : 0))
    return { key: s.label, x: center(i) - w / 2, y: y(sum), w, h, color: s.color }
  }).filter((s) => s.h > 0)
}

const tooltipStyle = computed(() => {
  const left = center(hover.value)
  return left > width.value / 2 ? { right: `${width.value - left + 12}px`, top: '8px' } : { left: `${left + 12}px`, top: '8px' }
})
</script>
