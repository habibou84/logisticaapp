<template>
  <RouterLink :to="`/mecanicien/pannes/${repair.id}`" :class="['tap m-card block border-l-4 p-3.5 active:bg-slate-50', SEVERITY_BORDERS[repair.severity] || 'border-l-slate-300']">
    <div class="flex items-start gap-3">
      <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-slate-100 text-xl" aria-hidden="true">{{ repair.icon || '🛠️' }}</span>
      <div class="min-w-0 flex-1">
        <p class="font-semibold leading-snug">{{ repair.category_label ? `${repair.category_label}${repair.part ? ' · ' + repair.part : ''}` : repair.title }}</p>
        <p class="truncate text-sm text-slate-600">
          {{ [repair.courier?.name, repair.vehicle?.plate].filter(Boolean).join(' · ') || repair.title }}
        </p>
      </div>
      <span :class="['shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold', badge.class]">{{ badge.label }}</span>
    </div>
    <div class="mt-2 flex items-center gap-2 text-xs text-slate-600">
      <span v-if="repair.distance_km !== null">📍 {{ repair.distance_km }} km</span>
      <span v-else-if="repair.source === 'manual'">🏠 Créée par l'équipe</span>
      <span class="ml-auto font-mono">{{ footer }}</span>
    </div>
  </RouterLink>
</template>

<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { SEVERITY_STYLES } from '../../composables/useFieldEvents'
import { REPAIR_STATUS_STYLES, SEVERITY_BORDERS, ago, duration, time } from '../../composables/useMechanic'

const props = defineProps({ repair: { type: Object, required: true } })

// À traiter : la gravité ; en cours et terminées : l'étape
const badge = computed(() => (['searching', 'offered'].includes(props.repair.status)
  ? { label: props.repair.status === 'offered' ? 'Pour vous' : props.repair.severity_label, class: props.repair.status === 'offered' ? REPAIR_STATUS_STYLES.offered : SEVERITY_STYLES[props.repair.severity] }
  : { label: props.repair.status_label, class: REPAIR_STATUS_STYLES[props.repair.status] }))

const footer = computed(() => {
  const r = props.repair
  if (r.status === 'done') return `${time(r.finished_at)} · ${duration(r.arrived_at || r.accepted_at, r.finished_at)}`
  if (r.status === 'cancelled') return `annulée ${ago(r.cancelled_at)}`
  if (r.status === 'en_route') return `parti à ${time(r.departed_at)}`
  if (['on_site', 'repairing'].includes(r.status)) return `sur place depuis ${time(r.arrived_at)}`
  return ago(r.created_at)
})
</script>
