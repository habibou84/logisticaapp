<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <p class="text-sm text-gray-500">Formule</p>
        <p class="text-lg font-semibold">{{ summary.plan ? summary.plan.name : 'Sans formule (aucune limite)' }}</p>
        <p v-if="summary.plan" class="text-sm text-gray-600">{{ money(summary.plan.price_monthly) }} / mois</p>
      </div>
      <span v-if="summary.on_trial" class="rounded-full bg-sky-100 px-3 py-1 text-sm font-medium text-sky-800">
        🎁 Essai gratuit jusqu'au {{ date(summary.trial_ends_at) }}
      </span>
    </div>

    <template v-if="summary.plan">
      <div class="space-y-1">
        <div class="flex justify-between text-sm">
          <span>Courses du mois</span>
          <span class="font-medium">{{ summary.usage.orders }}{{ summary.usage.included_orders !== null ? ` / ${summary.usage.included_orders} incluses` : ' (illimitées)' }}</span>
        </div>
        <div v-if="summary.usage.included_orders" class="h-2 rounded bg-slate-200 overflow-hidden">
          <div :class="['h-full', summary.usage.overage_orders ? 'bg-amber-500' : 'bg-emerald-500']" :style="{ width: `${percent(summary.usage.orders, summary.usage.included_orders)}%` }" />
        </div>
        <p v-if="summary.usage.overage_orders" class="text-xs text-amber-700">
          {{ summary.usage.overage_orders }} course{{ summary.usage.overage_orders > 1 ? 's' : '' }} en plus × {{ money(summary.plan.overage_per_order) }}
        </p>
      </div>

      <div class="space-y-1">
        <div class="flex justify-between text-sm">
          <span>Livreurs actifs</span>
          <span class="font-medium">{{ summary.usage.couriers }}{{ summary.usage.max_couriers !== null ? ` / ${summary.usage.max_couriers}` : ' (illimités)' }}</span>
        </div>
        <div v-if="summary.usage.max_couriers" class="h-2 rounded bg-slate-200 overflow-hidden">
          <div :class="['h-full', summary.usage.couriers >= summary.usage.max_couriers ? 'bg-red-500' : 'bg-emerald-500']" :style="{ width: `${percent(summary.usage.couriers, summary.usage.max_couriers)}%` }" />
        </div>
      </div>

      <div class="rounded-lg bg-slate-50 p-3 text-sm space-y-1">
        <p class="font-medium">Estimation pour {{ monthLabel }}</p>
        <div class="flex justify-between"><span>Forfait</span><span>{{ money(summary.estimate.base) }}</span></div>
        <div class="flex justify-between"><span>Courses en plus</span><span>{{ money(summary.estimate.overage) }}</span></div>
        <div class="flex justify-between border-t pt-1 font-semibold">
          <span>Total</span><span>{{ summary.on_trial ? 'Offert (essai)' : money(summary.estimate.total) }}</span>
        </div>
      </div>
    </template>

    <div>
      <p class="text-sm font-medium mb-1">Modules</p>
      <ul class="text-sm space-y-1">
        <li v-for="f in summary.features" :key="f.key" :class="f.included ? 'text-gray-800' : 'text-gray-400'">
          {{ f.included ? '✓' : '✗' }} {{ f.label }}
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { date, money } from '../utils/format'

const props = defineProps({
  summary: { type: Object, required: true },
})

const monthLabel = computed(() => {
  const [y, m] = props.summary.month.split('-')
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
})

function percent(value, max) {
  return Math.min(100, Math.round((value / max) * 100))
}
</script>
