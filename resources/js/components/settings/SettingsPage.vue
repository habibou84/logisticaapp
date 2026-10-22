<template>
  <div class="max-w-2xl space-y-4">
    <div>
      <RouterLink to="/admin/reglages" class="text-sm text-blue-600">← Réglages</RouterLink>
      <h1 class="text-xl font-bold mt-1">{{ icon }} {{ title }}</h1>
      <p v-if="intro" class="text-sm text-gray-600">{{ intro }}</p>
    </div>
    <slot />
  </div>
</template>

<script setup>
defineProps({ title: { type: String, required: true }, icon: { type: String, default: '' }, intro: { type: String, default: '' } })
</script>
