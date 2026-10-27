<template>
  <!-- Client à plusieurs activités : on regarde l'une d'elles, ou toutes -->
  <div v-if="menu && choices.length > 1">
    <button type="button" class="tap flex h-11 w-full items-center gap-2 rounded-xl bg-white px-3 text-sm font-semibold ring-1 ring-slate-200 active:bg-slate-50" aria-haspopup="dialog" @click="open = true">
      <StatusIcon name="sliders" class="h-4 w-4 shrink-0 text-slate-500" />
      <span class="flex-1 truncate text-left">{{ activeLabel }}</span>
      <StatusIcon name="down" class="h-4 w-4 shrink-0 text-slate-500" />
    </button>
    <BottomSheet :open="open" title="Activité" @close="open = false">
      <div class="m-card divide-y" role="radiogroup" aria-label="Activité affichée">
        <button
          v-for="a in options" :key="a.value" type="button" role="radio" :aria-checked="active === a.value"
          class="tap flex w-full items-center justify-between p-4 text-left font-medium active:bg-slate-50"
          @click="open = false; choose(a.value)"
        >
          {{ a.menuLabel }}<StatusIcon v-if="active === a.value" name="check" class="h-5 w-5 text-[var(--app-color)]" />
        </button>
      </div>
    </BottomSheet>
  </div>
  <div v-else-if="choices.length > 1" class="flex gap-2 overflow-x-auto no-scrollbar -mx-1 px-1" role="group" aria-label="Activité affichée">
    <button
      v-for="a in [{ value: '', label: 'Tout' }, ...choices]" :key="a.value" type="button"
      :class="['tap shrink-0 rounded-full px-3.5 py-1.5 text-sm font-semibold transition', active === a.value ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 shadow-soft']"
      :aria-pressed="active === a.value"
      @click="choose(a.value)"
    >
      {{ a.value === 'mad' ? 'MAD' : a.label }}
    </button>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import BottomSheet from '../mobile/BottomSheet.vue'
import StatusIcon from '../mobile/StatusIcon.vue'
import { useClientSpaceStore } from '../../stores/clientSpace'

// billed : seulement distribution et MAD (factures) ; menu : un bouton « Toutes activités ▾ »
const props = defineProps({ billed: Boolean, menu: Boolean })
const emit = defineEmits(['change'])
const space = useClientSpaceStore()
const choices = computed(() => space.activities.filter((a) => !props.billed || a.value !== 'ecommerce'))
const active = computed(() => (choices.value.some((a) => a.value === space.current) ? space.current : ''))
const open = ref(false)
const options = computed(() => [{ value: '', menuLabel: 'Toutes activités' }, ...choices.value.map((a) => ({ value: a.value, menuLabel: a.value === 'mad' ? 'MAD' : a.label }))])
const activeLabel = computed(() => options.value.find((a) => a.value === active.value)?.menuLabel)

function choose(value) {
  space.select(value)
  emit('change', value)
}
</script>
