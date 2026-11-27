<template>
  <div v-if="slip" class="slip-page p-4 max-w-3xl mx-auto">
    <div class="no-print mb-4 flex flex-wrap gap-2 items-center">
      <button class="btn-primary" @click="print"><Icone nom="imprimer" />Imprimer</button>
      <button class="btn-secondary" @click="$router.back()">← Retour</button>
      <input v-model="day" type="date" class="input w-auto" aria-label="Jour" @change="load">
      <select v-model="kind" class="input w-auto" aria-label="Type de bordereau" @change="load">
        <option value="pickup">Remise au livreur</option>
        <option value="delivery">Réception (courrier interne)</option>
      </select>
    </div>

    <div class="bg-white text-black border border-black p-6 space-y-4">
      <header class="flex justify-between items-start gap-4">
        <div>
          <p class="text-sm">{{ slip.company }}</p>
          <h1 class="text-2xl font-bold">Bordereau de livraison</h1>
          <p class="text-sm">{{ kind === 'pickup' ? 'Remise au livreur' : 'Réception (courrier interne)' }}</p>
          <p class="text-lg">{{ slip.merchant }} · <strong>{{ slip.department.label }}</strong></p>
        </div>
        <div class="text-right text-sm">
          <p>Journée du {{ date(slip.day) }}</p>
          <p v-if="slip.number">N° {{ slip.number }}</p>
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
      </table>
      <p class="text-sm font-semibold">
        {{ slip.orders.length }} course{{ slip.orders.length > 1 ? 's' : '' }}<template v-if="totalsText"> · {{ totalsText }}</template><template v-if="couriers"> · livreur : {{ couriers }}</template>
      </p>

      <!-- Phrase d'accord : ce que les signataires reconnaissent -->
      <p v-if="slip.orders.length" class="border border-dashed border-slate-400 bg-slate-50 px-3 py-2 text-sm">
        Les signataires reconnaissent que <strong>{{ slip.orders.length > 1 ? `les ${slip.orders.length} courses` : 'la course' }} ci-dessus<template v-if="totalsText"> ({{ totalsText }})</template></strong>
        {{ slip.orders.length > 1 ? 'ont été' : 'a été' }} {{ kind === 'pickup' ? 'remise' : 'reçue' }}{{ slip.orders.length > 1 ? 's' : '' }} {{ kind === 'pickup' ? 'au livreur' : 'par le département' }} en bon état, et que leur nombre est exact.
      </p>

      <!-- Signatures : cadres à remplir sur papier, ou remplis par la signature dans l'application -->
      <section class="grid grid-cols-2 gap-5 text-sm">
        <div v-for="party in parties" :key="party.key" class="border-[1.5px] border-black p-3 space-y-2.5">
          <p class="text-xs font-bold uppercase tracking-wide">{{ party.title }}</p>
          <template v-if="party.key === 'department'">
            <p class="slip-line"><span>Nom et prénoms :</span><b>{{ last?.signed_by_name || '' }}</b></p>
            <p class="slip-line"><span>Fonction :</span><b>{{ last?.signed_by_role || '' }}</b></p>
            <p class="slip-line"><span>Date :</span><b class="!flex-none w-28">{{ last ? date(last.signed_at) : '' }}</b><span>Heure :</span><b>{{ last ? time(last.signed_at) : '' }}</b></p>
            <div class="slip-box h-24"><small>Signature</small><img v-if="signature" :src="signature" alt="Signature du département" class="h-full mx-auto object-contain"></div>
            <div class="slip-box h-20" style="border-style: dashed"><small>Cachet du département</small></div>
            <p v-if="last" class="text-xs">Signé dans l'application ({{ last.order_ids.length }} course{{ last.order_ids.length > 1 ? 's' : '' }}).</p>
          </template>
          <template v-else>
            <p class="slip-line"><span>Nom et prénoms :</span><b>{{ courierList[0]?.name || '' }}</b></p>
            <p class="slip-line"><span>N° de téléphone :</span><b>{{ courierList[0]?.phone || '' }}</b></p>
            <p class="slip-line"><span>Date :</span><b class="!flex-none w-28" /><span>Heure :</span><b /></p>
            <div class="slip-box h-24"><small>Signature</small></div>
            <div class="slip-box h-20" style="border-style: dotted"><small>Observations (envoi manquant, abîmé…)</small></div>
          </template>
        </div>
      </section>
      <p class="text-xs text-slate-600">Bordereau imprimé le {{ printedAt }}</p>
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
import Icone from '../components/ui/Icone.vue'
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
const courierList = computed(() => slip.value?.couriers || [])
const couriers = computed(() => courierList.value.map((c) => c.name).filter(Boolean).join(', ') || [...new Set((slip.value?.orders || []).map((o) => o.courier).filter(Boolean))].join(', '))
// Envois par type : « 4 plis, 2 chèques, 1 colis »
const totalsText = computed(() => Object.entries(slip.value?.totals || {})
  .map(([type, n]) => `${n} ${{ pli: n > 1 ? 'plis' : 'pli', cheque: n > 1 ? 'chèques' : 'chèque', colis: 'colis' }[type] || type}`).join(', '))
// Remise : le département remet, le livreur reçoit ; réception (courrier interne) : l'inverse
const parties = computed(() => (kind.value === 'pickup'
  ? [{ key: 'department', title: 'Remis par · le département' }, { key: 'courier', title: 'Reçu par · le livreur' }]
  : [{ key: 'courier', title: 'Remis par · le livreur' }, { key: 'department', title: 'Reçu par · le département' }]))
const printedAt = new Date().toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
const time = (v) => new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })

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
.slip-line { display: flex; gap: 6px; align-items: flex-end; }
.slip-line span { white-space: nowrap; }
.slip-line b { flex: 1; border-bottom: 1px dotted #000; min-height: 1.1em; font-weight: 600; }
.slip-box { position: relative; border: 1px solid #000; padding-top: 14px; }
.slip-box small { position: absolute; left: 6px; top: 2px; color: #555; font-size: 10.5px; }
@media print {
  .no-print { display: none; }
  .slip-page { padding: 0; max-width: none; }
  @page { size: A4; margin: 1.5cm; }
}
</style>
