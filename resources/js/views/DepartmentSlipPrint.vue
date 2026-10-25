<template>
  <div v-if="slip" class="slip-page p-4 max-w-3xl mx-auto">
    <div class="no-print mb-4 flex flex-wrap gap-2 items-center">
      <button class="btn-primary" @click="print">🖨️ Imprimer</button>
      <button class="btn-secondary" @click="$router.back()">← Retour</button>
      <input v-model="day" type="date" class="input w-auto" aria-label="Jour" @change="load">
      <select v-model="kind" class="input w-auto" aria-label="Type de bordereau" @change="load">
        <option value="pickup">Remis au livreur</option>
        <option value="delivery">Reçu (courrier interne)</option>
      </select>
    </div>

    <div class="bg-white text-black border border-black p-6 space-y-4">
      <header class="flex justify-between items-start gap-4">
        <div>
          <p class="text-sm">{{ slip.company }}</p>
          <h1 class="text-2xl font-bold">Bordereau {{ kind === 'pickup' ? 'de remise' : 'de réception' }}</h1>
          <p class="text-lg">{{ slip.merchant }} · <strong>{{ slip.department.label }}</strong></p>
        </div>
        <div class="text-right text-sm">
          <p>Journée du {{ date(slip.day) }}</p>
          <p v-if="slip.department.location">{{ slip.department.location }}</p>
          <p v-if="slip.department.cost_center">Centre de coûts : {{ slip.department.cost_center }}</p>
        </div>
      </header>

      <table class="w-full text-sm border-collapse">
        <thead>
          <tr class="border-y border-black text-left">
            <th class="py-1 pr-2">#</th><th class="py-1 pr-2">Course</th><th class="py-1 pr-2">{{ kind === 'pickup' ? 'Destinataire' : 'Envoyée par' }}</th><th class="py-1 pr-2">Envoi</th><th class="py-1 pr-2">Demandé par</th><th class="py-1">{{ kind === 'pickup' ? 'Remis par' : 'Reçu par' }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(o, i) in slip.orders" :key="o.id" class="border-b border-slate-300 align-top">
            <td class="py-1 pr-2">{{ i + 1 }}</td>
            <td class="py-1 pr-2"><span class="font-mono">{{ o.tracking_code }}</span><span v-if="o.reference" class="block text-xs">Réf. {{ o.reference }}</span></td>
            <td class="py-1 pr-2">{{ o.recipient }}<span v-if="o.zone" class="text-xs"> ({{ o.zone }})</span></td>
            <td class="py-1 pr-2">{{ o.shipment || '—' }}</td>
            <td class="py-1 pr-2">{{ o.requested_by || '—' }}</td>
            <td class="py-1">{{ (kind === 'pickup' ? o.handed_by : o.received_by) || o.status_label }}</td>
          </tr>
          <tr v-if="!slip.orders.length"><td colspan="6" class="py-4 text-center">Aucune course ce jour-là.</td></tr>
        </tbody>
        <tfoot>
          <tr class="font-semibold"><td colspan="6" class="py-1">{{ slip.orders.length }} course(s){{ couriers ? ` · livreur : ${couriers}` : '' }}</td></tr>
        </tfoot>
      </table>

      <!-- Signatures enregistrées, ou cadre à signer sur papier -->
      <section class="grid grid-cols-2 gap-6 border-t border-black pt-3 text-sm">
        <div>
          <p class="text-xs uppercase">Pour le département</p>
          <template v-if="last">
            <p class="font-semibold">{{ last.signed_by_name }}<span v-if="last.signed_by_role" class="font-normal"> · {{ last.signed_by_role }}</span></p>
            <p class="text-xs">Signé le {{ dateTime(last.signed_at) }} ({{ last.order_ids.length }} course(s))</p>
            <img v-if="signature" :src="signature" alt="Signature du département" class="h-28 object-contain">
          </template>
          <div v-else class="border border-black h-28" />
        </div>
        <div>
          <p class="text-xs uppercase">Livreur</p>
          <p class="font-semibold">{{ couriers || '—' }}</p>
          <div class="border border-black h-28 mt-1" />
        </div>
      </section>
    </div>

    <!-- Faire signer sur le téléphone ou la tablette -->
    <section v-if="slip.orders.length" class="no-print card mt-4 p-4 space-y-3">
      <h2 class="font-semibold">{{ last ? 'Signer à nouveau' : 'Faire signer ici' }}</h2>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label" for="s-name">Nom</label><input id="s-name" v-model="form.signed_by_name" class="input" maxlength="120"></div>
        <div><label class="label" for="s-role">Fonction</label><input id="s-role" v-model="form.signed_by_role" class="input" maxlength="80"></div>
      </div>
      <SignaturePad v-model="form.signature" />
      <p v-if="signError" class="field-error">{{ signError }}</p>
      <button class="btn-primary" :disabled="busy || !form.signed_by_name || !form.signature" @click="sign">Enregistrer la signature</button>
    </section>
  </div>
  <p v-else-if="error" class="p-4 text-red-600">{{ error }}</p>
  <p v-else class="p-4">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { authImage } from '../composables/useAuthImage'
import SignaturePad from '../components/mobile/SignaturePad.vue'
import { date, dateTime, today } from '../utils/format'

const route = useRoute()
const slip = ref(null)
const signature = ref(null)
const error = ref('')
const day = ref(route.query.jour || today())
const kind = ref(route.query.type === 'delivery' ? 'delivery' : 'pickup')
const form = reactive({ signed_by_name: '', signed_by_role: '', signature: null })
const busy = ref(false)
const signError = ref('')

const last = computed(() => slip.value?.slips?.[0] || null)
const couriers = computed(() => [...new Set((slip.value?.orders || []).map((o) => o.courier).filter(Boolean))].join(', '))

function print() {
  window.print()
}

async function load() {
  try {
    slip.value = (await http.get(`/departments/${route.params.id}/slip`, { params: { day: day.value, kind: kind.value } })).data.data
    signature.value = await authImage(last.value?.signature_url)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Bordereau introuvable.')
  }
}

async function sign() {
  busy.value = true
  signError.value = ''
  try {
    await http.post(`/departments/${route.params.id}/slips`, { day: day.value, kind: kind.value, ...form })
    Object.assign(form, { signed_by_name: '', signed_by_role: '', signature: null })
    await load()
  } catch (e) {
    signError.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<style>
@media print {
  .no-print { display: none; }
  .slip-page { padding: 0; max-width: none; }
  @page { size: A4; margin: 1.5cm; }
}
</style>
