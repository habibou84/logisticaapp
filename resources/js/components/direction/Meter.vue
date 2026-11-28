<template>
  <!-- Jauge d'avancement ou de consommation ; trait vertical au repère (forfait, objectif) -->
  <div class="relative h-2 min-w-[5rem] rounded bg-slate-100" :title="`${Math.round(ratio * 100)} %`">
    <div class="absolute inset-y-0 left-0 rounded" :style="{ width: `${Math.min(100, (ratio / scale) * 100)}%`, background: color }" />
    <div v-if="mark" class="absolute inset-y-[-3px] w-0.5 bg-slate-700" :style="{ left: `${(mark / scale) * 100}%` }" />
  </div>
</template>

<script setup>
import { SERIES } from '../stats/chart'

defineProps({
  // Part atteinte (1 = 100 %)
  ratio: { type: Number, default: 0 },
  color: { type: String, default: SERIES[0] },
  // Valeur du repère et valeur du bout de la jauge (1 = 100 %)
  mark: { type: Number, default: 0 },
  scale: { type: Number, default: 1 },
})
</script>
