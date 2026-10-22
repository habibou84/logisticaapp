<template>
  <div class="rounded-lg bg-white p-4 shadow">
    <p class="text-sm text-slate-500">{{ title }}</p>
    <p :class="['mt-1 font-bold text-slate-900', text ? 'text-lg' : 'text-2xl']">{{ display }}</p>
    <p v-if="delta !== null" class="mt-1 text-xs" :class="deltaClass">
      {{ delta > 0 ? '▲' : delta < 0 ? '▼' : '=' }} {{ deltaText }} <span class="text-slate-400">vs période précédente</span>
    </p>
    <p v-else-if="hint" class="mt-1 text-xs text-slate-400">{{ hint }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  title: { type: String, required: true },
  value: { type: Number, default: null },
  previous: { type: Number, default: null },
  // number | money | percent
  kind: { type: String, default: 'number' },
  // Une hausse est-elle une bonne nouvelle ? (échecs, annulations : non)
  higherIsBetter: { type: Boolean, default: true },
  hint: { type: String, default: '' },
  // Texte affiché à la place d'un nombre (ex. le nom d'une zone)
  text: { type: String, default: '' },
})

function fmt(v) {
  if (v === null || v === undefined) return '—'
  if (props.kind === 'percent') return `${v.toLocaleString('fr-FR')} %`
  if (props.kind === 'money') return `${Math.round(v).toLocaleString('fr-FR')} F`
  return v.toLocaleString('fr-FR')
}

const display = computed(() => props.text || fmt(props.value))

// Pourcentages : écart en points ; sinon variation relative
const delta = computed(() => {
  if (props.value === null || props.previous === null) return null
  if (props.kind === 'percent') return Math.round((props.value - props.previous) * 10) / 10
  if (!props.previous) return props.value ? null : 0
  return Math.round(((props.value - props.previous) / props.previous) * 100)
})
const deltaText = computed(() => (props.kind === 'percent' ? `${Math.abs(delta.value)} pt` : `${Math.abs(delta.value)} %`))
const deltaClass = computed(() => {
  if (!delta.value) return 'text-slate-500'
  return (delta.value > 0) === props.higherIsBetter ? 'text-emerald-700' : 'text-red-600'
})
</script>
