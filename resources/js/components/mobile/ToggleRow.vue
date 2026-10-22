<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    class="tap w-full p-4 flex items-center gap-3 text-left active:bg-slate-50"
    @click="$emit('update:modelValue', !modelValue)"
  >
    <span class="flex-1 min-w-0">
      <span class="block font-medium">{{ label }}</span>
      <span v-if="description" class="block text-sm text-slate-500">{{ description }}</span>
    </span>
    <span :class="['h-7 w-12 shrink-0 rounded-full p-0.5 transition', modelValue ? 'bg-[var(--app-color)]' : 'bg-slate-300']">
      <span :class="['block h-6 w-6 rounded-full bg-white shadow transition', modelValue ? 'translate-x-5' : '']" />
    </span>
  </button>
</template>

<script setup>
defineProps({
  modelValue: { type: Boolean, default: false },
  label: { type: String, required: true },
  description: { type: String, default: '' },
})
defineEmits(['update:modelValue'])
</script>
