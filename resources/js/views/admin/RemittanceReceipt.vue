<template>
  <div v-if="r" class="space-y-4 max-w-4xl">
    <div class="flex flex-wrap items-center gap-2 print:hidden">
      <RouterLink to="/admin/caisse" class="text-sm text-blue-600">← Caisse</RouterLink>
      <span class="flex-1" />
      <button type="button" class="btn-primary" @click="print">Imprimer / PDF</button>
      <button v-if="canManage && r.courier_phone && !r.cancelled_at" type="button" class="btn-secondary" :disabled="busy" @click="resend">Renvoyer au livreur par WhatsApp</button>
    </div>

    <div class="grid gap-4 lg:grid-cols-[3fr_2fr]">
      <!-- Reçu imprimable -->
      <section class="card p-6 space-y-4 print:shadow-none print:border-0">
        <div class="flex justify-between gap-4">
          <div>
            <p class="text-lg font-bold">{{ r.company }}</p>
            <p class="text-sm text-gray-600">Reçu de versement à la caisse</p>
          </div>
          <div class="text-right text-sm">
            <p class="font-mono font-bold">{{ r.reference }}</p>
            <p>{{ dateTime(r.received_at) }}</p>
          </div>
        </div>
        <p v-if="r.cancelled_at" class="rounded bg-red-50 p-2 text-sm font-semibold text-red-800">
          Versement annulé le {{ dateTime(r.cancelled_at) }}<template v-if="r.cancelled_by"> par {{ r.cancelled_by }}</template> : « {{ r.cancel_reason }} »
        </p>
        <div class="grid grid-cols-2 gap-2 border-t pt-3 text-sm">
          <span>Livreur : <strong>{{ r.courier_name }}</strong></span>
          <span>Reçu par : <strong>{{ r.received_by }}</strong></span>
        </div>

        <div class="divide-y rounded border text-sm">
          <div class="flex justify-between p-2"><span>Encaissé ({{ r.collections.length }} course{{ r.collections.length > 1 ? 's' : '' }})</span><span>{{ money(collected) }}</span></div>
          <div v-for="a in r.advances" :key="`a${a.id}`" class="flex justify-between p-2">
            <span>{{ a.pay_kept ? 'Paie gardée sur l\'encaissé' : `Avance : ${a.reason}` }}</span><span>{{ a.amount < 0 ? '−' : '+' }} {{ money(Math.abs(a.amount)) }}</span>
          </div>
          <div v-for="e in r.expenses" :key="`e${e.id}`" class="flex justify-between p-2">
            <span>Frais payés par le livreur : {{ e.description }} <span class="font-mono text-xs">{{ e.tracking_code }}</span></span><span>− {{ money(e.amount) }}</span>
          </div>
          <div class="flex justify-between bg-slate-50 p-2 font-semibold"><span>Attendu</span><span>{{ money(r.amount_expected) }}</span></div>
          <div class="flex justify-between p-2 font-semibold"><span>{{ r.amount_expected < 0 ? 'Remis au livreur' : 'Reçu' }}</span><span>{{ money(Math.abs(r.amount_received)) }}</span></div>
          <div v-if="r.difference" :class="['flex justify-between p-2 font-semibold', r.difference < 0 ? 'bg-red-50 text-red-800' : 'bg-blue-50 text-blue-900']">
            <span>{{ r.difference < 0 ? 'Écart (retenu sur la paie)' : 'Versé en plus' }}</span><span>{{ money(r.difference) }}</span>
          </div>
        </div>

        <details class="text-sm" :open="r.collections.length <= 10">
          <summary class="cursor-pointer font-semibold">Détail des courses ({{ r.collections.length }})</summary>
          <ul class="mt-1 divide-y">
            <li v-for="c in r.collections" :key="c.id" class="flex justify-between gap-2 py-1">
              <span><span class="font-mono text-xs">{{ c.tracking_code }}</span> {{ c.recipient_name }} <span class="text-xs text-gray-500">{{ c.method_label }}</span></span>
              <span>{{ money(c.amount_collected) }}</span>
            </li>
          </ul>
        </details>

        <div v-if="r.parcels_returned.length" class="text-sm">
          <p class="font-semibold">Colis rendus à l'agence ({{ r.parcels_returned.length }})</p>
          <p class="font-mono text-xs text-gray-700">{{ r.parcels_returned.join(', ') }}</p>
        </div>
        <div v-if="r.parcels_kept.length" class="text-sm">
          <p class="font-semibold">Colis gardés par le livreur ({{ r.parcels_kept.length }})</p>
          <p class="font-mono text-xs text-gray-700">{{ r.parcels_kept.join(', ') }}</p>
        </div>
        <p v-if="r.notes" class="text-sm text-gray-700">Note : {{ r.notes }}</p>

        <div class="grid grid-cols-2 gap-6 pt-6 text-xs text-gray-600">
          <div class="border-t border-gray-400 pt-1">Signature du livreur</div>
          <div class="border-t border-gray-400 pt-1">Signature de la caisse</div>
        </div>
      </section>

      <!-- Annuler -->
      <section v-if="r.can_cancel" class="card space-y-3 border-orange-200 bg-orange-50 p-4 self-start print:hidden">
        <h2 class="font-semibold text-orange-900">Annuler ce versement</h2>
        <p class="text-sm text-orange-900">
          Possible aujourd'hui seulement. L'argent, les avances et les frais reviennent chez le livreur ; l'écart retenu sur sa
          paie est retiré. Les colis rendus restent à l'agence. L'annulation est notée dans le journal de la caisse.
        </p>
        <div>
          <label class="label" for="cancel-reason">Raison (obligatoire)</label>
          <input id="cancel-reason" v-model="reason" class="input" placeholder="Ex. : montant mal saisi">
        </div>
        <button type="button" class="btn-secondary text-red-700" :disabled="busy || !reason.trim()" @click="cancel">Annuler le versement</button>
      </section>
    </div>
  </div>
  <p v-else class="text-gray-500">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { dateTime, money } from '../../utils/format'

/**
 * Caisse › reçu d'un versement : imprimable, renvoyé au livreur par WhatsApp, annulable le jour même.
 */
const route = useRoute()
const auth = useAuthStore()
const toasts = useToastStore()
const canManage = computed(() => auth.can('finance.manage'))
const r = ref(null)
const reason = ref('')
const busy = ref(false)
const collected = computed(() => (r.value?.collections || []).reduce((s, c) => s + c.amount_collected, 0))

async function load() {
  r.value = (await http.get(`/finance/remittances/${route.params.id}`)).data.data
}

function print() {
  window.print()
}

async function resend() {
  busy.value = true
  try {
    await http.post(`/finance/remittances/${r.value.id}/receipt`)
    toasts.success('Reçu renvoyé au livreur.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function cancel() {
  if (!window.confirm(`Annuler le versement ${r.value.reference} de ${r.value.courier_name} ?`)) return
  busy.value = true
  try {
    await http.post(`/finance/remittances/${r.value.id}/cancel`, { reason: reason.value.trim() })
    toasts.success('Versement annulé.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
