<template>
  <!-- Onglets de pages réunies sous une entrée du menu (Véhicules et atelier, Utilisateurs et rôles) -->
  <nav v-if="visible.length > 1" class="flex gap-1 overflow-x-auto border-b" aria-label="Onglets">
    <RouterLink
      v-for="t in visible" :key="t.to" :to="t.to"
      :class="['-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', active(t) ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800']"
      :aria-current="active(t) ? 'page' : undefined"
    >
      {{ t.label }}
    </RouterLink>
  </nav>
</template>

<script setup>
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const props = defineProps({ tabs: { type: Array, required: true } })
const auth = useAuthStore()
const route = useRoute()
// Un seul onglet permis : pas de barre
const visible = computed(() => props.tabs.filter((t) => !t.permission || auth.can(t.permission)))
const active = (t) => route.path === t.to || route.path.startsWith(`${t.to}/`)
</script>
