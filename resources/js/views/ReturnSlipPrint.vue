<template>
  <div v-if="slip" class="slip-page p-4 max-w-3xl mx-auto">
    <div class="no-print mb-4 flex flex-wrap gap-2 items-center">
      <button class="btn-primary" @click="print">🖨️ Imprimer</button>
      <button class="btn-secondary" @click="$router.back()">← Retour</button>
      <span class="text-sm text-gray-500">Format A4 · à faire signer par le marchand à la remise</span>
    </div>

    <div class="bg-white text-black border border-black p-6 space-y-4">
      <header class="flex justify-between items-start gap-4">
        <div>
          <p class="text-sm">{{ slip.company }}</p>
          <h1 class="text-2xl font-bold">Bon de retour</h1>
          <p class="font-mono text-lg">{{ slip.reference }}</p>
        </div>
        <div class="text-right text-sm">
          <p>Établi le {{ dateTime(slip.created_at) }}</p>
          <p v-if="slip.created_by">par {{ slip.created_by }}</p>
          <p :class="['mt-1 inline-block rounded px-2 py-0.5 text-xs font-semibold', slip.status === 'handed' ? 'bg-emerald-100' : slip.status === 'cancelled' ? 'bg-slate-200' : 'bg-amber-100']">{{ slip.status_label }}</p>
        </div>
      </header>

      <section class="grid grid-cols-2 gap-4 text-sm border-t border-black pt-3">
        <div>
          <p class="text-xs uppercase">Marchand</p>
          <p class="font-semibold">{{ slip.merchant.business_name }}</p>
          <p>{{ slip.merchant.phone }}</p>
          <p>{{ [slip.merchant.zone, slip.merchant.address].filter(Boolean).join(' · ') }}</p>
        </div>
        <div>
          <p class="text-xs uppercase">Livreur</p>
          <p class="font-semibold">{{ slip.courier?.name || '—' }}</p>
          <p>{{ slip.courier?.phone }}</p>
        </div>
      </section>

      <table class="w-full text-sm border-collapse">
        <thead>
          <tr class="border-y border-black text-left">
            <th class="py-1 pr-2">#</th><th class="py-1 pr-2">Colis</th><th class="py-1 pr-2">Destinataire</th><th class="py-1 pr-2">Motif du retour</th><th class="py-1 text-right">Valeur</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(o, i) in slip.orders" :key="o.id" class="border-b border-slate-300">
            <td class="py-1 pr-2">{{ i + 1 }}</td>
            <td class="py-1 pr-2 font-mono">{{ o.tracking_code }}</td>
            <td class="py-1 pr-2">{{ o.recipient_name || o.recipient_phone }} <span class="text-xs">({{ o.zone }})</span></td>
            <td class="py-1 pr-2">{{ o.incident || '—' }}<span v-if="o.attempts_count" class="text-xs"> · {{ o.attempts_count }} tentative(s)</span></td>
            <td class="py-1 text-right whitespace-nowrap">{{ money(o.items_amount) }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="font-semibold"><td colspan="4" class="py-1">{{ slip.orders.length }} colis</td><td class="py-1 text-right">{{ money(total) }}</td></tr>
        </tfoot>
      </table>

      <p v-if="slip.notes" class="text-sm">Note : {{ slip.notes }}</p>

      <!-- Remise -->
      <section class="grid grid-cols-2 gap-6 border-t border-black pt-3 text-sm">
        <div>
          <p class="text-xs uppercase">Reçu par</p>
          <p v-if="slip.received_by_name" class="font-semibold">{{ slip.received_by_name }}</p>
          <p v-else class="border-b border-black h-8" />
          <p class="mt-2 text-xs uppercase">Date de remise</p>
          <p v-if="slip.handed_at">{{ dateTime(slip.handed_at) }}</p>
          <p v-else class="border-b border-black h-8" />
        </div>
        <div>
          <p class="text-xs uppercase">Signature du marchand</p>
          <img v-if="signature" :src="signature" alt="Signature du marchand" class="h-28 object-contain">
          <div v-else class="border border-black h-28" />
        </div>
      </section>
      <img v-if="photo" :src="photo" alt="Photo de la remise" class="no-print max-h-64 rounded border">
    </div>
  </div>
  <p v-else-if="error" class="p-4 text-red-600">{{ error }}</p>
  <p v-else class="p-4">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { authImage } from '../composables/useAuthImage'
import { dateTime, money } from '../utils/format'

const route = useRoute()
const slip = ref(null)
const signature = ref(null)
const photo = ref(null)
const error = ref('')
const total = computed(() => (slip.value?.orders || []).reduce((s, o) => s + (o.items_amount || 0), 0))

function print() {
  window.print()
}

onMounted(async () => {
  try {
    slip.value = (await http.get(`/return-slips/${route.params.id}`)).data.data
    ;[signature.value, photo.value] = await Promise.all([authImage(slip.value.signature_url), authImage(slip.value.photo_url)])
  } catch (e) {
    error.value = apiErrorMessage(e, 'Bon de retour introuvable.')
  }
})
</script>

<style>
@media print {
  .no-print { display: none; }
  .slip-page { padding: 0; max-width: none; }
  @page { size: A4; margin: 1.5cm; }
}
</style>
