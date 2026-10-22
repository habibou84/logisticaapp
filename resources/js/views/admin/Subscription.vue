<template>
  <div class="max-w-3xl space-y-4">
    <h1 class="text-xl font-bold">Mon abonnement</h1>

    <template v-if="summary">
      <!-- À payer -->
      <div v-if="summary.billing.amount_due" :class="['card p-4 space-y-2', summary.billing.state === 'ok' ? '' : 'ring-2', ringClass]">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="font-semibold">Reste à payer : {{ money(summary.billing.amount_due) }}</p>
          <p v-if="summary.billing.state === 'restricted'" class="text-sm font-medium text-red-700">Création de courses suspendue jusqu'au paiement</p>
          <p v-else-if="summary.billing.days_late" class="text-sm font-medium text-amber-700">En retard de {{ summary.billing.days_late }} jour{{ summary.billing.days_late > 1 ? 's' : '' }}</p>
        </div>
        <div v-if="summary.payment_instructions" class="rounded bg-slate-50 p-3 text-sm">
          <p class="font-medium mb-1">Comment payer</p>
          <p class="whitespace-pre-line text-gray-700">{{ summary.payment_instructions }}</p>
          <p class="mt-1 text-gray-500">Indiquez le numéro de la facture avec votre paiement.</p>
        </div>
      </div>

      <div class="card p-4">
        <SubscriptionSummary :summary="summary" />
      </div>

      <div class="card overflow-x-auto">
        <h2 class="font-semibold p-4 pb-2">Factures</h2>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Facture</th><th class="p-2">Mois</th><th class="p-2 text-right">Total</th><th class="p-2 text-right">Reste</th><th class="p-2">Échéance</th><th class="p-2">Statut</th><th class="p-2" /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="i in summary.invoices" :key="i.id">
              <td class="p-2 font-mono"><RouterLink :to="`/admin/abonnement/factures/${i.id}`" class="text-blue-600">{{ i.number }}</RouterLink></td>
              <td class="p-2">{{ i.period }}</td>
              <td class="p-2 text-right">{{ money(i.total) }}</td>
              <td class="p-2 text-right">{{ money(i.balance) }}</td>
              <td class="p-2">{{ date(i.due_at) }}</td>
              <td class="p-2"><InvoiceStatus :invoice="i" /></td>
              <td class="p-2 text-right whitespace-nowrap">
                <template v-if="summary.wave && i.status === 'open' && i.balance > 0">
                  <span v-if="pendingFor(i)?.claimed_at" class="text-xs text-gray-500">Paiement Wave en vérification</span>
                  <button v-else class="btn-primary !py-1" @click="openWave(i)">Payer avec Wave</button>
                </template>
              </td>
            </tr>
            <tr v-if="!summary.invoices.length"><td colspan="7" class="p-4 text-center text-gray-500">Aucune facture pour l'instant.</td></tr>
          </tbody>
        </table>
      </div>
    </template>
    <p v-else class="text-gray-500">Chargement…</p>

    <Modal :open="wave.open" title="Payer avec Wave" @close="wave.open = false">
      <form v-if="!wave.payment" class="space-y-3" @submit.prevent="startWave">
        <p class="text-sm text-gray-600">Facture {{ wave.invoice?.number }} · {{ wave.invoice?.period }}</p>
        <div>
          <label class="label" for="w-phone">Numéro Wave qui va payer *</label>
          <input id="w-phone" v-model.trim="wave.phone" type="tel" class="input" placeholder="07 00 00 00 00" required>
        </div>
        <p v-if="wave.error" class="field-error">{{ wave.error }}</p>
        <button class="btn-primary w-full" :disabled="wave.busy">{{ wave.busy ? 'Un instant…' : 'Continuer' }}</button>
      </form>
      <div v-else class="space-y-5 text-sm">
        <div class="rounded-lg bg-slate-50 p-4 text-center">
          <p class="text-2xl font-bold">{{ money(wave.payment.amount) }} à payer</p>
          <p class="mt-1 text-gray-600">Depuis le numéro Wave {{ wave.payment.payer_phone }}</p>
          <p class="mt-1 text-xs text-gray-500">Facture {{ wave.invoice?.number }} · {{ wave.invoice?.period }}</p>
        </div>

        <section class="space-y-3">
          <h3 class="flex items-center gap-2 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-sky-600 text-xs text-white">1</span>Payez avec Wave</h3>
          <!-- Téléphone : le bouton d'abord ; ordinateur : le code QR d'abord (on paie avec son téléphone) -->
          <div class="flex flex-col gap-3">
            <a v-if="wave.payment.link" :href="wave.payment.link" target="_blank" rel="noopener" class="order-1 flex w-full justify-center rounded-lg bg-sky-600 px-4 py-3 font-semibold text-white hover:bg-sky-700 md:order-2">Cliquez ici pour payer avec Wave</a>
            <div v-if="summary?.wave?.qr_url" class="order-2 flex flex-col items-center gap-2 md:order-1">
              <p class="text-gray-600">{{ wave.payment.link ? 'ou scannez ce code QR avec l\'application Wave' : 'Scannez ce code QR avec l\'application Wave' }}</p>
              <img :src="summary.wave.qr_url" alt="Code QR Wave de Jibiat" class="h-52 w-52 rounded bg-white object-contain ring-1 ring-slate-200">
            </div>
          </div>
        </section>

        <section class="space-y-2 border-t pt-4">
          <h3 class="flex items-center gap-2 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-700 text-xs text-white">2</span>Confirmez votre paiement</h3>
          <p class="text-gray-600">Une fois le paiement fait dans Wave :</p>
          <p v-if="wave.error" class="field-error">{{ wave.error }}</p>
          <button class="w-full rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white hover:bg-emerald-800 disabled:opacity-60" :disabled="wave.busy" @click="claimWave">{{ wave.busy ? 'Envoi…' : 'Cliquez ici pour confirmer votre paiement' }}</button>
          <p class="text-xs text-gray-500">Nous vérifions la réception, puis le paiement est enregistré sur la facture.</p>
        </section>
      </div>
    </Modal>

    <p class="text-sm text-gray-500">
      Pour changer de formule, ajouter des livreurs au-delà de la limite ou activer un module, contactez-nous.
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import InvoiceStatus from '../../components/InvoiceStatus.vue'
import Modal from '../../components/Modal.vue'
import { useToastStore } from '../../stores/toasts'
import SubscriptionSummary from '../../components/SubscriptionSummary.vue'
import { date, money } from '../../utils/format'

