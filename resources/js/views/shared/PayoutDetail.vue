<template>
  <div v-if="payout" class="max-w-4xl mx-auto space-y-4">
    <div class="no-print flex flex-wrap items-center gap-2">
      <button class="btn-secondary" @click="router.back()">← Retour</button>
      <button class="btn-secondary" @click="print">🖨️ Imprimer / PDF</button>
    </div>

    <!-- Relevé imprimable -->
    <div class="card p-6 space-y-4 statement">
      <div class="flex flex-wrap justify-between gap-4">
        <div>
          <p class="text-sm text-gray-500">{{ auth.user?.company?.name }}</p>
          <h1 class="text-xl font-bold">Relevé de reversement</h1>
          <p class="font-mono">{{ payout.reference }}</p>
        </div>
        <div class="text-right">
          <p class="font-semibold">{{ payout.merchant.business_name }}</p>
          <p class="text-sm">{{ payout.merchant.phone }}</p>
          <p class="text-sm">Période : {{ date(payout.period_start) }} → {{ date(payout.period_end) }}</p>
          <PayoutBadge :status="payout.status" :label="payout.status_label" />
        </div>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 text-sm">
        <div><p class="text-gray-500">Encaissé</p><p class="font-semibold">{{ money(payout.total_collected) }}</p></div>
        <div><p class="text-gray-500">Frais de livraison</p><p class="font-semibold">− {{ money(payout.total_fees) }}</p></div>
        <div><p class="text-gray-500">Frais d'expédition</p><p class="font-semibold">− {{ money(payout.total_shipping_fees || 0) }}</p></div>
        <div><p class="text-gray-500">Autres frais</p><p class="font-semibold">− {{ money(payout.total_other_fees || 0) }}</p></div>
        <div v-if="payout.total_storage_fees"><p class="text-gray-500">Stockage</p><p class="font-semibold">− {{ money(payout.total_storage_fees) }}</p></div>
        <div><p class="text-gray-500">Ajustements</p><p class="font-semibold">{{ money(payout.total_adjustments) }}</p></div>
        <div>
          <p class="text-gray-500">{{ payout.net_amount >= 0 ? 'Net à reverser' : 'Net dû par le marchand' }}</p>
          <p :class="['font-bold text-lg', signedClass(payout.net_amount)]">{{ money(Math.abs(payout.net_amount)) }}</p>
        </div>
      </div>

      <LedgerTable :entries="payout.entries" :show-payout="false" />

      <p v-if="payout.paid_at" class="text-sm">
        Payé le {{ dateTime(payout.paid_at) }} par {{ payout.method_label }}<span v-if="payout.transaction_ref"> (réf. {{ payout.transaction_ref }})</span>.
      </p>
      <p v-if="payout.notes" class="text-sm text-gray-600">{{ payout.notes }}</p>
    </div>

    <div v-if="payout.status === 'draft' && auth.can('finance.manage') && !auth.user?.merchant_id" class="card p-4 no-print">
      <PayForm @pay="pay" @cancel="cancel" />
    </div>
  </div>
  <p v-else class="text-gray-500">Chargement…</p>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import LedgerTable from '../../components/LedgerTable.vue'
import PayoutBadge from '../../components/PayoutBadge.vue'
import PayForm from '../../components/PayForm.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime, money, signedClass } from '../../utils/format'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const payout = ref(null)

async function load() {
  payout.value = (await http.get(`/finance/payouts/${route.params.id}`)).data.data
}

async function pay(payment) {
  try {
    payout.value = (await http.post(`/finance/payouts/${payout.value.id}/pay`, payment)).data.data
    toasts.success('Reversement enregistré comme payé.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function cancel() {
  if (!window.confirm('Annuler ce reversement ? Les opérations redeviendront disponibles.')) return
  payout.value = (await http.post(`/finance/payouts/${payout.value.id}/cancel`)).data.data
}

function print() {
  window.print()
}

onMounted(load)
</script>

<style>
@media print {
  .no-print, header, aside, nav { display: none !important; }
  .statement { box-shadow: none; border: none; }
}
</style>
