<template>
  <div class="space-y-3">
    <ActivitySwitch billed />

    <!-- Reste à payer, toutes factures émises confondues -->
    <div v-if="toPay" :class="['rounded-2xl p-5 text-white', overdue ? 'bg-red-600' : '']" :style="overdue ? {} : { backgroundColor: 'var(--app-color)' }">
      <p class="text-sm opacity-85">{{ overdue ? 'Dont en retard : ' + money(overdue) : 'Reste à payer' }}</p>
      <p class="text-3xl font-bold mt-0.5">{{ money(toPay) }}</p>
      <p class="mt-1 text-sm opacity-90">Touchez une facture pour voir comment la régler.</p>
    </div>

    <p class="px-1 text-sm text-slate-500">Vos courses de distribution et de MAD sont facturées chaque mois, une facture par activité. Touchez une facture pour la voir ou l'enregistrer en PDF.</p>
    <RouterLink v-for="i in shown" :key="i.id" :to="`/marchand/factures/${i.id}`" class="tap m-card p-4 flex justify-between items-center gap-3 active:bg-slate-50">
      <span class="min-w-0">
        <span class="font-medium">{{ i.period_label }}</span> <span class="text-sm text-slate-500">· {{ i.activity_label }}</span>
        <span class="block text-sm text-slate-500">{{ i.number }} · {{ money(i.total) }}</span>
        <span v-if="i.status === 'issued' && i.due_at" :class="['block text-xs', i.is_overdue ? 'text-red-600 font-medium' : 'text-slate-500']">
          {{ i.is_overdue ? 'Était à payer le' : 'À payer avant le' }} {{ date(i.due_at) }}
        </span>
      </span>
      <span :class="['shrink-0 rounded-full px-2 py-0.5 text-xs font-medium', i.status === 'paid' ? 'bg-emerald-100 text-emerald-800' : i.is_overdue ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800']">
        {{ i.status === 'issued' ? `Reste ${money(i.balance)}` : i.status_label }}
      </span>
    </RouterLink>
    <p v-if="loaded && !shown.length" class="m-card p-6 text-center text-slate-500">Aucune facture pour le moment.</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import ActivitySwitch from '../../components/client/ActivitySwitch.vue'
import { useClientSpaceStore } from '../../stores/clientSpace'
import { date, money } from '../../utils/format'

const space = useClientSpaceStore()
const invoices = ref([])
const loaded = ref(false)
// Activité choisie en haut (l'e-commerce n'a pas de facture du mois : toutes)
const shown = computed(() => invoices.value.filter((i) => !space.current || space.current === 'ecommerce' || i.activity === space.current))
const unpaid = computed(() => shown.value.filter((i) => i.status === 'issued'))
const toPay = computed(() => unpaid.value.reduce((sum, i) => sum + i.balance, 0))
const overdue = computed(() => unpaid.value.filter((i) => i.is_overdue).reduce((sum, i) => sum + i.balance, 0))

onMounted(async () => {
  invoices.value = (await http.get('/client-invoices')).data.data
  loaded.value = true
})
</script>
