<template>
  <div v-if="order" class="label-page p-4">
    <div class="no-print mb-4 flex gap-2">
      <button class="btn-primary" @click="print">Imprimer</button>
      <span class="text-sm text-gray-500 self-center">Format 10 × 15 cm</span>
    </div>

    <div class="label border-2 border-black p-3 bg-white text-black space-y-2">
      <div class="flex justify-between items-start gap-2">
        <div>
          <p class="text-xs">{{ auth.user?.company?.name }}</p>
          <p class="font-mono text-lg font-bold">{{ order.tracking_code }}</p>
          <p class="text-xs">{{ dateTime(order.created_at) }}</p>
        </div>
        <img :src="qr" alt="QR code de suivi" class="w-28 h-28">
      </div>
      <div class="border-t border-black pt-2">
        <p class="text-xs uppercase">Expéditeur</p>
        <p class="font-semibold">{{ order.merchant?.business_name }}</p>
        <p class="text-sm">{{ order.pickup.zone_name }} · {{ order.pickup.phone }}</p>
      </div>
      <div class="border-t border-black pt-2">
        <p class="text-xs uppercase">Destinataire</p>
        <p class="font-bold text-lg">{{ order.recipient.name || '—' }}</p>
        <p class="font-semibold">{{ order.recipient.phone }}<span v-if="order.recipient.phone2"> / {{ order.recipient.phone2 }}</span></p>
        <p class="text-2xl font-bold uppercase">{{ order.delivery.zone_name }}</p>
        <p class="text-sm">{{ order.delivery.address }}</p>
        <p v-if="order.delivery.landmark" class="text-sm">Repère : {{ order.delivery.landmark }}</p>
      </div>
      <div class="border-t border-black pt-2 flex justify-between items-end">
        <div>
          <p class="text-xs uppercase">À encaisser</p>
          <p class="text-2xl font-bold">{{ money(order.amounts.cod_amount) }}</p>
        </div>
        <p class="text-sm font-semibold">
          <span v-if="order.package.is_fragile">FRAGILE </span>
          <span v-if="order.package.is_express">EXPRESS</span>
        </p>
      </div>
    </div>
  </div>
  <p v-else class="p-4">Chargement…</p>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import QRCode from 'qrcode'
import http from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { dateTime, money } from '../utils/format'
import { siteOrigin } from '../native'

const route = useRoute()
const auth = useAuthStore()
const order = ref(null)
const qr = ref('')

function print() {
  window.print()
}

onMounted(async () => {
  order.value = (await http.get(`/orders/${route.params.id}`)).data.data
  // Le QR code mène à la page de suivi publique (scan au ramassage, au dépôt et à la remise)
  qr.value = await QRCode.toDataURL(`${siteOrigin()}/suivi/${order.value.tracking_code}`, { margin: 1, width: 240 })
})
</script>

<style>
.label { width: 10cm; min-height: 15cm; }
@media print {
  .no-print { display: none; }
  .label-page { padding: 0; }
  @page { size: 10cm 15cm; margin: 0; }
}
</style>
