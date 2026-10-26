<template>
  <!-- Distribution (réglage) : l'accusé de réception remplit tout le bas de l'étiquette -->
  <div v-if="receipt" class="label receipt border-2 border-black p-[9px] bg-white text-black flex flex-col gap-[5px]">
    <div class="flex justify-between items-start gap-2">
      <div>
        <img v-if="logo" :src="logo" alt="" class="max-h-[0.9cm] max-w-[3.5cm] object-contain">
        <p v-else class="text-[11px]">{{ company?.name }}</p>
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

  <div v-else class="label border-2 border-black p-3 bg-white text-black flex flex-col gap-2">
    <div class="flex justify-between items-start gap-2">
      <div>
        <img v-if="logo" :src="logo" alt="" class="max-h-[1cm] max-w-[3.5cm] object-contain">
        <p v-else class="text-xs">{{ company?.name }}</p>
        <p class="font-mono text-lg font-bold">{{ order.tracking_code }}</p>
        <p class="text-xs">{{ dateTime(order.created_at) }}</p>
        <p v-if="order.activity && order.activity !== 'ecommerce'" class="text-xs mt-1"><b class="uppercase">{{ order.activity_label }}</b><template v-if="quantity"> · {{ quantity }}</template></p>
      </div>
      <img :src="qr" alt="QR code de suivi" class="w-28 h-28">
    </div>
    <div class="border-t border-black pt-2">
      <p class="text-xs uppercase">Expéditeur</p>
      <p class="font-semibold">{{ order.merchant?.business_name }}<template v-if="order.department"> · {{ order.department.name }}</template></p>
      <p class="text-sm">{{ order.pickup.zone_name }}<template v-if="order.pickup.phone"> · {{ localPhone(order.pickup.phone) }}</template></p>
    </div>
    <div class="border-t border-black pt-2 flex-1">
      <p class="text-xs uppercase">Destinataire</p>
      <p class="font-bold text-lg leading-tight">{{ organisation }}</p>
      <p v-if="handTo" class="text-sm">À remettre à : <b>{{ handTo }}</b></p>
      <p class="font-semibold">{{ localPhone(order.recipient.phone) }}<span v-if="order.recipient.phone2"> / {{ localPhone(order.recipient.phone2) }}</span></p>
      <p class="text-2xl font-bold uppercase leading-tight">{{ order.delivery.zone_name }}</p>
      <p class="text-sm">{{ order.delivery.address }}</p>
      <p v-if="order.delivery.landmark" class="text-sm">Repère : {{ order.delivery.landmark }}</p>
    </div>
    <div class="border-t border-black pt-2 flex justify-between items-end gap-2">
      <!-- E-commerce : ce que le livreur encaisse ; distribution, MAD : facturé au mois, rien à encaisser -->
      <div v-if="!order.billed_monthly">
        <p class="text-xs uppercase">À encaisser</p>
        <p class="text-2xl font-bold">{{ money(order.amounts.cod_amount) }}</p>
      </div>
      <div v-else-if="order.package.description" class="text-sm">{{ order.package.description }}</div>
      <p class="text-sm font-semibold ml-auto">
        <span v-if="order.package.is_fragile" class="tag">FRAGILE</span>
        <span v-if="order.package.is_express" class="tag">EXPRESS</span>
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watchEffect } from 'vue'
import QRCode from 'qrcode'
import { useAuthStore } from '../../stores/auth'
import { dateTime, localPhone, money } from '../../utils/format'
import { siteOrigin } from '../../native'

/**
 * Étiquette d'une course (10 × 15 cm) : e-commerce avec le montant à encaisser, distribution avec l'accusé de
 * réception (réglage), MAD sans montant. Le QR code mène au suivi public (scan au ramassage, au dépôt, à la remise).
 */
const props = defineProps({ order: { type: Object, required: true } })
const auth = useAuthStore()
const company = computed(() => auth.user?.company)
const logo = computed(() => company.value?.logo_url || null)
const qr = ref('')
watchEffect(async () => {
  qr.value = await QRCode.toDataURL(`${siteOrigin()}/suivi/${props.order.tracking_code}`, { margin: 1, width: 240 })
})

// Accusé de réception sur l'étiquette (réglage de l'entreprise ou du contrat, distribution par défaut)
const receipt = computed(() => props.order.billed_monthly && props.order.proof?.receipt_label)
// Organisation destinataire : celle de l'annuaire, sinon le nom saisi
const organisation = computed(() => props.order.destination?.label || props.order.recipient.name || '—')
// Personne attendue : responsable du département destinataire (courrier interne), ou nom saisi en plus de l'organisation
const handTo = computed(() => {
  const o = props.order
  if (o.to_department) return o.to_department.manager_name ? `${o.to_department.manager_name} (${o.to_department.name})` : o.to_department.name
  return o.destination && o.recipient.name && o.recipient.name !== o.destination.label ? o.recipient.name : null
})
const quantity = computed(() => {
  const p = props.order.package
  if (!p.shipment_type_label) return ''
  const n = p.shipment_quantity || 1
  return `${n} ${p.shipment_type_label.toLowerCase()}${n > 1 && !p.shipment_type_label.endsWith('s') ? 's' : ''}`
})
</script>
