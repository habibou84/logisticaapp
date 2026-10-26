<template>
  <div v-if="order" class="label-page p-4">
    <div class="no-print mb-4 flex gap-2">
      <button class="btn-primary" @click="print">Imprimer</button>
      <span class="text-sm text-gray-500 self-center">Format 10 × 15 cm<template v-if="receipt"> · accusé de réception à faire signer et tamponner</template></span>
    </div>

    <!-- Distribution (réglage) : l'accusé de réception remplit tout le bas de l'étiquette -->
    <div v-if="receipt" class="label receipt border-2 border-black p-[9px] bg-white text-black flex flex-col gap-[5px]">
      <div class="flex justify-between items-start gap-2">
        <div>
          <p class="text-[11px]">{{ auth.user?.company?.name }}</p>
          <p class="font-mono text-[17px] font-bold leading-tight">{{ order.tracking_code }}</p>
          <p class="text-[11px]">{{ dateTime(order.created_at) }}</p>
          <p class="text-[11px] mt-1">
            <b class="uppercase">{{ order.activity_label }}</b><template v-if="quantity"> · {{ quantity }}</template>
            <span v-if="order.package.is_express" class="tag">EXPRESS</span>
            <span v-if="order.package.is_fragile" class="tag">FRAGILE</span>
          </p>
        </div>
        <img :src="qr" alt="QR code de suivi" class="w-[2.4cm] h-[2.4cm]">
      </div>
      <div class="sec">
        <p class="k">Expéditeur</p>
        <p class="font-bold text-[13px] leading-tight">{{ order.merchant?.business_name }}<template v-if="order.department"> · {{ order.department.name }}</template></p>
        <p class="text-[11px]">{{ order.pickup.zone_name }}<template v-if="order.pickup.phone"> · {{ localPhone(order.pickup.phone) }}</template></p>
      </div>
      <div class="sec">
        <p class="k">Destinataire</p>
        <p class="font-bold text-[15px] leading-tight">{{ organisation }}</p>
        <p class="text-[13px] leading-tight">
          <template v-if="handTo">À remettre à : <b>{{ handTo }}</b> · </template><b>{{ localPhone(order.recipient.phone) }}</b><template v-if="order.recipient.phone2"> / {{ localPhone(order.recipient.phone2) }}</template>
        </p>
        <p class="text-[21px] font-extrabold uppercase leading-tight">{{ order.delivery.zone_name }}</p>
        <p class="text-[11px]">{{ order.delivery.address }}<template v-if="order.delivery.landmark"> · {{ order.delivery.landmark }}</template></p>
      </div>
      <div class="accuse">
        <p class="text-[12px] font-bold uppercase tracking-wider">Accusé de réception</p>
        <p class="line"><span>Nom :</span><i /></p>
        <p class="line"><span>Prénoms :</span><i /></p>
        <p class="line"><span>Fonction :</span><i /></p>
        <p class="line"><span>Reçu le :</span><i style="flex: 1.1" /><span>à</span><i style="flex: 0.5" /><span>h</span><i style="flex: 0.5" /></p>
        <div class="boxes">
          <div class="box"><span>Signature</span></div>
          <div class="box" style="border-style: dashed"><span>Cachet de l'entreprise</span></div>
        </div>
        <p class="text-[8.5px] mt-[5px]">Je reconnais avoir reçu {{ order.package.shipment_quantity > 1 ? 'les envois ci-dessus' : 'l\'envoi ci-dessus' }} en bon état.</p>
      </div>
    </div>

    <div v-else class="label border-2 border-black p-3 bg-white text-black space-y-2">
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
        <div v-if="order.billed_monthly">
          <p class="text-xs uppercase">{{ order.activity_label }}</p>
          <p class="text-lg font-bold">Rien à encaisser</p>
        </div>
        <div v-else>
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
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import QRCode from 'qrcode'
import http from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { dateTime, localPhone, money } from '../utils/format'
import { siteOrigin } from '../native'

const route = useRoute()
const auth = useAuthStore()
const order = ref(null)
const qr = ref('')

// Accusé de réception sur l'étiquette (réglage de l'entreprise ou du contrat, distribution par défaut)
const receipt = computed(() => order.value?.billed_monthly && order.value?.proof?.receipt_label)
// Organisation destinataire : celle de l'annuaire, sinon le nom saisi
const organisation = computed(() => order.value.destination?.label || order.value.recipient.name || '—')
// Personne attendue : responsable du département destinataire (courrier interne), ou nom saisi en plus de l'organisation
const handTo = computed(() => {
  const o = order.value
  if (o.to_department) return o.to_department.manager_name ? `${o.to_department.manager_name} (${o.to_department.name})` : o.to_department.name
  return o.destination && o.recipient.name && o.recipient.name !== o.destination.label ? o.recipient.name : null
})
const quantity = computed(() => {
  const p = order.value.package
  if (!p.shipment_type_label) return ''
  const n = p.shipment_quantity || 1
  return `${n} ${p.shipment_type_label.toLowerCase()}${n > 1 && !p.shipment_type_label.endsWith('s') ? 's' : ''}`
})

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
.label.receipt { height: 15cm; font-family: Arial, Helvetica, sans-serif; }
.label .sec { border-top: 1.5px solid #000; padding-top: 4px; }
.label .k { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
.label .tag { display: inline-block; border: 1.5px solid #000; border-radius: 3px; padding: 0 5px; font-size: 10px; font-weight: 700; margin-left: 4px; }
.label .accuse { border: 2px solid #000; border-radius: 4px; padding: 7px 8px 6px; flex: 1; display: flex; flex-direction: column; min-height: 0; }
.label .line { display: flex; gap: 6px; font-size: 12px; margin-top: 9px; align-items: flex-end; }
.label .line span { white-space: nowrap; }
.label .line i { flex: 1; border-bottom: 1px dotted #000; height: 14px; }
.label .boxes { display: flex; gap: 6px; margin-top: 9px; flex: 1; min-height: 2.4cm; }
.label .box { flex: 1; border: 1.5px solid #000; border-radius: 3px; position: relative; }
.label .box span { position: absolute; top: 3px; left: 5px; font-size: 9px; text-transform: uppercase; }
@media print {
  .no-print { display: none; }
  .label-page { padding: 0; }
  @page { size: 10cm 15cm; margin: 0; }
}
</style>
