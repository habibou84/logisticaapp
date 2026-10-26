<template>
  <div class="space-y-3">
    <input v-model="search" type="search" class="m-input" placeholder="🔍 Chercher un motif" aria-label="Chercher un motif">

    <!-- Recherche : tous les motifs qui correspondent -->
    <ChoiceChips v-if="search.trim()" :model-value="modelValue" :options="options(matches)" label="Motif" @update:model-value="pick" />
    <p v-if="search.trim() && !matches.length" class="text-sm text-slate-500">Aucun motif. Choisissez « Autres » et précisez.</p>

    <template v-else-if="!search.trim()">
      <!-- Peu de motifs : directement -->
      <ChoiceChips v-if="reasons.length <= 8" :model-value="modelValue" :options="options(reasons)" label="Motif" @update:model-value="pick" />
      <template v-else-if="family">
        <button type="button" class="text-sm font-medium text-[var(--app-color)]" @click="family = null">← {{ FAMILY(family).icon }} {{ FAMILY(family).label }}</button>
        <ChoiceChips :model-value="modelValue" :options="options(reasons.filter((r) => (r.family || 'other') === family))" label="Motif" @update:model-value="pick" />
      </template>
      <template v-else>
        <div v-if="recent.length">
          <p class="m-label">Utilisés récemment</p>
          <ChoiceChips :model-value="modelValue" :options="options(recent)" label="Motifs récents" @update:model-value="pick" />
        </div>
        <p class="m-label">Familles</p>
        <ChoiceChips :model-value="null" :options="families" :columns="2" label="Famille" @update:model-value="(f) => (family = f)" />
      </template>
    </template>

    <p v-if="selected" class="rounded-xl bg-slate-50 px-3 py-2 text-sm">Motif : <strong>{{ selected.label }}</strong></p>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import ChoiceChips from './ChoiceChips.vue'
import { REASON_FAMILIES } from '../../utils/reasons'

/**
 * Choix d'un motif parmi beaucoup : par famille, par recherche, ou parmi les derniers utilisés sur ce téléphone.
 */
const props = defineProps({
  modelValue: { type: Number, default: null },
  reasons: { type: Array, required: true },
})
const emit = defineEmits(['update:modelValue'])
const RECENT_KEY = 'recent_reasons'
const search = ref('')
const family = ref(null)

const FAMILY = (key) => REASON_FAMILIES[key] || REASON_FAMILIES.other
const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const options = (list) => list.map((r) => ({ value: r.id, label: r.label }))
const selected = computed(() => props.reasons.find((r) => r.id === props.modelValue))
const matches = computed(() => props.reasons.filter((r) => norm(r.label).includes(norm(search.value.trim()))))
const families = computed(() => Object.keys(REASON_FAMILIES)
  .map((key) => ({ key, count: props.reasons.filter((r) => (r.family || 'other') === key).length }))
  .filter((f) => f.count)
  .map((f) => ({ value: f.key, label: FAMILY(f.key).label, icon: FAMILY(f.key).icon, description: `${f.count} motif${f.count > 1 ? 's' : ''}` })))

function readRecent() {
  try {
    return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]')
  } catch {
    return []
  }
}
const recent = computed(() => readRecent().map((id) => props.reasons.find((r) => r.id === id)).filter(Boolean).slice(0, 4))

function pick(id) {
  emit('update:modelValue', id)
  try {
    localStorage.setItem(RECENT_KEY, JSON.stringify([id, ...readRecent().filter((x) => x !== id)].slice(0, 6)))
  } catch {
    // Stockage indisponible : pas de « récents »
  }
}

watch(() => props.reasons, () => { family.value = null; search.value = '' })
</script>
