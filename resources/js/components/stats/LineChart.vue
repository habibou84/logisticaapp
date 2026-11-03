<template>
  <figure class="space-y-2">
    <!-- Légende : toujours présente dès deux séries -->
    <figcaption v-if="series.length > 1" class="flex flex-wrap gap-4 text-sm text-slate-600">
      <span v-for="s in series" :key="s.label" class="inline-flex items-center gap-1.5">
        <span class="inline-block h-0.5 w-4 rounded" :style="{ background: s.color }" />{{ s.label }}
      </span>
    </figcaption>

    <div ref="box" class="relative" @mouseleave="hover = null">
      <svg :width="width" :height="height" role="img" :aria-label="ariaLabel" class="block select-none">
        <!-- Grille et axe des valeurs (discrets) -->
        <g v-for="t in ticks" :key="t">
          <line :x1="pad.left" :x2="width - pad.right" :y1="y(t)" :y2="y(t)" :stroke="INK.grid" stroke-width="1" />
          <text :x="pad.left - 8" :y="y(t)" text-anchor="end" dominant-baseline="middle" font-size="11" :fill="INK.muted">{{ format(t) }}</text>
        </g>
        <text v-for="i in xLabels" :key="`x${i}`" :x="x(i)" :y="height - 6" text-anchor="middle" font-size="11" :fill="INK.muted">{{ labelText(labels[i]) }}</text>

        <line v-if="hover !== null" :x1="x(hover)" :x2="x(hover)" :y1="pad.top" :y2="height - pad.bottom" :stroke="INK.muted" stroke-width="1" stroke-dasharray="3 3" />

        <path v-for="s in series" :key="s.label" :d="path(s.values)" fill="none" :stroke="s.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

        <!-- Points au survol, entourés de la couleur du fond -->
        <template v-if="hover !== null">
          <circle v-for="s in series" :key="`p${s.label}`" :cx="x(hover)" :cy="y(s.values[hover] ?? 0)" r="4.5" :fill="s.color" stroke="#fff" stroke-width="2" />
        </template>

        <rect :x="pad.left" :y="pad.top" :width="Math.max(0, width - pad.left - pad.right)" :height="Math.max(0, height - pad.top - pad.bottom)" fill="transparent" @mousemove="move" @touchstart.passive="move" />
      </svg>

      <div
        v-if="hover !== null"
        class="pointer-events-none absolute z-10 rounded-lg border bg-white px-3 py-2 text-sm shadow-lg"
        :style="tooltipStyle"
      >
        <p class="font-medium text-slate-900">{{ labelText(labels[hover], true) }}</p>
        <p v-for="s in series" :key="`t${s.label}`" class="flex items-center gap-2 text-slate-600">
          <span class="inline-block h-2 w-2 rounded-full" :style="{ background: s.color }" />
          {{ s.label }} <span class="ml-auto pl-3 font-semibold text-slate-900">{{ format(s.values[hover] ?? 0, true) }}</span>
        </p>
      </div>
    </div>
  </figure>
</template>

<script setup>
import { computed, ref } from 'vue'
import { INK, labelIndexes, niceTicks, shortDate } from './chart'
import { useWidth } from './useWidth'

const props = defineProps({
  labels: { type: Array, required: true },
  // [{ label, color, values: [] }]
  series: { type: Array, required: true },
  height: { type: Number, default: 240 },
  // format(valeur, détaillé ?) : axe compact, info-bulle complète
  format: { type: Function, default: (v) => v.toLocaleString('fr-FR') },
  ariaLabel: { type: String, default: '' },
  // Libellés : dates (AAAA-MM-JJ, par défaut) ou textes affichés tels quels (mois…)
  dates: { type: Boolean, default: true },
})
const labelText = (v, full = false) => (props.dates ? shortDate(v, full) : v)

const box = ref(null)
const width = useWidth(box)
const hover = ref(null)
const pad = { top: 10, right: 12, bottom: 24, left: 48 }

const max = computed(() => Math.max(0, ...props.series.flatMap((s) => s.values).filter((v) => v !== null && v !== undefined)))
const ticks = computed(() => niceTicks(max.value))
const top = computed(() => ticks.value.at(-1) || 1)
const xLabels = computed(() => labelIndexes(props.labels.length, Math.max(2, Math.floor(width.value / 90))))

function x(i) {
  const n = props.labels.length
  return n <= 1 ? (pad.left + width.value - pad.right) / 2 : pad.left + (i / (n - 1)) * (width.value - pad.left - pad.right)
}
function y(v) {
  return pad.top + (1 - v / top.value) * (props.height - pad.top - pad.bottom)
}
// Valeur absente (null : jour à venir) : la ligne s'arrête
function path(values) {
  let started = false
  return values.map((v, i) => {
    if (v === null || v === undefined) {
      started = false
      return ''
    }
    const cmd = started ? 'L' : 'M'
    started = true
    return `${cmd}${x(i).toFixed(1)},${y(v).toFixed(1)}`
  }).filter(Boolean).join(' ')
}

function move(event) {
  const point = event.touches?.[0] ?? event
  const rect = box.value.getBoundingClientRect()
  const n = props.labels.length
  const ratio = (point.clientX - rect.left - pad.left) / (width.value - pad.left - pad.right)
  hover.value = Math.min(n - 1, Math.max(0, Math.round(ratio * (n - 1))))
}

const tooltipStyle = computed(() => {
  const left = x(hover.value)
  return left > width.value / 2
    ? { right: `${width.value - left + 12}px`, top: '8px' }
    : { left: `${left + 12}px`, top: '8px' }
})
</script>
