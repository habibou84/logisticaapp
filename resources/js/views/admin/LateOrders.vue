<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <RouterLink to="/admin/courses" class="text-sm text-blue-600">‹ Courses</RouterLink>
        <h1 class="text-xl font-bold">⏳ Courses en retard</h1>
        <p class="text-sm text-gray-600">
          Date de livraison dépassée, et pas encore livrées, retournées ni annulées. La distribution n'y arrive qu'après
          l'échéance de son lot, la MAD jamais (Réglages › Courses, « Livraisons du jour »).
        </p>
      </div>
      <RouterLink v-if="tab === 'pas-ramassees' && auth.can('settings.manage')" to="/admin/reglages/courses#a-reporter" class="text-sm text-blue-600">⚙️ Message du soir</RouterLink>
    </div>

    <nav class="flex gap-1 overflow-x-auto border-b" aria-label="Onglets">
      <button
        v-for="t in tabs" :key="t.key" type="button"
        :class="['-mb-px flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', tab === t.key ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800']"
        :aria-current="tab === t.key ? 'page' : undefined" @click="select(t.key)"
      >
        {{ t.label }}
        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', t.count ? 'bg-red-100 text-red-800' : 'bg-slate-100 text-slate-500']">{{ t.count }}</span>
      </button>
    </nav>
    <p class="text-sm text-gray-600">{{ current.help }}</p>

    <!-- Pas ramassées : nouvelle date à demander ou à choisir (ancienne page « À reporter ») -->
    <RescheduleOrders v-if="tab === 'pas-ramassees'" embedded />
    <!-- Ramassées pas livrées, ou toutes : actions sur les courses (ancienne page « Courses non traitées ») -->
    <UntreatedOrders v-else embedded :stage="tab === 'ramassees' ? 'picked' : 'all'" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useDispatchCountStore } from '../../stores/dispatchCounts'
import RescheduleOrders from './RescheduleOrders.vue'
import UntreatedOrders from './UntreatedOrders.vue'

/** Courses en retard (repris de Jibiat) : un seul menu, trois onglets (pas ramassées, ramassées pas livrées, toutes) */
const auth = useAuthStore()
const dispatchCounts = useDispatchCountStore()
const route = useRoute()
const router = useRouter()
const KEYS = ['pas-ramassees', 'ramassees', 'toutes']

const tabs = computed(() => {
  const c = dispatchCounts.counts
  return [
    { key: 'pas-ramassees', label: '📦 Pas ramassées', count: c.to_reschedule || 0, help: 'Colis jamais ramassés ni collectés : ils sont cachés chez les livreurs tant que la date n\'est pas actualisée. Demandez une nouvelle date ou choisissez-la.' },
    { key: 'ramassees', label: '🛵 Ramassées, pas livrées', count: c.exceptions?.untreated_picked || 0, help: 'Colis chez un livreur ou à l\'entrepôt : à livrer, reporter ou faire revenir.' },
    { key: 'toutes', label: '⏳ Toutes', count: c.exceptions?.untreated || 0, help: 'Toutes les courses en retard, quelle que soit l\'étape.' },
  ]
})
const pick = (value) => (KEYS.includes(value) ? value : 'pas-ramassees')
const tab = ref(pick(route.query.onglet))
const current = computed(() => tabs.value.find((t) => t.key === tab.value))

function select(key) {
  tab.value = key
  router.replace({ query: { onglet: key } })
}
// Lien du menu, du tableau de bord ou d'une notification vers un autre onglet
watch(() => route.query.onglet, (value) => { if (value && pick(value) !== tab.value) tab.value = pick(value) })

onMounted(() => dispatchCounts.fetchCounts().catch(() => {}))
</script>
