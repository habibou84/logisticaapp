<template>
  <div class="space-y-2">
    <!-- Grille souple : 24 colonnes qui se partagent la largeur disponible -->
    <div class="grid gap-[2px] text-xs" style="grid-template-columns: auto repeat(24, minmax(0, 1fr))" role="table" :aria-label="ariaLabel">
      <span />
      <span v-for="h in 24" :key="`h${h}`" class="text-center text-slate-400">{{ (h - 1) % 6 === 0 ? `${h - 1}h` : '' }}</span>
      <template v-for="(row, d) in data" :key="d">
        <span class="pr-2 text-right leading-5 text-slate-500">{{ DAYS[d] }}</span>
        <span
          v-for="(v, h) in row"
          :key="`${d}-${h}`"
          class="h-5 rounded-sm"
          role="cell"
          :style="{ background: shade(v), outline: hover && hover.d === d && hover.h === h ? '2px solid #0d366b' : 'none' }"
          :aria-label="`${DAYS_LONG[d]} ${h} h : ${v} course${v > 1 ? 's' : ''}`"
          @mouseenter="hover = { d, h, v }"
          @mouseleave="hover = null"
        />
      </template>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
      <p class="min-h-4">
        <template v-if="hover">{{ DAYS_LONG[hover.d] }}, {{ hover.h }} h – {{ hover.h + 1 }} h : <strong class="text-slate-900">{{ hover.v }} course{{ hover.v > 1 ? 's' : '' }}</strong></template>
        <template v-else-if="peak">Pointe : {{ DAYS_LONG[peak.d].toLowerCase() }} {{ peak.h }} h – {{ peak.h + 1 }} h ({{ peak.v }} courses)</template>
      </p>
      <span class="inline-flex items-center gap-1">Moins
        <span v-for="c in BLUES" :key="c" class="inline-block h-3 w-3 rounded-sm" :style="{ background: c }" />
        Plus</span>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { BLUES } from './chart'

const props = defineProps({
  // 7 lignes (lundi → dimanche) × 24 heures
  data: { type: Array, required: true },
  ariaLabel: { type: String, default: '' },
})

const DAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']
const DAYS_LONG = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche']
const hover = ref(null)

const max = computed(() => Math.max(0, ...props.data.flat()))
const peak = computed(() => {
  let best = null
  props.data.forEach((row, d) => row.forEach((v, h) => { if (v > 0 && (!best || v > best.v)) best = { d, h, v } }))
  return best
})

function shade(v) {
  if (!v || !max.value) return '#f4f4f2'
  return BLUES[Math.min(BLUES.length - 1, 1 + Math.floor((v / max.value) * (BLUES.length - 1)))]
}
</script>
