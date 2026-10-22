<template>
  <RouterLink
    :to="to"
    class="flex items-center gap-2 p-2 rounded hover:bg-white/15"
    exact-active-class="bg-white/20"
  >
    <span>{{ icon }}</span>
    <span class="flex-1">{{ label }}</span>
    <span v-if="badge" class="min-w-5 h-5 px-1.5 rounded-full bg-red-600 text-white text-xs font-semibold grid place-items-center">{{ badge > 99 ? '99+' : badge }}</span>
  </RouterLink>
</template>

<script setup>
defineProps({
  icon: { type: String, default: '' },
  label: { type: String, required: true },
  to: { type: String, required: true },
  badge: { type: Number, default: 0 },
})
</script>
