<template>
  <span :class="['rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', cls]">{{ label }}</span>
</template>

<script setup>
import { computed } from 'vue'
import { today } from '../utils/format'

const props = defineProps({
  invoice: { type: Object, required: true },
})

// En retard : facture à payer dont l'échéance est passée
const late = computed(() => props.invoice.status === 'open' && props.invoice.due_at < today())

const label = computed(() => {
  if (props.invoice.status === 'paid') return 'Payée'
  if (props.invoice.status === 'void') return 'Annulée'
  if (late.value) return props.invoice.days_late ? `En retard (${props.invoice.days_late} j)` : 'En retard'
  return props.invoice.amount_paid ? 'Payée en partie' : 'À payer'
})

const cls = computed(() => {
  if (props.invoice.status === 'paid') return 'bg-emerald-100 text-emerald-800'
  if (props.invoice.status === 'void') return 'bg-slate-100 text-slate-600'
  return late.value ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800'
})
</script>
