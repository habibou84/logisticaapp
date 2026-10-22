<template>
  <div v-if="invoice" class="max-w-4xl mx-auto space-y-4">
    <div class="no-print flex flex-wrap items-center gap-2">
      <button class="btn-secondary" @click="router.back()">← Retour</button>
      <button class="btn-secondary" @click="print">🖨️ Imprimer / PDF</button>
      <template v-if="canManage && invoice.status === 'draft'">
        <button class="btn-secondary" :disabled="busy" @click="act('refresh', 'Facture recalculée.')">↻ Recalculer</button>
        <button class="btn-primary" :disabled="busy" @click="issue">Émettre la facture</button>
      </template>
      <button v-if="canManage && (invoice.status === 'draft' || (invoice.status === 'issued' && !invoice.amount_paid))" class="text-red-600 ml-auto" :disabled="busy" @click="voidInvoice">
        {{ invoice.status === 'draft' ? 'Supprimer le brouillon' : 'Annuler la facture' }}
      </button>
    </div>

    <p v-if="invoice.status === 'draft'" class="no-print rounded bg-slate-100 p-3 text-sm text-slate-700">
      Brouillon : recalculé à chaque « Recalculer » d'après les courses du mois (les lignes ajoutées à la main sont gardées).
      Le client ne le voit pas tant qu'il n'est pas émis.
    </p>

    <!-- Facture imprimable -->
    <div class="card p-6 space-y-5 statement">
      <div class="flex flex-wrap justify-between gap-4">
        <div>
          <p class="font-semibold">{{ company?.name }}</p>
          <p v-if="company?.address" class="text-sm text-gray-600">{{ company.address }}</p>
          <p v-if="company?.phone || company?.email" class="text-sm text-gray-600">{{ [company.phone, company.email].filter(Boolean).join(' · ') }}</p>
          <h1 class="text-xl font-bold mt-3">Facture {{ invoice.number || '(brouillon)' }}</h1>
          <p class="text-sm">{{ invoice.activity_label }} · {{ invoice.period_label }}</p>
        </div>
        <div class="text-right text-sm">
          <p class="font-semibold text-base">{{ invoice.client.business_name }}</p>
          <p v-if="invoice.client.contact_name">{{ invoice.client.contact_name }}</p>
          <p>{{ invoice.client.phone }}</p>
          <p v-if="invoice.client.email">{{ invoice.client.email }}</p>
          <p v-if="invoice.issued_at" class="mt-2">Émise le {{ date(invoice.issued_at) }}</p>
          <p v-if="invoice.due_at">À payer avant le {{ date(invoice.due_at) }}</p>
          <span :class="['inline-block mt-1 rounded-full px-2 py-0.5 text-xs font-medium', invoice.is_overdue ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-700']">{{ invoice.status_label }}</span>
        </div>
      </div>

      <table class="w-full text-sm">
        <thead class="text-left text-gray-600 border-b">
          <tr><th class="py-2">Désignation</th><th class="py-2 text-right">Quantité</th><th class="py-2 text-right">Prix unitaire</th><th class="py-2 text-right">Montant</th><th class="no-print" /></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="l in invoice.lines" :key="l.id" :class="l.kind === 'included' ? 'text-gray-500' : ''">
            <td class="py-2">{{ l.label }}</td>
            <td class="py-2 text-right">{{ l.quantity }}</td>
            <td class="py-2 text-right">{{ l.kind === 'included' ? '' : money(l.unit_price) }}</td>
            <td class="py-2 text-right">{{ l.kind === 'included' ? 'inclus' : money(l.amount) }}</td>
            <td class="py-2 text-right no-print">
              <button v-if="canManage && l.manual && invoice.status === 'draft'" class="text-red-600 text-xs" @click="removeLine(l)">Retirer</button>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="border-t font-bold"><td class="py-2" colspan="3">Total</td><td class="py-2 text-right">{{ money(invoice.total) }}</td><td class="no-print" /></tr>
          <tr v-if="invoice.amount_paid"><td class="py-1" colspan="3">Déjà payé</td><td class="py-1 text-right">− {{ money(invoice.amount_paid) }}</td><td class="no-print" /></tr>
          <tr v-if="invoice.amount_paid" class="font-semibold"><td class="py-1" colspan="3">Reste à payer</td><td class="py-1 text-right">{{ money(invoice.balance) }}</td><td class="no-print" /></tr>
        </tfoot>
      </table>

      <form v-if="canManage && invoice.status === 'draft'" class="no-print flex flex-wrap items-end gap-2 border-t pt-3" @submit.prevent="addLine">
        <div class="flex-1 min-w-48"><label class="label" for="cl-label">Ligne à ajouter (remise, frais)</label><input id="cl-label" v-model="line.label" class="input" required maxlength="255"></div>
        <div><label class="label" for="cl-qty">Quantité</label><input id="cl-qty" v-model.number="line.quantity" type="number" min="1" class="input w-20" required></div>
        <div><label class="label" for="cl-price">Prix unitaire (F, négatif = remise)</label><input id="cl-price" v-model.number="line.unit_price" type="number" class="input w-36" required></div>
        <button class="btn-secondary" :disabled="busy">+ Ajouter</button>
      </form>

      <div v-if="invoice.payments.length" class="text-sm space-y-1">
        <h2 class="font-semibold">Paiements</h2>
        <p v-for="p in invoice.payments" :key="p.id">
          {{ date(p.paid_on) }} · {{ money(p.amount) }} par {{ p.method_label }}<template v-if="p.reference"> (réf. {{ p.reference }})</template>
        </p>
      </div>

      <details class="text-sm" :open="printingAnnex">
        <summary class="font-semibold cursor-pointer">Détail des {{ invoice.orders.length }} course(s) du mois</summary>
        <div class="overflow-x-auto mt-2">
          <table class="w-full text-xs">
            <thead class="text-left text-gray-600 border-b">
              <tr><th class="py-1">Date</th><th class="py-1">Code</th><th class="py-1">Destinataire</th><th class="py-1">Destination</th><th class="py-1">Envoi</th><th class="py-1">Délai</th><th class="py-1">État</th><th class="py-1 text-right">Montant</th></tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="o in invoice.orders" :key="o.id">
                <td class="py-1">{{ date(o.created_at) }}</td>
                <td class="py-1 font-mono">{{ o.tracking_code }}</td>
                <td class="py-1">{{ o.recipient_name || '—' }}</td>
                <td class="py-1">{{ o.destination }}</td>
                <td class="py-1">{{ o.shipment_type || '—' }}<template v-if="o.weight_kg"> · {{ o.weight_kg }} kg</template></td>
                <td class="py-1">{{ o.delivery_delay || '—' }}</td>
                <td class="py-1">{{ o.status_label }}</td>
                <td class="py-1 text-right">{{ o.basis === 'forfait' ? 'forfait' : money(o.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>
    </div>

    <div v-if="canManage && invoice.status === 'issued'" class="card p-4 no-print space-y-3">
      <h2 class="font-semibold">Enregistrer un paiement</h2>
      <form class="flex flex-wrap items-end gap-2" @submit.prevent="pay">
        <div><label class="label" for="cp-amount">Montant (F)</label><input id="cp-amount" v-model.number="payment.amount" type="number" min="1" :max="invoice.balance" class="input w-36" required></div>
        <div>
          <label class="label" for="cp-method">Moyen</label>
          <select id="cp-method" v-model="payment.method" class="input w-auto">
            <option value="bank_transfer">Virement</option>
            <option value="cheque">Chèque</option>
            <option value="mobile_money">Mobile Money</option>
            <option value="cash">Espèces</option>
            <option value="other">Autre</option>
          </select>
        </div>
        <div><label class="label" for="cp-ref">Référence</label><input id="cp-ref" v-model="payment.reference" class="input w-40" maxlength="100"></div>
        <div><label class="label" for="cp-date">Date</label><input id="cp-date" v-model="payment.paid_on" type="date" :max="today()" class="input w-auto" required></div>
        <button class="btn-primary" :disabled="busy">Enregistrer</button>
      </form>
    </div>
  </div>
  <p v-else class="text-gray-500">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date, money, today } from '../../utils/format'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const invoice = ref(null)
const busy = ref(false)
const printingAnnex = ref(false)
const company = computed(() => auth.user?.company)
const canManage = computed(() => !auth.user?.merchant_id && auth.can('invoices.manage'))
const line = reactive({ label: '', quantity: 1, unit_price: 0 })
const payment = reactive({ amount: null, method: 'bank_transfer', reference: '', paid_on: today() })

async function load() {
  invoice.value = (await http.get(`/client-invoices/${route.params.id}`)).data.data
  payment.amount = invoice.value.balance || null
}

async function run(request, success) {
  busy.value = true
  try {
    const { data } = await request()
    if (data.data === null) {
      toasts.success(success)
      router.replace(auth.user?.merchant_id ? '/marchand/factures' : '/admin/factures')
      return
    }
    invoice.value = data.data
    payment.amount = invoice.value.balance || null
    toasts.success(success)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

const act = (action, success) => run(() => http.post(`/client-invoices/${invoice.value.id}/${action}`), success)

function issue() {
  if (!window.confirm(`Émettre la facture de ${money(invoice.value.total)} ? Elle reçoit son numéro et ne se modifie plus.`)) return
  act('issue', 'Facture émise.')
}

function voidInvoice() {
  const draft = invoice.value.status === 'draft'
  if (!window.confirm(draft ? 'Supprimer ce brouillon ? Il pourra être recalculé.' : 'Annuler cette facture ? Elle garde son numéro, marquée annulée.')) return
  act('void', draft ? 'Brouillon supprimé.' : 'Facture annulée.')
}

async function addLine() {
  await run(() => http.post(`/client-invoices/${invoice.value.id}/lines`, { ...line }), 'Ligne ajoutée.')
  Object.assign(line, { label: '', quantity: 1, unit_price: 0 })
}

const removeLine = (l) => run(() => http.delete(`/client-invoices/${invoice.value.id}/lines/${l.id}`), 'Ligne retirée.')

async function pay() {
  await run(() => http.post(`/client-invoices/${invoice.value.id}/payments`, { ...payment, reference: payment.reference || null }), 'Paiement enregistré.')
  payment.reference = ''
}

function print() {
  printingAnnex.value = true
  setTimeout(() => window.print(), 50)
}

onMounted(load)
</script>

<style>
@media print {
  .no-print, header, aside, nav { display: none !important; }
  .statement { box-shadow: none; border: none; }
}
</style>
