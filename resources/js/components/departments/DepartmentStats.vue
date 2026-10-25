<template>
  <section class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-sm text-gray-600">Chiffres de {{ data?.month_label?.toLowerCase() || '…' }} : coût d'après la facture du mois en cours de calcul.</p>
      <input v-model="month" type="month" class="input w-auto" aria-label="Mois" @change="load">
    </div>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm min-w-[40rem]">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Département</th><th class="p-2 text-right">Courses</th><th class="p-2 text-right">Envois</th><th class="p-2 text-right">Reçues</th><th class="p-2 text-right">Livrées</th><th class="p-2 text-right">Échecs</th><th class="p-2 text-right">Délai moyen</th><th class="p-2 text-right">Coût</th></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="r in data?.data || []" :key="r.id">
            <td class="p-2"><b>{{ r.label }}</b><span v-if="r.cost_center" class="block text-xs text-gray-500">{{ r.cost_center }}</span></td>
            <td class="p-2 text-right">{{ r.courses }}</td>
            <td class="p-2 text-right">{{ r.units }}<span v-if="r.quota" :class="['block text-xs', r.units > r.quota ? 'text-red-600' : 'text-gray-500']">sur {{ r.quota }}</span></td>
            <td class="p-2 text-right">{{ r.received }}</td>
            <td class="p-2 text-right">{{ r.delivered }}</td>
            <td :class="['p-2 text-right', r.failed ? 'text-red-600' : '']">{{ r.failed }}</td>
            <td class="p-2 text-right">{{ r.avg_hours === null ? '—' : `${String(r.avg_hours).replace('.', ',')} h` }}</td>
            <td class="p-2 text-right font-semibold">{{ money(r.cost) }}</td>
          </tr>
          <tr v-if="data && !data.data.length"><td colspan="8" class="p-6 text-center text-gray-500">Aucun département.</td></tr>
        </tbody>
        <tfoot v-if="data?.data?.length">
          <tr v-if="data.unassigned_cost" class="text-gray-600"><td class="p-2" colspan="7">Courses sans département</td><td class="p-2 text-right">{{ money(data.unassigned_cost) }}</td></tr>
          <tr class="border-t font-semibold"><td class="p-2">Total</td><td class="p-2 text-right">{{ sum('courses') }}</td><td class="p-2 text-right">{{ sum('units') }}</td><td /><td class="p-2 text-right">{{ sum('delivered') }}</td><td class="p-2 text-right">{{ sum('failed') }}</td><td /><td class="p-2 text-right">{{ money(sum('cost') + (data.unassigned_cost || 0)) }}</td></tr>
        </tfoot>
      </table>
    </div>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import { money, today } from '../../utils/format'

const props = defineProps({ merchantId: { type: Number, required: true } })
const month = ref(today().slice(0, 7))
const data = ref(null)
const sum = (key) => (data.value?.data || []).reduce((s, r) => s + (r[key] || 0), 0)

async function load() {
  data.value = (await http.get(`/merchants/${props.merchantId}/departments/stats`, { params: { month: month.value } })).data
}
onMounted(load)
</script>
