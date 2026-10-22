<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Facturation</h1>
      <form class="flex items-center gap-2" @submit.prevent="generate">
        <label class="text-sm text-gray-600" for="g-period">Factures du mois</label>
        <input id="g-period" v-model="period" type="month" class="input w-auto" required>
        <button class="btn-secondary" :disabled="generating">{{ generating ? 'Génération…' : 'Générer' }}</button>
      </form>
    </div>

    <div v-if="totals" class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <StatCard title="Revenu mensuel (hors essais)" :value="money(totals.mrr)" icon="📈" />
      <StatCard title="Abonnées · en essai" :value="`${totals.subscribed} · ${totals.on_trial}`" icon="🏢" />
      <StatCard title="Encaissé ce mois" :value="money(totals.collected)" icon="💰" />
      <StatCard title="Reste à encaisser" :value="money(totals.outstanding)" icon="🧾" />
      <StatCard title="Factures en retard" :value="totals.overdue_invoices" icon="⏰" />
    </div>

    <div class="flex flex-wrap gap-2">
      <select v-model="status" class="input w-auto" aria-label="Statut" @change="load">
        <option value="">Toutes les factures</option>
        <option value="open">À payer</option>
        <option value="overdue">En retard</option>
        <option value="paid">Payées</option>
        <option value="void">Annulées</option>
      </select>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr>
            <th class="p-2">Facture</th><th class="p-2">Entreprise</th><th class="p-2">Mois</th>
            <th class="p-2 text-right">Total</th><th class="p-2 text-right">Reste</th><th class="p-2">Échéance</th><th class="p-2">Statut</th><th class="p-2" />
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="i in invoices" :key="i.id">
            <td class="p-2 font-mono"><RouterLink :to="`/console/factures/${i.id}`" class="text-blue-600">{{ i.number }}</RouterLink></td>
            <td class="p-2"><RouterLink :to="`/console/entreprises/${i.company?.id}`" class="hover:underline">{{ i.company?.name }}</RouterLink></td>
            <td class="p-2">{{ i.period }}</td>
            <td class="p-2 text-right">{{ money(i.total) }}</td>
            <td class="p-2 text-right font-semibold">{{ i.status === 'void' ? '—' : money(i.balance) }}</td>
            <td class="p-2">{{ date(i.due_at) }}</td>
            <td class="p-2"><InvoiceStatus :invoice="i" /></td>
            <td class="p-2 text-right whitespace-nowrap">
              <button v-if="i.status === 'open'" class="btn-primary !py-1" @click="openPay(i)">Encaisser</button>
              <button v-if="i.status === 'open' && !i.amount_paid" class="ml-1 text-xs text-red-600" @click="voidInvoice(i)">Annuler</button>
            </td>
          </tr>
          <tr v-if="loaded && !invoices.length"><td colspan="8" class="p-6 text-center text-gray-500">Aucune facture.</td></tr>
        </tbody>
      </table>
    </div>

    <form class="card p-4 space-y-3" @submit.prevent="saveSettings">
      <h2 class="font-semibold">Mentions des factures</h2>
      <div>
        <label class="label" for="s-issuer">Émetteur (première ligne : nom ; puis adresse, téléphone, RCCM…)</label>
        <textarea id="s-issuer" v-model="settings.invoice_issuer" class="input" rows="3" maxlength="1000" placeholder="Jibiat&#10;Abidjan, Cocody&#10;+225 07 00 00 00 00" />
      </div>
      <div>
        <label class="label" for="s-pay">Comment payer (affiché sur les factures et dans « Mon abonnement »)</label>
        <textarea id="s-pay" v-model="settings.payment_instructions" class="input" rows="3" maxlength="2000" placeholder="Orange Money : 07 00 00 00 00 (Jibiat)&#10;Wave : 05 00 00 00 00" />
      </div>
      <button class="btn-primary" :disabled="savingSettings">Enregistrer</button>
    </form>

    <Modal :open="pay.open" :title="`Encaisser ${pay.invoice?.number}`" @close="pay.open = false">
      <form class="space-y-3" @submit.prevent="savePayment">
        <p class="text-sm text-gray-600">{{ pay.invoice?.company?.name }} · reste à payer {{ money(pay.invoice?.balance) }}</p>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label" for="p-amount">Montant (F) *</label><input id="p-amount" v-model.number="pay.amount" type="number" min="1" :max="pay.invoice?.balance" class="input" required></div>
          <div><label class="label" for="p-date">Date *</label><input id="p-date" v-model="pay.paid_on" type="date" class="input" required></div>
          <div>
            <label class="label" for="p-method">Moyen *</label>
            <select id="p-method" v-model="pay.method" class="input">
              <option v-for="(label, key) in methods" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>
          <div><label class="label" for="p-ref">Référence</label><input id="p-ref" v-model.trim="pay.reference" class="input" maxlength="100" placeholder="N° de transaction"></div>
        </div>
        <p v-if="pay.error" class="field-error">{{ pay.error }}</p>
        <button class="btn-primary w-full" :disabled="pay.saving">Enregistrer le paiement</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import InvoiceStatus from '../../components/InvoiceStatus.vue'
