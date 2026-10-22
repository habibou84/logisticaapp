<template>
  <Teleport to="body">
    <Transition name="sheet">
      <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 tap" @click.self="$emit('close')">
        <div class="w-full max-w-lg max-h-[90dvh] overflow-y-auto rounded-t-3xl bg-white pb-safe shadow-2xl" role="dialog" aria-modal="true" :aria-label="title">
          <div class="sticky top-0 bg-white rounded-t-3xl pt-2 pb-3 px-5 z-10">
            <div class="mx-auto mb-3 h-1.5 w-10 rounded-full bg-slate-300" />
            <div class="flex items-center justify-between gap-3">
              <h2 class="text-lg font-semibold">{{ title }}</h2>
              <button class="h-9 w-9 grid place-items-center rounded-full bg-slate-100 text-slate-500" aria-label="Fermer" @click="$emit('close')">✕</button>
            </div>
          </div>
          <div class="px-5 pb-6">
            <slot />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
})
defineEmits(['close'])
</script>

<style>
.sheet-enter-active, .sheet-leave-active { transition: background-color .2s ease; }
.sheet-enter-active > div, .sheet-leave-active > div { transition: transform .25s ease; }
.sheet-enter-from, .sheet-leave-to { background-color: transparent; }
.sheet-enter-from > div, .sheet-leave-to > div { transform: translateY(100%); }
</style>
