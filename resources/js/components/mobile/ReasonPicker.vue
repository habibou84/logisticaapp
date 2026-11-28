<template>
  <div class="space-y-3">
    <input v-model="search" type="search" class="m-input" placeholder="Chercher dans la liste" aria-label="Chercher dans la liste">

    <!-- Toute la liste d'un coup (consignes, reports, échecs, refus…), filtrée par la recherche -->
    <ChoiceChips :model-value="modelValue" :options="options" label="Note" @update:model-value="(id) => emit('update:modelValue', id)" />
    <p v-if="search.trim() && !options.length" class="text-sm text-slate-600">Rien dans la liste. Choisissez « Autre » ou écrivez une note.</p>

    <p v-if="selected" class="rounded-xl bg-slate-50 px-3 py-2 text-sm">Choisi : <strong>{{ selected.label }}</strong></p>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import ChoiceChips from './ChoiceChips.vue'
import { REASON_FAMILIES } from '../../utils/reasons'

/**
 * Liste des notes de la course (motifs) en une seule liste, dans l'ordre des familles (consignes du client d'abord),
 * avec une recherche.
 */
const props = defineProps({
  modelValue: { type: Number, default: null },
  reasons: { type: Array, required: true },
})
const emit = defineEmits(['update:modelValue'])
const search = ref('')

const ORDER = Object.keys(REASON_FAMILIES)
const rank = (r) => {
  const i = ORDER.indexOf(r.family || 'other')
  return i < 0 ? ORDER.length : i
}
const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const sorted = computed(() => props.reasons.map((r, i) => ({ r, i })).sort((a, b) => rank(a.r) - rank(b.r) || a.i - b.i).map((x) => x.r))
const options = computed(() => sorted.value
  .filter((r) => !search.value.trim() || norm(r.label).includes(norm(search.value.trim())))
  .map((r) => ({ value: r.id, label: r.label })))
const selected = computed(() => props.reasons.find((r) => r.id === props.modelValue))

watch(() => props.reasons, () => { search.value = '' })
</script>
