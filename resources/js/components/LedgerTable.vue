<template>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-left text-gray-600">
        <tr><th class="p-2">Date</th><th class="p-2">Opération</th><th class="p-2">Course</th><th class="p-2 text-right">Montant</th><th v-if="showPayout" class="p-2">Reversement</th></tr>
      </thead>
      <tbody class="divide-y">
        <tr v-for="e in entries" :key="e.id">
          <td class="p-2 text-xs whitespace-nowrap">{{ dateTime(e.created_at) }}</td>
          <td class="p-2">{{ e.type_label }}<span v-if="e.description && e.type === 'adjustment'" class="block text-xs text-gray-500">{{ e.description }}</span></td>
          <td class="p-2 text-xs"><span class="font-mono">{{ e.order?.tracking_code || '—' }}</span><span v-if="e.order?.recipient_name" class="block text-gray-500">{{ e.order.recipient_name }}</span></td>
          <td :class="['p-2 text-right font-medium whitespace-nowrap', signedClass(e.amount)]">{{ money(e.amount) }}</td>
          <td v-if="showPayout" class="p-2 text-xs font-mono">{{ e.payout?.reference || '—' }}</td>
        </tr>
        <tr v-if="!entries.length"><td :colspan="showPayout ? 5 : 4" class="p-4 text-center text-gray-500">Aucune écriture.</td></tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { dateTime, money, signedClass } from '../utils/format'

defineProps({
  entries: { type: Array, default: () => [] },
  showPayout: { type: Boolean, default: true },
})
</script>
