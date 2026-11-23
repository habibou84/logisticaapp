<template>
  <RouterLink
    :to="to"
    :class="['flex items-center gap-2 p-2 rounded hover:bg-white/15', alsoActive ? 'bg-white/20' : '']"
    exact-active-class="bg-white/20"
  >
    <span>{{ icon }}</span>
    <span class="flex-1">{{ label }}</span>
    <!-- Pastilles d'activité : la page ne sert qu'à la distribution, à la MAD ou à l'e-commerce -->
    <span v-for="t in tags" :key="t" :class="['rounded-full px-1.5 py-0.5 text-[10px] font-bold leading-none', activityBadge(t)]" :title="activityLabel(t)">{{ activityShort(t) }}</span>
    <span v-if="badge" class="min-w-5 h-5 px-1.5 rounded-full bg-red-600 text-white text-xs font-semibold grid place-items-center">{{ badge > 99 ? '99+' : badge }}</span>
  </RouterLink>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { activityBadge, activityLabel, activityShort } from '../utils/activities'

const props = defineProps({
  icon: { type: String, default: '' },
  label: { type: String, required: true },
  to: { type: String, required: true },
  badge: { type: Number, default: 0 },
  // Activités de la page (pastilles)
  tags: { type: Array, default: () => [] },
  // Autres pages de la même entrée (onglets) : l'entrée reste allumée
  also: { type: Array, default: () => [] },
})
const route = useRoute()
const alsoActive = computed(() => props.also.some((p) => route.path === p || route.path.startsWith(`${p}/`)))
</script>
