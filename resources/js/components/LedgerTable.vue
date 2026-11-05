<template>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-left text-gray-600">
        <tr><th class="p-2">Date</th><th class="p-2">Opération</th><th class="p-2">Course</th><th class="p-2 text-right">Montant</th><th v-if="hasBalance" class="p-2 text-right">Solde</th><th v-if="showPayout" class="p-2">Paiement</th></tr>
      </thead>
      <tbody v-for="g in groups" :key="g.key" class="divide-y">
        <tr v-if="byDay" class="bg-slate-100">
          <td :colspan="columns - 1" class="p-2 text-xs font-semibold text-slate-700">{{ g.label }}<span v-if="g.courses" class="font-normal text-gray-500"> · {{ g.courses }} course(s)</span></td>
          <td :class="['p-2 text-right text-xs font-semibold whitespace-nowrap', signedClass(g.total)]">{{ money(g.total) }}</td>
        </tr>
        <tr v-for="e in g.entries" :key="e.id">
          <td class="p-2 text-xs whitespace-nowrap">{{ dateTime(e.created_at) }}</td>
          <td class="p-2">{{ e.type_label }}<span v-if="e.description && e.type === 'adjustment'" class="block text-xs text-gray-500">{{ e.description }}</span></td>
          <td class="p-2 text-xs"><span class="font-mono">{{ e.order?.tracking_code || '—' }}</span><span v-if="e.order?.recipient_name" class="block text-gray-500">{{ e.order.recipient_name }}</span></td>
          <td :class="['p-2 text-right font-medium whitespace-nowrap', signedClass(e.amount)]">{{ money(e.amount) }}</td>
          <td v-if="hasBalance" class="p-2 text-right whitespace-nowrap text-gray-700">{{ e.balance === null || e.balance === undefined ? '' : money(e.balance) }}</td>
          <td v-if="showPayout" class="p-2 text-xs font-mono">{{ e.payout?.reference || '—' }}</td>
        </tr>
      </tbody>
      <tbody v-if="!entries.length">
        <tr><td :colspan="6" class="p-4 text-center text-gray-500">Aucune écriture.</td></tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { dateTime, money, signedClass } from '../utils/format'

const props = defineProps({
  entries: { type: Array, default: () => [] },
  showPayout: { type: Boolean, default: true },
  // Regroupe les écritures par date de livraison de la course
  byDay: { type: Boolean, default: false },
})
// Solde après chaque ligne, quand le grand livre complet est affiché
const hasBalance = computed(() => props.entries.some((e) => e.balance !== null && e.balance !== undefined))
const columns = computed(() => 4 + (hasBalance.value ? 1 : 0) + (props.showPayout ? 1 : 0))

const dayLabel = (day) => {
  const d = new Date(`${day}T12:00:00`)
  const s = d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })
  return `Livrées le ${s.charAt(0).toUpperCase()}${s.slice(1)}`
}

const groups = computed(() => {
  if (!props.byDay) return props.entries.length ? [{ key: 'all', entries: props.entries }] : []
  const map = new Map()
  for (const e of props.entries) {
    const key = e.delivery_day || ''
    if (!map.has(key)) map.set(key, { key: key || 'none', entries: [], total: 0, orders: new Set() })
    const g = map.get(key)
    g.entries.push(e)
    g.total += Number(e.amount) || 0
    if (e.order?.id || e.order?.tracking_code) g.orders.add(e.order.id || e.order.tracking_code)
  }
  return [...map.entries()]
    .sort(([a], [b]) => (a === '' ? 1 : b === '' ? -1 : b.localeCompare(a)))
    .map(([day, g]) => ({ key: g.key, entries: g.entries, total: g.total, courses: g.orders.size, label: day ? dayLabel(day) : 'Sans course' }))
})
</script>
