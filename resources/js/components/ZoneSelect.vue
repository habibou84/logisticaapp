<template>
  <div class="relative">
    <input
      :id="id"
      v-model="query"
      :class="[inputClass, 'w-full']"
      :required="required && !modelValue"
      autocomplete="jibiat-off"
      placeholder="Tapez la commune ou le quartier…"
      @focus="open = true"
      @blur="close"
      @keydown.enter.prevent="pick(matches[0])"
      @keydown.esc="open = false"
    >
    <ul v-if="open && matches.length" :class="['absolute z-20 mt-1 w-full bg-white border shadow max-h-60 overflow-y-auto', mobile ? 'rounded-xl' : 'rounded']">
      <li
        v-for="z in matches"
        :key="z.id"
        :class="[mobile ? 'px-4 py-3 border-b last:border-0 active:bg-slate-50' : 'px-3 py-2 text-sm hover:bg-slate-50', 'cursor-pointer', z.id === modelValue ? 'font-semibold' : '']"
        @mousedown.prevent="pick(z)"
      >
        <span v-if="z.is_shipping">🚌 </span>{{ z.full_name }}
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

/**
 * Commune / quartier recherché à la frappe (au lieu d'une longue liste déroulante).
 */
const props = defineProps({
  modelValue: { type: Number, default: null },
  zones: { type: Array, default: () => [] },
  id: { type: String, default: undefined },
  required: { type: Boolean, default: false },
  inputClass: { type: [String, Array], default: 'input' },
  // Téléphone : lignes plus hautes, faciles à toucher
  mobile: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const query = ref('')
const open = ref(false)

const selected = computed(() => props.zones.find((z) => z.id === props.modelValue))
watch(selected, (zone) => { query.value = zone?.full_name ?? '' }, { immediate: true })

const plain = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const matches = computed(() => {
  const term = plain(query.value)
  // Champ affichant la commune choisie : toute la liste
  if (!term || term === plain(selected.value?.full_name)) return props.zones.slice(0, 50)
  return props.zones.filter((z) => plain(z.full_name).includes(term)).slice(0, 50)
})

function pick(zone) {
  if (!zone) return
  emit('update:modelValue', zone.id)
  query.value = zone.full_name
  open.value = false
}

function close() {
  open.value = false
  // Saisie abandonnée : on réaffiche la commune choisie
  query.value = selected.value?.full_name ?? query.value
}
</script>
