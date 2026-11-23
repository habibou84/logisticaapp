<template>
  <!-- Interrupteur oui / non (charte : vert quand c'est actif) -->
  <button
    type="button" role="switch" :aria-checked="on" :aria-label="label" :disabled="disabled"
    :class="['relative h-7 w-12 shrink-0 rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50', on ? 'bg-emerald-700' : 'bg-slate-400']"
    @click="$emit('toggle')"
  >
    <span :class="['absolute top-[3px] h-[22px] w-[22px] rounded-full bg-white shadow transition-all', on ? 'left-[23px]' : 'left-[3px]']" />
  </button>
</template>

<script setup>
defineProps({ on: { type: Boolean, default: false }, label: { type: String, required: true }, disabled: { type: Boolean, default: false } })
defineEmits(['toggle'])
</script>
