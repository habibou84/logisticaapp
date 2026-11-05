<template>
  <!-- Détail de l'argent d'un livreur : par date de livraison, puis d'où vient le total -->
  <div class="grid gap-4 pt-1 lg:grid-cols-2">
    <div class="overflow-hidden rounded-xl border bg-white">
      <p class="border-b px-3 py-2 text-xs font-semibold text-gray-600">Détail par date de livraison</p>
      <table class="w-full text-sm">
        <tbody class="divide-y">
          <tr v-for="d in sortedDays" :key="d.day" :class="periodKey !== 'all' && inPeriod(d.day) ? 'bg-amber-50' : ''">
            <td class="px-3 py-2"><span class="first-letter:uppercase">{{ shortDay(d.day) }}</span><span v-if="periodKey !== 'all' && inPeriod(d.day)" class="text-xs text-amber-700"> · date choisie</span></td>
            <td class="whitespace-nowrap px-3 py-2 text-xs text-gray-500">{{ d.count }} course{{ d.count > 1 ? 's' : '' }}</td>
            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ money(d.amount) }}</td>
          </tr>
          <tr v-if="undated">
            <td class="px-3 py-2 text-gray-500">Encaissements sans date</td><td class="px-3 py-2 text-xs text-gray-500">—</td>
            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-gray-500">{{ money(undated) }}</td>
          </tr>
          <tr v-if="courier.advances || courier.expenses">
            <td class="px-3 py-2 text-gray-500">Avances et frais</td><td class="px-3 py-2 text-xs text-gray-500">sans date</td>
            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-gray-500">{{ money(courier.advances - courier.expenses) }}</td>
          </tr>
          <tr v-if="!sortedDays.length && !undated && !courier.advances && !courier.expenses"><td colspan="3" class="px-3 py-2 text-gray-400">Aucun argent en main.</td></tr>
          <tr class="bg-slate-50 font-semibold">
            <td class="px-3 py-2" colspan="2">{{ courier.cash_in_hand < 0 ? 'La caisse lui doit' : 'Total à remettre' }}</td>
            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ money(Math.abs(courier.cash_in_hand)) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="space-y-2 text-sm">
      <p class="text-xs font-semibold text-gray-600">D'où vient le total</p>
      <p class="flex justify-between"><span class="text-gray-600">Encaissé chez les clients</span><span class="tabular-nums">{{ money(courier.collected) }}</span></p>
      <p v-if="courier.advances" class="flex justify-between"><span class="text-gray-600">+ Avances données</span><span class="tabular-nums">{{ money(courier.advances) }}</span></p>
      <p v-if="courier.expenses" class="flex justify-between"><span class="text-gray-600">− Frais payés par le livreur</span><span class="tabular-nums">−{{ money(courier.expenses) }}</span></p>
      <p class="flex justify-between border-t pt-2 font-semibold">
        <span>{{ courier.cash_in_hand < 0 ? 'La caisse lui doit' : 'À remettre' }}</span><span class="tabular-nums">{{ money(Math.abs(courier.cash_in_hand)) }}</span>
      </p>
      <p class="flex justify-between pt-1 text-xs text-gray-500"><span>Gains non payés au livreur</span><span class="tabular-nums">{{ money(courier.unpaid) }}</span></p>
      <p v-if="courier.oldest_collected_at" class="text-xs text-gray-500">Garde de l'argent depuis le {{ dateTime(courier.oldest_collected_at) }}<template v-if="courier.parcels_in_hand"> · 📦 {{ courier.parcels_in_hand }} colis en main</template></p>
      <div v-if="canManage" class="flex flex-wrap gap-2 pt-1">
        <button v-if="periodKey !== 'all' && hasOtherDates" type="button" class="btn-secondary" @click="$emit('remit-all')">Recevoir toutes les dates</button>
        <button type="button" class="btn-secondary" @click="$emit('advance')">Donner une avance</button>
      </div>
      <p v-if="canManage && periodKey !== 'all' && hasOtherDates" class="text-xs text-gray-500">« Recevoir » coche seulement les courses {{ periodLabel }}.</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { dateTime, money } from '../../utils/format'

const props = defineProps({
  courier: { type: Object, required: true },
  inPeriod: { type: Function, required: true },
  periodKey: { type: String, default: 'all' },
  periodLabel: { type: String, default: '' },
  canManage: { type: Boolean, default: false },
  shortDay: { type: Function, required: true },
})
defineEmits(['remit-all', 'advance'])

// Plus récentes en premier
const sortedDays = computed(() => props.courier.days.filter((d) => d.day).sort((a, b) => b.day.localeCompare(a.day)))
// Encaissements sans date de livraison (anciennes courses) : le reste de l'encaissé
const undated = computed(() => props.courier.collected - sortedDays.value.reduce((n, d) => n + d.amount, 0))
const hasOtherDates = computed(() => sortedDays.value.some((d) => !props.inPeriod(d.day)) || undated.value !== 0)
</script>
