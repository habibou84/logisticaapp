<template>
  <!-- Client à plusieurs activités : on regarde l'une d'elles, ou toutes -->
  <div v-if="choices.length > 1" class="flex gap-2 overflow-x-auto no-scrollbar -mx-1 px-1" role="group" aria-label="Activité affichée">
    <button
      v-for="a in [{ value: '', label: 'Tout' }, ...choices]" :key="a.value" type="button"
      :class="['tap shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium ring-1', active === a.value ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']"
      :style="active === a.value ? { backgroundColor: 'var(--app-color)' } : {}"
      :aria-pressed="active === a.value"
      @click="choose(a.value)"
    >
      {{ a.value === 'mad' ? 'MAD' : a.label }}
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useClientSpaceStore } from '../../stores/clientSpace'

// billed : seulement distribution et MAD (factures)
const props = defineProps({ billed: Boolean })
const emit = defineEmits(['change'])
const space = useClientSpaceStore()
const choices = computed(() => space.activities.filter((a) => !props.billed || a.value !== 'ecommerce'))
const active = computed(() => (choices.value.some((a) => a.value === space.current) ? space.current : ''))

function choose(value) {
  space.select(value)
  emit('change', value)
}
</script>
