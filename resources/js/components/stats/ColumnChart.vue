<template>
  <div ref="box" class="relative" @mouseleave="hover = null">
    <svg :width="width" :height="height" role="img" :aria-label="ariaLabel" class="block select-none">
      <g v-for="t in ticks" :key="t">
        <line :x1="pad.left" :x2="width - pad.right" :y1="y(t)" :y2="y(t)" :stroke="INK.grid" stroke-width="1" />
        <text :x="pad.left - 8" :y="y(t)" text-anchor="end" dominant-baseline="middle" font-size="11" :fill="INK.muted">{{ format(t) }}</text>
      </g>
      <text v-for="i in xLabels" :key="`x${i}`" :x="center(i)" :y="height - 6" text-anchor="middle" font-size="11" :fill="INK.muted">{{ shortDate(labels[i]) }}</text>

      <!-- Colonnes fines, extrémité arrondie, posées sur la ligne de base -->
      <path v-for="(v, i) in values" :key="i" :d="column(i, v)" :fill="color" :opacity="hover === null || hover === i ? 1 : 0.55" />

      <!-- Zone de survol plus large que la colonne -->
      <rect
        v-for="(v, i) in values"
        :key="`h${i}`"
        :x="pad.left + i * slot"
        :y="pad.top"
        :width="slot"
        :height="Math.max(0, height - pad.top - pad.bottom)"
        fill="transparent"
        @mouseenter="hover = i"
        @touchstart.passive="hover = i"
      />
    </svg>

    <div v-if="hover !== null" class="pointer-events-none absolute z-10 rounded-lg border bg-white px-3 py-2 text-sm shadow-lg" :style="tooltipStyle">
      <p class="font-medium text-slate-900">{{ shortDate(labels[hover], true) }}</p>
      <p class="text-slate-600">{{ label }} : <span class="font-semibold text-slate-900">{{ format(values[hover], true) }}</span></p>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { INK, SERIES, labelIndexes, niceTicks, shortDate } from './chart'
import { useWidth } from './useWidth'

const props = defineProps({
  labels: { type: Array, required: true },
  values: { type: Array, required: true },
  label: { type: String, default: '' },
  color: { type: String, default: SERIES[0] },
  height: { type: Number, default: 200 },
  format: { type: Function, default: (v) => v.toLocaleString('fr-FR') },
  ariaLabel: { type: String, default: '' },
})

const box = ref(null)
const width = useWidth(box)
const hover = ref(null)
const pad = { top: 10, right: 12, bottom: 24, left: 48 }

const ticks = computed(() => niceTicks(Math.max(0, ...props.values)))
const top = computed(() => ticks.value.at(-1) || 1)
const slot = computed(() => (width.value - pad.left - pad.right) / Math.max(1, props.values.length))
const xLabels = computed(() => labelIndexes(props.labels.length, Math.max(2, Math.floor(width.value / 90))))

function y(v) {
  return pad.top + (1 - v / top.value) * (props.height - pad.top - pad.bottom)
}
function center(i) {
  return pad.left + (i + 0.5) * slot.value
}
// Colonne : 2 px d'écart avec la voisine, au plus 28 px de large, coins arrondis en haut
function column(i, v) {
  if (!v) return ''
  const w = Math.max(2, Math.min(28, slot.value - 2))
  const x0 = center(i) - w / 2
  const base = y(0)
  const yTop = y(v)
  const r = Math.min(4, w / 2, base - yTop)
  return `M${x0},${base} V${yTop + r} Q${x0},${yTop} ${x0 + r},${yTop} H${x0 + w - r} Q${x0 + w},${yTop} ${x0 + w},${yTop + r} V${base} Z`
}

const tooltipStyle = computed(() => {
  const left = center(hover.value)
  return left > width.value / 2 ? { right: `${width.value - left + 12}px`, top: '8px' } : { left: `${left + 12}px`, top: '8px' }
})
</script>
