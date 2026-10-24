<template>
  <div class="text-center py-12 px-6">
    <!-- Nom d'icône (« package », « search »…) : icône au trait dans une pastille ; sinon le caractère donné -->
    <span v-if="/^[a-z-]+$/.test(icon)" class="mx-auto mb-3 grid h-16 w-16 place-items-center rounded-2xl bg-slate-100 text-slate-500" aria-hidden="true">
      <StatusIcon :name="icon" class="h-8 w-8" />
    </span>
    <div v-else class="text-5xl mb-3">{{ icon }}</div>
    <p class="font-semibold text-slate-800">{{ title }}</p>
    <p v-if="text" class="text-sm text-slate-600 mt-1">{{ text }}</p>
    <div v-if="$slots.default" class="mt-5"><slot /></div>
  </div>
</template>

<script setup>
import StatusIcon from './StatusIcon.vue'

defineProps({
  icon: { type: String, default: '📭' },
  title: { type: String, required: true },
  text: { type: String, default: '' },
})
</script>
