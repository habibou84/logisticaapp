<template>
  <!-- Date de livraison des courses : même règle que la page Courses -->
  <div class="flex flex-wrap items-center gap-2 text-sm" role="group" :aria-label="label">
    <span class="font-medium text-slate-700">📅 {{ label }} :</span>
    <button
      v-for="p in periods" :key="p.key" type="button"
      :class="['rounded-full px-3 py-1 ring-1', period.key === p.key ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
      :aria-pressed="period.key === p.key" @click="$emit('choose', p.key)"
    >
      {{ p.label }}
    </button>
    <template v-if="period.key === 'custom'">
      <input v-model="period.from" type="date" class="input w-auto py-1" aria-label="Du" @change="$emit('changed')">
      <input v-model="period.to" type="date" class="input w-auto py-1" :min="period.from" aria-label="Au" @change="$emit('changed')">
    </template>
    <span class="ml-auto text-xs text-gray-500">{{ summary }}</span>
  </div>
</template>

<script setup>
/* eslint-disable vue/no-mutating-props -- la période est un objet partagé avec la page */
defineProps({
  period: { type: Object, required: true },
  periods: { type: Array, required: true },
  label: { type: String, default: 'Date de livraison des courses' },
  summary: { type: String, default: '' },
})
defineEmits(['choose', 'changed'])
</script>
