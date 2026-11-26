<template>
  <div class="max-w-2xl space-y-4">
    <div>
      <RouterLink to="/admin/reglages" class="text-sm text-blue-600">← Réglages</RouterLink>
      <h1 class="mt-1 flex items-center gap-2 text-xl font-bold"><Icone v-if="icon" :nom="icon" taille="grande" />{{ title }}</h1>
      <p v-if="intro" class="text-sm text-slate-600">{{ intro }}</p>
    </div>
    <slot />
  </div>
</template>

<script setup>
import Icone from '../ui/Icone.vue'
defineProps({ title: { type: String, required: true }, icon: { type: String, default: '' }, intro: { type: String, default: '' } })
</script>
