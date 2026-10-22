<template>
  <div class="m-card overflow-hidden">
    <RouterLink :to="to" class="flex items-center gap-3 p-4 active:bg-slate-50">
      <!-- Même icône pour toutes les courses : seul l'état, en petit, change -->
      <span class="h-10 w-10 shrink-0 rounded-xl grid place-items-center bg-[color-mix(in_srgb,var(--app-color)_10%,white)] text-[var(--app-color)]" aria-hidden="true">
        <StatusIcon name="package" class="h-5 w-5" />
      </span>
      <div class="min-w-0 flex-1">
        <p class="font-semibold truncate">{{ order.recipient.name || localPhone(order.recipient.phone) }}</p>
        <p class="text-sm text-slate-500 truncate">{{ place }}</p>
        <p :class="['text-sm font-medium flex items-center gap-1.5 min-w-0', state.color]">
          <StatusIcon :name="state.icon" class="h-3.5 w-3.5 shrink-0" /><span class="truncate">{{ state.text }}</span>
        </p>
      </div>
      <div class="shrink-0 text-right">
        <!-- Course à livrer un autre jour : son jour, en court -->
        <p v-if="later" class="mb-0.5 inline-block rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700">📅 {{ later }}</p>
        <p class="font-semibold">{{ money(order.amounts.cod_amount) }}</p>
        <p class="font-mono text-[11px] text-slate-400">{{ order.tracking_code }}</p>
      </div>
    </RouterLink>
    <!-- Actions directes (ex. appeler le client d'une course à régler) -->
    <div v-if="$slots.actions" class="flex gap-2 px-4 pb-4">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { localPhone, money } from '../../utils/format'
import { laterDay, merchantStatus } from '../../utils/merchantStatus'
import StatusIcon from './StatusIcon.vue'

const props = defineProps({
  order: { type: Object, required: true },
  to: { type: String, required: true },
})

const state = computed(() => merchantStatus(props.order))
const later = computed(() => laterDay(props.order))

// « Adjamé, près du marché » plutôt que « Adjamé · Adjamé, près du marché »
const place = computed(() => {
  const { zone_name: zone, address } = props.order.delivery
  if (!address) return zone
  return zone && !address.toLowerCase().startsWith(zone.toLowerCase()) ? `${zone} · ${address}` : address
})
</script>
