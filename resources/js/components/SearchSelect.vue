<template>
  <!-- Peu de choix : liste déroulante habituelle -->
  <select
    v-if="!searchable"
    :id="id"
    :value="nativeValue"
    :class="inputClass"
    :disabled="disabled"
    :required="required"
    :aria-label="ariaLabel || undefined"
    @change="onNative($event.target.value)"
  >
    <option v-if="placeholder !== null" :value="KEY_EMPTY" :disabled="!allowEmpty">{{ placeholder }}</option>
    <option v-for="(o, i) in options" :key="i" :value="keyOf(o.value)" :disabled="o.disabled">{{ o.label }}{{ o.hint ? ` · ${o.hint}` : '' }}</option>
  </select>

  <!-- Longue liste : champ de recherche (nom, téléphone, code ; accents et majuscules ignorés) -->
  <div v-else ref="root" class="relative" @keydown.esc.stop="close">
    <button
      :id="id"
      type="button"
      :class="[inputClass, 'flex w-full items-center justify-between gap-2 text-left']"
      :disabled="disabled"
      :aria-label="ariaLabel || undefined"
      aria-haspopup="listbox"
      :aria-expanded="open"
      @click="toggle"
    >
      <span :class="['min-w-0 truncate', selected ? '' : 'text-gray-500']">{{ selected ? selected.label : placeholder ?? 'Choisir…' }}</span>
      <span class="shrink-0 text-xs text-gray-400" aria-hidden="true">▾</span>
    </button>
    <!-- Valeur obligatoire : le formulaire refuse l'envoi tant que rien n'est choisi -->
    <input v-if="required" :value="selected ? 'x' : ''" required tabindex="-1" aria-hidden="true" class="pointer-events-none absolute inset-x-0 bottom-0 h-px opacity-0">

    <div v-if="open" class="absolute z-40 mt-1 w-full min-w-[16rem] rounded-xl border bg-white shadow-lg">
      <div class="border-b p-2">
        <input
          ref="search"
          v-model="query"
          type="search"
          class="input w-full py-1.5"
          :placeholder="searchPlaceholder"
          autocomplete="off"
          aria-label="Rechercher dans la liste"
          @keydown.down.prevent="move(1)"
          @keydown.up.prevent="move(-1)"
          @keydown.enter.prevent="pick(matches[active])"
        >
      </div>
      <ul class="max-h-72 overflow-y-auto py-1" role="listbox">
        <li
          v-if="placeholder !== null && allowEmpty && !query"
          :class="['cursor-pointer px-3 py-2.5 text-sm text-gray-600 sm:py-2', active === -1 ? 'bg-slate-100' : 'hover:bg-slate-50']"
          role="option" :aria-selected="selected === null"
          @mousedown.prevent="choose(emptyValue)"
        >
          {{ placeholder }}
        </li>
        <li
          v-for="(o, i) in matches" :key="keyOf(o.value)"
          :class="['px-3 py-2.5 text-sm sm:py-2', o.disabled ? 'cursor-not-allowed text-gray-400' : 'cursor-pointer', i === active ? 'bg-slate-100' : 'hover:bg-slate-50', o === selected ? 'font-semibold' : '']"
          role="option" :aria-selected="o === selected" :aria-disabled="o.disabled || undefined"
          @mousedown.prevent="pick(o)" @mouseenter="active = i"
        >
          {{ o.label }}<span v-if="o.hint" class="block text-xs font-normal text-gray-500">{{ o.hint }}</span>
        </li>
        <li v-if="!matches.length" class="px-3 py-3 text-sm text-gray-500">Aucun résultat pour « {{ query }} »</li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

/**
 * Liste déroulante avec recherche. Au-delà de « threshold » choix, un champ de recherche filtre par libellé, détail
 * et mots cachés (téléphone, code) sans tenir compte des accents ni des majuscules ; en dessous, une liste habituelle.
 * options : [{ value, label, hint?, search?, disabled? }]
 */
const props = defineProps({
  modelValue: { type: [String, Number, Boolean, null], default: null },
  options: { type: Array, required: true },
  // Choix vide (« Tous les marchands », « Livreur… ») ; null : aucun
  placeholder: { type: String, default: null },
  // Le choix vide peut être repris (filtres) ; sinon il n'est qu'une invite
  allowEmpty: { type: Boolean, default: true },
  // Valeur du choix vide (le plus souvent '' pour un filtre, null pour un champ)
  emptyValue: { type: [String, Number, null], default: '' },
  threshold: { type: Number, default: 8 },
  inputClass: { type: [String, Array], default: 'input' },
  searchPlaceholder: { type: String, default: 'Nom, téléphone…' },
  id: { type: String, default: undefined },
  ariaLabel: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  required: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'change'])

const KEY_EMPTY = '' // choix vide : valeur vide, pour que « required » bloque l'envoi
const keyOf = (v) => (v === null || v === undefined ? KEY_EMPTY : `${typeof v}:${v}`)
const searchable = computed(() => props.options.length > props.threshold)
const same = (a, b) => a !== null && a !== undefined && b !== null && b !== undefined && String(a) === String(b)
const selected = computed(() => props.options.find((o) => same(o.value, props.modelValue)) ?? null)
const nativeValue = computed(() => (selected.value ? keyOf(selected.value.value) : KEY_EMPTY))

const normalize = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const digits = (s) => String(s ?? '').replace(/\D/g, '')

const root = ref(null)
const search = ref(null)
const open = ref(false)
const query = ref('')
const active = ref(-1)

const matches = computed(() => {
  const q = normalize(query.value.trim())
  if (!q) return props.options
  const qDigits = digits(query.value)
  return props.options.filter((o) => {
    const text = normalize(`${o.label} ${o.hint ?? ''} ${o.search ?? ''}`)
    return q.split(/\s+/).every((word) => text.includes(word)) || (qDigits.length >= 3 && digits(`${o.search ?? ''} ${o.hint ?? ''}`).includes(qDigits))
  })
})
watch(query, () => { active.value = matches.value.length ? 0 : -1 })

function choose(value) {
  emit('update:modelValue', value)
  emit('change', value)
  close()
}
function pick(o) {
  if (!o || o.disabled) return
  choose(o.value)
}
function onNative(key) {
  if (key === KEY_EMPTY) return choose(props.emptyValue)
  const o = props.options.find((x) => keyOf(x.value) === key)
  if (o) choose(o.value)
}
function move(step) {
  if (!matches.value.length) return
  active.value = (active.value + step + matches.value.length) % matches.value.length
}

function onOutside(e) {
  if (root.value && !root.value.contains(e.target)) close()
}
async function toggle() {
  if (open.value) return close()
  open.value = true
  query.value = ''
  active.value = -1
  document.addEventListener('mousedown', onOutside)
  await nextTick()
  search.value?.focus()
}
function close() {
  open.value = false
  document.removeEventListener('mousedown', onOutside)
}
onBeforeUnmount(() => document.removeEventListener('mousedown', onOutside))
</script>