const summary = ref(null)

const ringClass = computed(() => ({
  due: 'ring-amber-300', warning: 'ring-amber-500', restricted: 'ring-red-500',
})[summary.value?.billing.state] ?? '')

const toasts = useToastStore()

async function load() {
  summary.value = (await http.get('/subscription')).data.data
}

onMounted(load)

// Paiement par le lien marchand Wave : numéro qui paie, puis lien ou QR code, puis « J'ai payé »
const wave = reactive({ open: false })
const pendingFor = (invoice) => summary.value?.wave_pending?.find((p) => p.invoice_id === invoice.id)

function openWave(invoice) {
  const pending = pendingFor(invoice)
  Object.assign(wave, { open: true, invoice, phone: pending?.payer_phone ?? '', payment: pending ?? null, error: '', busy: false })
}

async function startWave() {
  wave.busy = true
  wave.error = ''
  try {
    wave.payment = (await http.post(`/subscription/invoices/${wave.invoice.id}/wave`, { payer_phone: wave.phone })).data.data
  } catch (e) {
    wave.error = apiErrorMessage(e)
  } finally {
    wave.busy = false
  }
}

async function claimWave() {
  wave.busy = true
  wave.error = ''
  try {
    await http.post(`/subscription/wave-payments/${wave.payment.id}/paid`)
    wave.open = false
    toasts.success(`Merci ! Nous vérifions votre paiement de ${money(wave.payment.amount)}. Il sera enregistré sur la facture dès qu'il est confirmé.`)
    await load()
  } catch (e) {
    wave.error = apiErrorMessage(e)
  } finally {
    wave.busy = false
  }
}
</script>
