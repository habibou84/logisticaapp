<template>
  <div class="pt-3">
    <button
      type="button"
      class="w-full flex items-center gap-2 px-2 py-1 text-xs font-semibold uppercase tracking-wider text-white/60 hover:text-white"
      :aria-expanded="open"
      @click.stop="toggle"
    >
      <span class="flex-1 text-left">{{ label }}</span>
      <!-- Rubrique fermée : ses alertes restent visibles -->
      <span v-if="!open && badgeTotal" class="min-w-5 h-5 px-1.5 rounded-full bg-red-600 text-white text-xs font-semibold grid place-items-center normal-case tracking-normal">
        {{ badgeTotal > 99 ? '99+' : badgeTotal }}
      </span>
      <span aria-hidden="true" :class="['transition-transform', open ? 'rotate-90' : '']">›</span>
    </button>
    <div v-show="open" class="mt-1 space-y-1">
      <SidebarItem v-for="item in items" :key="item.to" :icon="item.icon" :label="item.label" :to="item.to" :badge="item.badge || 0" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import SidebarItem from './SidebarItem.vue'

const props = defineProps({
  id: { type: String, required: true },
  label: { type: String, required: true },
  items: { type: Array, required: true },
  // Ouverte tant que l'utilisateur ne l'a pas refermée
  defaultOpen: { type: Boolean, default: true },
})

const route = useRoute()
const storageKey = `admin-menu-${props.id}`

function stored() {
  try {
    const value = localStorage.getItem(storageKey)
    return value === null ? null : value === 'open'
  } catch {
    return null
  }
}

const open = ref(stored() ?? props.defaultOpen)
const badgeTotal = computed(() => props.items.reduce((sum, item) => sum + (item.badge || 0), 0))

// La rubrique de la page affichée s'ouvre d'elle-même
const containsCurrentPage = computed(() => props.items.some((item) => route.path === item.to || route.path.startsWith(`${item.to}/`)))
watch(containsCurrentPage, (inside) => {
  if (inside) open.value = true
}, { immediate: true })

function toggle() {
  open.value = !open.value
  try {
    localStorage.setItem(storageKey, open.value ? 'open' : 'closed')
  } catch {
    // stockage indisponible : le choix vaut pour cette visite
  }
}
</script>
