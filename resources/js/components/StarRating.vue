<template>
  <!-- Étoiles : affichage d'une note (readonly) ou choix de 1 à 5 -->
  <span :class="['inline-flex items-center', gap]" :role="readonly ? 'img' : 'radiogroup'" :aria-label="readonly ? `${modelValue ?? 0} sur 5` : label">
    <button
      v-for="n in 5" :key="n" type="button"
      :disabled="readonly"
      :role="readonly ? undefined : 'radio'"
      :aria-checked="readonly ? undefined : modelValue === n"
      :aria-label="readonly ? undefined : `${n} étoile${n > 1 ? 's' : ''}`"
      :class="['leading-none disabled:cursor-default', readonly ? '' : 'tap active:scale-110 transition']"
      @click="$emit('update:modelValue', n)"
    >
      <svg :class="[size, fill(n)]" viewBox="0 0 24 24" aria-hidden="true">
        <defs v-if="half(n)"><linearGradient :id="`half-${uid}`"><stop offset="50%" stop-color="currentColor" /><stop offset="50%" stop-color="#e2e8f0" /></linearGradient></defs>
        <path :fill="half(n) ? `url(#half-${uid})` : 'currentColor'" d="M12 2.5l2.9 6.1 6.6.8-4.9 4.5 1.3 6.6L12 17.3l-5.9 3.2 1.3-6.6-4.9-4.5 6.6-.8z" />
      </svg>
    </button>
  </span>
</template>

<script setup>
const props = defineProps({
  modelValue: { type: Number, default: null },
  readonly: Boolean,
  size: { type: String, default: 'h-5 w-5' },
  gap: { type: String, default: 'gap-0.5' },
  label: { type: String, default: 'Note' },
})
defineEmits(['update:modelValue'])
const uid = Math.random().toString(36).slice(2, 8)
const value = () => props.modelValue ?? 0
// Note affichée : étoiles pleines, demi-étoile au-delà de ,25
const half = (n) => props.readonly && value() > n - 1 && value() < n && value() - (n - 1) >= 0.25 && value() - (n - 1) < 0.75
const fill = (n) => (value() >= n - (props.readonly ? 0.25 : 0) || half(n) ? 'text-amber-400' : 'text-slate-200')
</script>
