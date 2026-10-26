<template>
  <!-- Sticker électronique de la FNE : QR code de vérification, visuel et numéro de la DGI -->
  <div class="flex items-center gap-3 rounded border border-emerald-300 bg-emerald-50 p-2 text-sm w-fit">
    <img v-if="qr" :src="qr" alt="QR code de vérification FNE" class="h-24 w-24 bg-white">
    <div>
      <p class="font-semibold text-emerald-900">{{ credit ? 'Avoir' : 'Facture' }} normalisé{{ credit ? '' : 'e' }} électronique · DGI</p>
      <p>N° FNE : <span class="font-mono font-semibold">{{ fne.reference }}</span></p>
      <p v-if="fne.certified_at" class="text-xs text-gray-600">Certifié{{ credit ? '' : 'e' }} le {{ dateTime(fne.certified_at) }}</p>
      <a v-if="fne.verification_url" :href="fne.verification_url" target="_blank" rel="noopener" class="no-print text-xs text-blue-600">Vérifier sur la FNE</a>
    </div>
  </div>
</template>

<script setup>
import { ref, watchEffect } from 'vue'
import QRCode from 'qrcode'
import { dateTime } from '../utils/format'

const props = defineProps({ fne: { type: Object, required: true }, credit: { type: Boolean, default: false } })
const qr = ref('')
watchEffect(async () => {
  qr.value = props.fne.verification_url ? await QRCode.toDataURL(props.fne.verification_url, { margin: 1, width: 240 }) : ''
})
</script>
