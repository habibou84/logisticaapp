<template>
  <div v-if="payout" class="space-y-4">
    <section class="m-card p-5 text-center space-y-1">
      <PayoutBadge :status="payout.status" :label="payout.status_label" />
      <p :class="['text-4xl font-bold pt-2', signedClass(payout.net_amount)]">{{ money(Math.abs(payout.net_amount)) }}</p>
      <p class="text-slate-500">{{ payout.net_amount >= 0 ? 'reversé à vous' : 'à régler à l\'agence' }}</p>
      <p class="text-sm text-slate-500">{{ date(payout.period_start) }} → {{ date(payout.period_end) }} · <span class="font-mono">{{ payout.reference }}</span></p>
      <p v-if="payout.paid_at" class="text-sm font-medium text-emerald-700 pt-1">Payé le {{ dateTime(payout.paid_at) }} par {{ payout.method_label }}</p>
    </section>

    <section class="m-card p-4 space-y-2 text-sm">
      <div class="flex justify-between"><span class="text-slate-500">Encaissé</span><span>{{ money(payout.total_collected) }}</span></div>
      <div class="flex justify-between"><span class="text-slate-500">Frais de livraison</span><span>− {{ money(payout.total_fees) }}</span></div>
      <div v-if="payout.total_shipping_fees" class="flex justify-between"><span class="text-slate-500">Frais d'expédition</span><span>− {{ money(payout.total_shipping_fees) }}</span></div>
      <div v-if="payout.total_other_fees" class="flex justify-between"><span class="text-slate-500">Autres frais</span><span>− {{ money(payout.total_other_fees) }}</span></div>
      <div v-if="payout.total_storage_fees" class="flex justify-between"><span class="text-slate-500">Stockage</span><span>− {{ money(payout.total_storage_fees) }}</span></div>
      <div v-if="payout.total_adjustments" class="flex justify-between"><span class="text-slate-500">Ajustements</span><span>{{ money(payout.total_adjustments) }}</span></div>
      <div class="flex justify-between font-semibold text-base border-t pt-2"><span>Net</span><span>{{ money(payout.net_amount) }}</span></div>
    </section>

    <section class="m-card divide-y">
      <div v-for="e in payout.entries" :key="e.id" class="p-4 flex justify-between gap-3 text-sm">
        <div class="min-w-0">
          <p class="font-medium">{{ e.type_label }}</p>
          <p class="text-xs text-slate-500 truncate">{{ e.order?.tracking_code }} {{ e.order?.recipient_name ? `· ${e.order.recipient_name}` : '' }}</p>
        </div>
        <p :class="['font-semibold whitespace-nowrap', signedClass(e.amount)]">{{ money(e.amount) }}</p>
      </div>
    </section>

    <button class="m-btn-secondary" @click="print">🖨️ Imprimer / PDF</button>
  </div>
  <p v-else class="text-center text-slate-400 py-10">Chargement…</p>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '../../bootstrap/axios'
import PayoutBadge from '../../components/PayoutBadge.vue'
import { date, dateTime, money, signedClass } from '../../utils/format'

const route = useRoute()
const payout = ref(null)

function print() {
  window.print()
}

onMounted(async () => {
  payout.value = (await http.get(`/finance/payouts/${route.params.id}`)).data.data
})
</script>
