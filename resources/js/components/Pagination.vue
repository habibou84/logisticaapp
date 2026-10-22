<template>
  <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between text-sm py-3">
    <span class="text-gray-500">{{ meta.from }}–{{ meta.to }} sur {{ meta.total }}</span>
    <div class="flex gap-2">
      <button class="btn-secondary" :disabled="meta.current_page <= 1" @click="$emit('change', meta.current_page - 1)">Précédent</button>
      <button class="btn-secondary" :disabled="meta.current_page >= meta.last_page" @click="$emit('change', meta.current_page + 1)">Suivant</button>
    </div>
  </div>
</template>

<script setup>
defineProps({ meta: { type: Object, default: null } })
defineEmits(['change'])
</script>
