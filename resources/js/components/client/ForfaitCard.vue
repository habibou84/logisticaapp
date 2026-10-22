<template>
  <!-- Formule du mois (distribution, MAD) : consommation du forfait et estimation de la facture -->
  <div class="m-card p-4 space-y-3">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ plan.activity === 'mad' ? 'MAD' : plan.activity_label }} · {{ plan.month_label }}</p>
        <p class="font-semibold">{{ plan.formula_label }}</p>
      </div>
      <span v-if="plan.has_forfait" class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ plan.delivery_delay.replace('h', ' h') }}</span>
    </div>

    <template v-if="plan.has_forfait">
      <div>
        <div class="flex justify-between text-sm">
          <span><strong class="text-lg">{{ plan.used }}</strong> / {{ plan.included }} {{ plan.unit_label }} inclus</span>
          <span :class="plan.percent >= 100 ? 'text-red-600 font-semibold' : plan.percent >= 80 ? 'text-amber-700 font-semibold' : 'text-slate-500'">{{ plan.percent }} %</span>
        </div>
        <div class="mt-1.5 h-2.5 rounded-full bg-slate-100 overflow-hidden" role="progressbar" :aria-valuenow="Math.min(100, plan.percent)" aria-valuemin="0" aria-valuemax="100" :aria-label="`Forfait utilisé à ${plan.percent} %`">
          <div class="h-full rounded-full transition-all" :class="plan.percent >= 100 ? 'bg-red-500' : plan.percent >= 80 ? 'bg-amber-500' : 'bg-[var(--app-color)]'" :style="{ width: `${Math.min(100, plan.percent)}%` }" />
        </div>
        <p class="mt-1.5 text-xs text-slate-500">
          <template v-if="plan.over">{{ plan.over }} en plus du forfait<template v-if="plan.overage_price !== null"> ({{ money(plan.overage_price) }} chacun)</template>.</template>
          <template v-else>Encore {{ plan.remaining }} compris dans le forfait ce mois-ci.</template>
        </p>
      </div>
    </template>
    <p v-else class="text-sm text-slate-600">{{ plan.orders }} envoi(s) ce mois-ci, au prix du délai choisi.</p>

    <div v-if="plan.estimate !== null" class="flex justify-between border-t pt-2 text-sm">
      <span class="text-slate-500">Facture estimée à ce jour</span>
      <strong>{{ money(plan.estimate) }}</strong>
    </div>
  </div>
</template>

<script setup>
import { money } from '../../utils/format'

defineProps({ plan: { type: Object, required: true } })
</script>