import Modal from '../../components/Modal.vue'
import StatCard from '../../components/StatCard.vue'
import { useToastStore } from '../../stores/toasts'
import { date, money, today } from '../../utils/format'

const toasts = useToastStore()
const invoices = ref([])
const totals = ref(null)
const methods = ref({})
const loaded = ref(false)
const status = ref('')
const period = ref('')
const generating = ref(false)
const settings = reactive({ invoice_issuer: '', payment_instructions: '' })
const savingSettings = ref(false)
const pay = reactive({ open: false })

async function load() {
  const { data } = await http.get('/console/invoices', { params: { status: status.value || undefined } })
  invoices.value = data.data
  totals.value = data.meta.totals
  methods.value = data.meta.methods
  if (!period.value) period.value = data.meta.previous_period
  loaded.value = true
}

async function generate() {
  generating.value = true
  try {
    const { data } = await http.post('/console/invoices/generate', { period: period.value })
    toasts.success(`${data.data.created} facture(s) créée(s) pour ${period.value}.`)
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    generating.value = false
  }
}

function openPay(invoice) {
  Object.assign(pay, { open: true, invoice, amount: invoice.balance, paid_on: today(), method: 'mobile_money', reference: '', error: '', saving: false })
}

async function savePayment() {
  pay.saving = true
  pay.error = ''
  try {
    await http.post(`/console/invoices/${pay.invoice.id}/payments`, {
      amount: pay.amount, paid_on: pay.paid_on, method: pay.method, reference: pay.reference || null,
    })
    pay.open = false
    toasts.success('Paiement enregistré.')
    await load()
  } catch (e) {
    pay.error = apiErrorMessage(e)
  } finally {
    pay.saving = false
  }
}

async function voidInvoice(invoice) {
  const reason = window.prompt(`Annuler la facture ${invoice.number} ? Motif (facultatif) :`)
  if (reason === null) return
  try {
    await http.post(`/console/invoices/${invoice.id}/void`, { reason: reason || null })
    toasts.success('Facture annulée.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function saveSettings() {
  savingSettings.value = true
  try {
    const { data } = await http.put('/console/settings', { invoice_issuer: settings.invoice_issuer || null, payment_instructions: settings.payment_instructions || null })
    Object.assign(settings, { invoice_issuer: data.data.invoice_issuer || '', payment_instructions: data.data.payment_instructions || '' })
    toasts.success('Mentions enregistrées.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    savingSettings.value = false
  }
}

onMounted(async () => {
  await load()
  const { data } = await http.get('/console/settings')
  Object.assign(settings, { invoice_issuer: data.data.invoice_issuer || '', payment_instructions: data.data.payment_instructions || '' })
})
</script>
