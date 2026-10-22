<template>
  <div>
    <div class="-mx-4 px-4 flex gap-2 overflow-x-auto no-scrollbar" role="radiogroup" aria-label="Période">
      <button
        v-for="p in presets"
        :key="p.key"
        type="button"
        role="radio"
        :aria-checked="modelValue.key === p.key"
        :class="chip(modelValue.key === p.key)"
        :style="modelValue.key === p.key ? { backgroundColor: 'var(--app-color)' } : {}"
        @click="choose(p)"
      >
        {{ p.label }}
      </button>
      <button
        type="button"
        role="radio"
        :aria-checked="modelValue.key === 'custom'"
        :class="chip(modelValue.key === 'custom')"
        :style="modelValue.key === 'custom' ? { backgroundColor: 'var(--app-color)' } : {}"
        @click="openPicker"
      >
        📅 {{ modelValue.key === 'custom' ? modelValue.label : 'Autre date' }}
      </button>
    </div>

    <BottomSheet :open="picker.open" title="Choisir les dates" @close="picker.open = false">
      <form class="space-y-4" @submit.prevent="applyPicker">
        <ChoiceChips
          v-model="picker.mode"
          :options="[{ value: 'day', label: 'Un jour' }, { value: 'range', label: 'Du … au …' }]"
          :columns="2"
          label="Type de période"
        />
        <div v-if="picker.mode === 'day'">
          <label class="m-label" for="pick-day">Date</label>
          <input id="pick-day" v-model="picker.from" type="date" class="m-input" required>
        </div>
        <div v-else class="grid grid-cols-2 gap-2">
          <div>
            <label class="m-label" for="pick-from">Du</label>
            <input id="pick-from" v-model="picker.from" type="date" :max="picker.to || undefined" class="m-input" required>
          </div>
          <div>
            <label class="m-label" for="pick-to">Au</label>
            <input id="pick-to" v-model="picker.to" type="date" :min="picker.from" class="m-input" required>
          </div>
        </div>
        <button class="m-btn-primary">Afficher</button>
      </form>
    </BottomSheet>
  </div>
</template>

<script setup>
import { reactive } from 'vue'
import BottomSheet from './BottomSheet.vue'
import ChoiceChips from './ChoiceChips.vue'
import { date as formatDate, today } from '../../utils/format'
import { PERIOD_PRESETS } from '../../utils/periods'

const props = defineProps({
  // { key, label, from, to } (from/to vides pour « Tout »)
  modelValue: { type: Object, required: true },
  // Clés des raccourcis proposés, dans l'ordre
  keys: { type: Array, default: () => ['today', 'later', 'yesterday', 'week', 'month'] },
})
const emit = defineEmits(['update:modelValue'])

const presets = props.keys.map((k) => PERIOD_PRESETS[k])
const picker = reactive({ open: false, mode: 'day', from: today(), to: today() })

const chip = (on) => ['tap shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium ring-1', on ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']
// 2026-10-03 → « 03/10 »
const short = (iso) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`

function choose(preset) {
  emit('update:modelValue', { key: preset.key, label: preset.label, from: preset.from(), to: preset.to() })
}

function openPicker() {
  const from = props.modelValue.from || today()
  const to = props.modelValue.to || from
  Object.assign(picker, { open: true, mode: from === to ? 'day' : 'range', from, to })
}

function applyPicker() {
  const to = picker.mode === 'day' ? picker.from : picker.to
  const [from, end] = picker.from <= to ? [picker.from, to] : [to, picker.from]
  picker.open = false
  emit('update:modelValue', { key: 'custom', label: from === end ? `Le ${formatDate(from)}` : `${short(from)} → ${short(end)}`, from, to: end })
}
</script>
