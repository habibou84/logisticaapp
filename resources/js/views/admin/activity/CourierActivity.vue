<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Activité des livreurs</h1>

    <!-- Onglets : chaque ancienne page garde son adresse sous /admin/activite -->
    <nav class="flex gap-1 overflow-x-auto border-b border-slate-300" aria-label="Onglets de l'activité des livreurs">
      <RouterLink
        v-for="t in tabs"
        :key="t.to"
        :to="t.to"
        :class="['whitespace-nowrap px-3 py-2.5 text-sm border-b-[3px] -mb-px', isCurrent(t) ? 'border-[var(--admin-color,#1f2937)] font-bold text-slate-900' : 'border-transparent text-slate-700 hover:text-slate-900']"
        :aria-current="isCurrent(t) ? 'page' : undefined"
      >
        {{ t.label }}
        <span v-if="t.badge" :class="['ml-1 rounded-full px-1.5 text-xs font-semibold text-white', t.tone === 'orange' ? 'bg-orange-600' : 'bg-red-600']">{{ t.badge > 99 ? '99+' : t.badge }}</span>
      </RouterLink>
    </nav>

    <RouterView v-slot="{ Component }">
      <component :is="Component" embedded />
    </RouterView>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useFieldReportStore } from '../../../stores/fieldReports'
import { useHeldParcelStore } from '../../../stores/heldParcels'
import { useAuthStore } from '../../../stores/auth'
import { ACTIVITY_TABS } from '../../../utils/courierActivity'

const route = useRoute()
const auth = useAuthStore()
const fieldReports = useFieldReportStore()
const heldParcels = useHeldParcelStore()

// Onglets permis à ce compte (le planning et les remontées ont leur propre droit)
const badges = computed(() => ({
  '/admin/activite/colis': { badge: heldParcels.counts.overdue, tone: 'orange' },
  '/admin/activite/remontees': { badge: fieldReports.badge },
}))
const tabs = computed(() => ACTIVITY_TABS.filter((t) => auth.can(t.permission)).map((t) => ({ ...t, ...badges.value[t.to] })))
const isCurrent = (t) => (t.exact ? route.path === t.to || route.path === `${t.to}/` : route.path.startsWith(t.to))
</script>
