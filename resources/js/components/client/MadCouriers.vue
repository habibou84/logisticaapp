<template>
  <!-- MAD : les livreurs mis à disposition du client (nom, téléphone, état ; pas de position) -->
  <div class="m-card p-4 space-y-2">
    <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Mes livreurs attitrés</p>
    <div v-for="c in couriers" :key="c.id" class="flex items-center gap-3">
      <span class="h-10 w-10 shrink-0 rounded-full bg-slate-100 grid place-items-center font-semibold text-slate-700">{{ initials(c.name) }}</span>
      <span class="min-w-0 flex-1">
        <span class="block font-medium truncate">{{ c.name }}</span>
        <span :class="['inline-flex items-center gap-1 text-xs', COLORS[c.status]]"><span class="h-2 w-2 rounded-full bg-current" />{{ c.status_label }}</span>
        <span v-if="c.clocked_in_at" class="block text-xs text-slate-600">Arrivé à {{ hm(c.clocked_in_at) }}<template v-if="c.clocked_in_place"> · {{ c.clocked_in_place }}</template><template v-if="c.late_minutes"> ({{ c.late_minutes }} min de retard)</template></span>
        <span v-else-if="c.status !== 'absent'" class="block text-xs text-slate-600">Pas encore pointé aujourd'hui</span>
      </span>
      <a v-if="c.phone" :href="`tel:${c.phone}`" class="tap shrink-0 grid h-10 w-10 place-items-center rounded-full bg-slate-100 text-slate-700" :aria-label="`Appeler ${c.name}`"><StatusIcon name="phone" class="h-5 w-5" /></a>
    </div>
    <RouterLink to="/marchand/mes-parcours" class="tap flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5 text-sm font-semibold text-[var(--app-color)]">
      🧭 Parcours de mes livreurs <span aria-hidden="true">›</span>
    </RouterLink>
  </div>
</template>

<script setup>
import StatusIcon from '../mobile/StatusIcon.vue'
import { hm } from '../../utils/shifts'

defineProps({ couriers: { type: Array, required: true } })

const COLORS = { available: 'text-emerald-700', busy: 'text-blue-700', unavailable: 'text-slate-600', absent: 'text-amber-700' }
const initials = (name) => name.split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('')
</script>
