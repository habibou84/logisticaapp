<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-4"
      @click.self="$emit('close')"
    >
      <div
        class="w-full sm:max-w-lg max-h-[92vh] overflow-y-auto bg-white rounded-t-2xl sm:rounded-xl shadow-xl"
        role="dialog"
        aria-modal="true"
      >
        <div class="flex items-center justify-between border-b px-4 py-3">
          <h2 class="font-semibold">{{ title }}</h2>
          <button class="text-gray-400 hover:text-gray-700 text-xl leading-none" aria-label="Fermer" @click="$emit('close')">×</button>
        </div>
        <div class="p-4">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
})
defineEmits(['close'])
</script>
