<template>
  <div class="space-y-3">
    <p class="px-1 text-sm text-slate-500">Vos courses sous contrat sont facturées chaque mois. Touchez une facture pour la voir ou l'enregistrer en PDF.</p>
    <RouterLink v-for="i in invoices" :key="i.id" :to="`/marchand/factures/${i.id}`" class="tap m-card p-4 flex justify-between items-center active:bg-slate-50">
      <span>
        <span class="font-medium">{{ i.period_label }}</span>
        <span class="block text-sm text-slate-500">{{ i.number }} · {{ money(i.total) }}</span>
      </span>
      <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', i.status === 'paid' ? 'bg-emerald-100 text-emerald-800' : i.is_overdue ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800']">
        {{ i.status === 'issued' ? `Reste ${money(i.balance)}` : i.status_label }}
      </span>
    </RouterLink>
    <p v-if="loaded && !invoices.length" class="m-card p-6 text-center text-slate-500">Aucune facture pour le moment.</p>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import { money } from '../../utils/format'

const invoices = ref([])
const loaded = ref(false)

onMounted(async () => {
  invoices.value = (await http.get('/client-invoices')).data.data
  loaded.value = true
})
</script>
