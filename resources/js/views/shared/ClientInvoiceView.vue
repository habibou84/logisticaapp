<template>
  <div v-if="invoice" class="max-w-4xl mx-auto space-y-4">
    <div class="no-print flex flex-wrap items-center gap-2">
      <button class="btn-secondary" @click="router.back()">← Retour</button>
      <button class="btn-secondary" @click="print">🖨️ Imprimer / PDF</button>
      <button class="btn-secondary" :disabled="busy" @click="exportCsv">⬇️ Excel (par centre de coûts)</button>
      <template v-if="canManage && invoice.status === 'draft'">
        <button class="btn-secondary" :disabled="busy" @click="act('refresh', 'Facture recalculée.')">↻ Recalculer</button>
        <button class="btn-primary" :disabled="busy" @click="issue">Émettre la facture</button>
      </template>
      <button v-if="canCredit" class="btn-secondary" :disabled="busy" @click="openCredit">🧾 Faire un avoir</button>
      <button v-if="canManage && (invoice.status === 'draft' || (invoice.status === 'issued' && !invoice.amount_paid && !invoice.credited_amount && auth.can('invoices.void')))" class="text-red-600 ml-auto" :disabled="busy" @click="voidInvoice">
        {{ invoice.status === 'draft' ? 'Supprimer le brouillon' : 'Annuler la facture' }}
      </button>
    </div>

    <!-- Facture normalisée (FNE) : à certifier (FNE injoignable) ou refusée (données à corriger) -->
    <div v-if="fneProblem" class="no-print rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 flex flex-wrap items-center gap-2">
      <span class="flex-1">⚠️ {{ fneProblem }}</span>
      <button v-if="canManage" class="btn-secondary" :disabled="busy" @click="act('certify', 'Certification envoyée.')">Certifier maintenant</button>
    </div>

    <p v-if="invoice.status === 'draft'" class="no-print rounded bg-slate-100 p-3 text-sm text-slate-700">
      Brouillon : recalculé à chaque « Recalculer » d'après les courses du mois (les lignes ajoutées à la main sont gardées).
      Le client ne le voit pas tant qu'il n'est pas émis.
    </p>

    <!-- Facture imprimable -->
    <div :class="['card p-6 space-y-5 statement', printingNote ? 'print:hidden' : '']">
      <div class="flex flex-wrap justify-between gap-4">
        <div>
          <p class="font-semibold">{{ invoice.issuer?.legal_name || company?.name }}</p>
          <p v-if="company?.address" class="text-sm text-gray-600">{{ company.address }}</p>
          <p v-if="issuerFiscal" class="text-xs text-gray-600">{{ issuerFiscal }}</p>
          <p v-if="company?.phone || company?.email" class="text-sm text-gray-600">{{ [company.phone, company.email].filter(Boolean).join(' · ') }}</p>
          <h1 class="text-xl font-bold mt-3">Facture {{ invoice.number || '(brouillon)' }}</h1>
          <p class="text-sm">{{ invoice.activity_label }} · {{ invoice.period_label }}</p>
          <p v-if="invoice.department" class="text-sm font-medium">Département : {{ invoice.department.label }}<template v-if="invoice.department.cost_center"> · centre de coûts {{ invoice.department.cost_center }}</template></p>
        </div>
        <div class="text-right text-sm">
          <p class="font-semibold text-base">{{ invoice.client.legal_name || invoice.client.business_name }}</p>
          <p v-if="invoice.client.legal_name && invoice.client.legal_name !== invoice.client.business_name" class="text-gray-600">{{ invoice.client.business_name }}</p>
          <p v-if="invoice.client.ncc || invoice.client.rccm" class="text-xs">{{ [invoice.client.ncc && `NCC ${invoice.client.ncc}`, invoice.client.rccm && `RCCM ${invoice.client.rccm}`].filter(Boolean).join(' · ') }}</p>
          <p v-if="invoice.client.address" class="text-xs text-gray-600">{{ invoice.client.address }}</p>
          <p v-if="invoice.client.contact_name">{{ invoice.client.contact_name }}</p>
          <p>{{ invoice.client.phone }}</p>
          <p v-if="invoice.client.email">{{ invoice.client.email }}</p>
          <p v-if="invoice.issued_at" class="mt-2">Émise le {{ date(invoice.issued_at) }}</p>
          <p v-if="invoice.due_at">À payer avant le {{ date(invoice.due_at) }}</p>
          <span :class="['inline-block mt-1 rounded-full px-2 py-0.5 text-xs font-medium', invoice.is_overdue ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-700']">{{ invoice.status_label }}</span>
        </div>
      </div>
      <FneSticker v-if="invoice.fne?.status === 'certified'" :fne="invoice.fne" />

      <table class="w-full text-sm">
        <thead class="text-left text-gray-600 border-b">
          <tr><th class="py-2">Désignation</th><th class="py-2 text-right">Quantité</th><th class="py-2 text-right">Prix unitaire{{ basisLabel }}</th><th class="py-2 text-right">Montant{{ basisLabel }}</th><th class="no-print" /></tr>
        </thead>
        <!-- Facture ventilée : une section par département, avec son sous-total -->
        <tbody v-for="g in groups" :key="g.key" class="divide-y">
          <tr v-if="g.department" class="bg-slate-50">
            <td class="py-2 font-semibold" colspan="3">🏢 {{ g.department.label }}<span v-if="g.department.cost_center" class="font-normal text-gray-600"> · centre de coûts {{ g.department.cost_center }}</span></td>
            <td class="py-2 text-right font-semibold">{{ money(g.department.total) }}</td><td class="no-print" />
          </tr>
          <tr v-for="l in g.lines" :key="l.id" :class="l.kind === 'included' ? 'text-gray-500' : ''">
            <td :class="['py-2', g.department ? 'pl-4' : '']">{{ l.label }}</td>
            <td class="py-2 text-right">{{ l.quantity }}</td>
            <td class="py-2 text-right">{{ l.kind === 'included' ? '' : money(l.unit_price) }}</td>
            <td class="py-2 text-right">{{ l.kind === 'included' ? 'inclus' : money(l.amount) }}</td>
            <td class="py-2 text-right no-print">
              <button v-if="canManage && l.manual && invoice.status === 'draft'" class="text-red-600 text-xs" @click="removeLine(l)">Retirer</button>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <template v-if="hasVat">
            <tr class="border-t"><td class="py-1" colspan="3">Total hors taxe</td><td class="py-1 text-right">{{ money(invoice.total_ht) }}</td><td class="no-print" /></tr>
            <tr><td class="py-1" colspan="3">TVA {{ rate(invoice.vat_rate) }}</td><td class="py-1 text-right">{{ money(invoice.vat_amount) }}</td><td class="no-print" /></tr>
          </template>
          <tr class="border-t font-bold"><td class="py-2" colspan="3">Total{{ hasVat ? ' TTC' : '' }}</td><td class="py-2 text-right">{{ money(invoice.total) }}</td><td class="no-print" /></tr>
          <tr v-if="invoice.vat_exemption"><td class="py-1 text-xs text-gray-600" colspan="4">{{ invoice.vat_exemption }}</td><td class="no-print" /></tr>
          <tr v-if="invoice.credited_amount"><td class="py-1" colspan="3">Avoirs ({{ invoice.credit_notes.map((n) => n.number).join(', ') }})</td><td class="py-1 text-right">− {{ money(invoice.credited_amount) }}</td><td class="no-print" /></tr>
          <tr v-if="invoice.amount_paid"><td class="py-1" colspan="3">Déjà payé</td><td class="py-1 text-right">− {{ money(invoice.amount_paid) }}</td><td class="no-print" /></tr>
          <tr v-if="invoice.amount_paid || invoice.credited_amount" class="font-semibold"><td class="py-1" colspan="3">Reste à payer</td><td class="py-1 text-right">{{ money(invoice.balance) }}</td><td class="no-print" /></tr>
          <tr v-if="invoice.refund_due" class="font-semibold text-amber-700"><td class="py-1" colspan="3">À rembourser au client</td><td class="py-1 text-right">{{ money(invoice.refund_due) }}</td><td class="no-print" /></tr>
        </tfoot>
      </table>

      <form v-if="canManage && invoice.status === 'draft'" class="no-print flex flex-wrap items-end gap-2 border-t pt-3" @submit.prevent="addLine">
        <div class="flex-1 min-w-48"><label class="label" for="cl-label">Ligne à ajouter (remise, frais)</label><input id="cl-label" v-model="line.label" class="input" required maxlength="255"></div>
        <div><label class="label" for="cl-qty">Quantité</label><input id="cl-qty" v-model.number="line.quantity" type="number" min="1" class="input w-20" required></div>
        <div><label class="label" for="cl-price">Prix unitaire (F, négatif = remise)</label><input id="cl-price" v-model.number="line.unit_price" type="number" class="input w-36" required></div>
        <div v-if="invoice.departments?.length">
          <label class="label" for="cl-dep">Département</label>
          <select id="cl-dep" v-model="line.department_id" class="input w-44">
            <option v-for="d in invoice.departments" :key="d.id ?? 'none'" :value="d.id">{{ d.label }}</option>
          </select>
        </div>
        <button class="btn-secondary" :disabled="busy">+ Ajouter</button>
      </form>

      <!-- Comment payer : moyens choisis par l'entreprise (Réglages › Entreprise) -->
      <div v-if="(invoice.payment_methods?.length || invoice.payment_info) && invoice.status !== 'void' && invoice.status !== 'paid'" class="rounded border border-slate-200 bg-slate-50 p-3 text-sm space-y-2">
        <p class="font-semibold">Comment payer</p>
        <div v-if="invoice.payment_methods?.length" class="grid sm:grid-cols-2 gap-2">
          <div v-for="(m, i) in invoice.payment_methods" :key="i" class="rounded bg-white border border-slate-200 p-2">
            <p class="font-medium">{{ ICONS[m.method] }} {{ m.title }}</p>
            <!-- Libellé au-dessus de la valeur : un IBAN tient sur une ligne, sans être coupé -->
            <div v-for="l in m.lines" :key="l.label" class="mt-1">
              <p class="text-xs text-gray-500">{{ l.label }}</p>
              <p class="flex items-baseline justify-between gap-2">
                <span :class="l.copy ? 'font-mono tracking-tight' : ''">{{ l.value }}</span>
                <button v-if="l.copy" type="button" class="no-print shrink-0 text-xs text-blue-600" @click="copy(l.value)">Copier</button>
              </p>
            </div>
          </div>
        </div>
        <p v-if="invoice.payment_info" class="whitespace-pre-line text-gray-700">{{ invoice.payment_info }}</p>
        <p class="text-xs text-gray-500">Indiquez le numéro {{ invoice.number }} en référence du paiement.</p>
      </div>

      <div v-if="invoice.credit_notes?.length" class="text-sm space-y-1">
        <h2 class="font-semibold">Avoirs</h2>
        <div v-for="n in invoice.credit_notes" :key="n.id" class="flex flex-wrap items-baseline gap-x-2">
          <span class="font-mono">{{ n.number }}</span>
          <span>· {{ date(n.issued_at) }} · {{ money(n.total) }}{{ hasVat ? ' TTC' : '' }} · {{ n.reason }}</span>
          <span v-if="n.refund_due" class="text-amber-700">· {{ money(n.refund_due) }} à rembourser</span>
          <span v-if="n.fne?.reference" class="text-gray-600">· FNE {{ n.fne.reference }}</span>
          <span v-else-if="n.fne" class="text-amber-700">· {{ n.fne.status === 'failed' ? 'refusé par la FNE' : 'à certifier' }}</span>
          <button type="button" class="no-print text-xs text-blue-600" @click="printNote(n)">🖨️ Imprimer l'avoir</button>
        </div>
      </div>

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
              <tr><th class="py-1">Date</th><th class="py-1">Code</th><th v-if="hasDepartments" class="py-1">Département</th><th class="py-1">Destinataire</th><th class="py-1">Destination</th><th class="py-1">Envoi</th><th class="py-1">Délai</th><th class="py-1">État</th><th class="py-1 text-right">Montant</th></tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="o in invoice.orders" :key="o.id">
                <td class="py-1">{{ date(o.created_at) }}</td>
                <td class="py-1 font-mono">{{ o.tracking_code }}</td>
                <td v-if="hasDepartments" class="py-1">{{ o.department || '—' }}<span v-if="o.requested_by" class="block text-gray-500">{{ o.requested_by }}</span></td>
                <td class="py-1">{{ o.recipient_name || '—' }}</td>
                <td class="py-1">{{ o.destination }}</td>
                <td class="py-1">{{ o.units > 1 ? `${o.units} × ` : '' }}{{ o.shipment_type || '—' }}<template v-if="o.weight_kg"> · {{ o.weight_kg }} kg</template></td>
                <td class="py-1">{{ o.delivery_delay || '—' }}</td>
                <td class="py-1">{{ o.status_label }}</td>
                <td class="py-1 text-right">{{ o.basis === 'forfait' ? 'forfait' : money(o.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>
    </div>

    <!-- Avoir imprimable (seul à l'impression) -->
    <div v-if="printingNote" class="hidden print:block p-6 space-y-5 text-sm">
      <div class="flex flex-wrap justify-between gap-4">
        <div>
          <p class="font-semibold">{{ invoice.issuer?.legal_name || company?.name }}</p>
          <p v-if="company?.address" class="text-gray-600">{{ company.address }}</p>
          <p v-if="issuerFiscal" class="text-xs text-gray-600">{{ issuerFiscal }}</p>
          <h1 class="text-xl font-bold mt-3">Avoir {{ printingNote.number }}</h1>
          <p>Sur la facture {{ invoice.number }} · {{ invoice.activity_label }} · {{ invoice.period_label }}</p>
          <p>Motif : {{ printingNote.reason }}</p>
        </div>
        <div class="text-right">
          <p class="font-semibold text-base">{{ invoice.client.legal_name || invoice.client.business_name }}</p>
          <p v-if="invoice.client.ncc" class="text-xs">NCC {{ invoice.client.ncc }}</p>
          <p v-if="invoice.client.address" class="text-xs text-gray-600">{{ invoice.client.address }}</p>
          <p class="mt-2">Le {{ date(printingNote.issued_at) }}</p>
        </div>
      </div>
      <FneSticker v-if="printingNote.fne?.status === 'certified'" :fne="printingNote.fne" credit />
      <table class="w-full">
        <thead class="text-left text-gray-600 border-b">
          <tr><th class="py-2">Désignation</th><th class="py-2 text-right">Quantité</th><th class="py-2 text-right">Prix unitaire{{ basisLabel }}</th><th class="py-2 text-right">Montant{{ basisLabel }}</th></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="(l, i) in printingNote.lines" :key="i"><td class="py-2">{{ l.label }}</td><td class="py-2 text-right">{{ l.quantity }}</td><td class="py-2 text-right">{{ money(l.unit_price) }}</td><td class="py-2 text-right">{{ money(l.amount) }}</td></tr>
        </tbody>
        <tfoot>
          <template v-if="hasVat">
            <tr class="border-t"><td class="py-1" colspan="3">Total hors taxe</td><td class="py-1 text-right">{{ money(printingNote.total_ht) }}</td></tr>
            <tr><td class="py-1" colspan="3">TVA {{ rate(printingNote.vat_rate) }}</td><td class="py-1 text-right">{{ money(printingNote.vat_amount) }}</td></tr>
          </template>
          <tr class="border-t font-bold"><td class="py-2" colspan="3">Total de l'avoir{{ hasVat ? ' TTC' : '' }}</td><td class="py-2 text-right">{{ money(printingNote.total) }}</td></tr>
          <tr v-if="printingNote.refund_due"><td class="py-1" colspan="3">Remboursé au client</td><td class="py-1 text-right">{{ money(printingNote.refund_due) }}</td></tr>
        </tfoot>
      </table>
    </div>

    <Modal :open="credit.open" title="Faire un avoir" wide @close="credit.open = false">
      <form class="space-y-3" @submit.prevent="createCredit">
        <p class="text-sm text-gray-600">L'avoir réduit ce que le client doit ; si la facture est déjà payée, la différence est à lui rembourser. Reste sur la facture : <b>{{ money(remaining) }}</b>.</p>
        <div class="flex gap-4 text-sm">
          <label class="flex items-center gap-1.5"><input v-model="credit.full" type="radio" :value="true"> Avoir total (tout le reste)</label>
          <label class="flex items-center gap-1.5"><input v-model="credit.full" type="radio" :value="false"> Avoir partiel</label>
        </div>
        <template v-if="!credit.full">
          <table class="w-full text-sm">
            <thead class="text-left text-gray-600 border-b"><tr><th class="py-1">Ligne</th><th class="py-1 text-right">Facturé</th><th class="py-1 text-right">À reprendre</th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="l in creditableLines" :key="l.id">
                <td class="py-1">{{ l.label }} <span class="text-gray-500">· {{ money(l.unit_price) }}</span></td>
                <td class="py-1 text-right">{{ l.quantity }}<span v-if="l.credited_quantity" class="text-gray-500"> ({{ l.credited_quantity }} déjà repris)</span></td>
                <td class="py-1 text-right"><input v-model.number="credit.quantities[l.id]" type="number" min="0" :max="l.quantity - l.credited_quantity" class="input w-20 text-right" :aria-label="`Quantité reprise : ${l.label}`"></td>
              </tr>
            </tbody>
          </table>
          <p v-if="fneCertified" class="text-xs text-gray-500">Facture normalisée (FNE) : l'avoir reprend des lignes de la facture, en tout ou en partie.</p>
          <div v-else class="flex flex-wrap items-end gap-2">
            <div class="flex-1 min-w-48"><label class="label" for="cr-extra">Montant libre (geste commercial, erreur…)</label><input id="cr-extra" v-model="credit.extra_label" class="input" maxlength="255" placeholder="Libellé"></div>
            <div><label class="label" for="cr-amount">Montant{{ basisLabel }} (F)</label><input id="cr-amount" v-model.number="credit.extra_amount" type="number" min="0" class="input w-36"></div>
          </div>
        </template>
        <div><label class="label" for="cr-reason">Motif de l'avoir *</label><input id="cr-reason" v-model="credit.reason" class="input" maxlength="500" required placeholder="Ex. : plis déjà réceptionnés, contrat résilié"></div>
        <p class="text-sm font-semibold">Avoir : {{ money(creditPreview) }}{{ hasVat ? ' TTC' : '' }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="busy || !creditPreview || creditPreview > remaining">Émettre l'avoir</button>
      </form>
    </Modal>

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
import Modal from '../../components/Modal.vue'
import FneSticker from '../../components/FneSticker.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const invoice = ref(null)
const busy = ref(false)
const printingAnnex = ref(false)
const company = computed(() => auth.user?.company)
const canManage = computed(() => !auth.user?.merchant_id && auth.can('invoices.manage'))
const line = reactive({ label: '', quantity: 1, unit_price: 0, department_id: null })
// Lignes groupées par département (facture ventilée), sinon un seul groupe
const groups = computed(() => {
  const deps = invoice.value?.departments || []
  if (!deps.length) return [{ key: 'all', department: null, lines: invoice.value?.lines || [] }]
  return deps.map((d) => ({ key: d.id ?? 'none', department: d, lines: invoice.value.lines.filter((l) => (l.department_id ?? null) === (d.id ?? null)) }))
})
// TVA : affichée dès qu'un taux s'applique ; base des prix saisis (HT ou TTC)
const hasVat = computed(() => invoice.value?.vat_rate > 0)
const basisLabel = computed(() => (hasVat.value ? (invoice.value.vat_basis === 'ttc' ? ' TTC' : ' HT') : ''))
const rate = (r) => `${String(Math.round(r * 100) / 100).replace('.', ',')} %`
const issuerFiscal = computed(() => {
  const i = invoice.value?.issuer || {}
  return [i.ncc && `NCC ${i.ncc}`, i.rccm && `RCCM ${i.rccm}`, i.tax_regime, i.tax_office && `Centre des impôts : ${i.tax_office}`].filter(Boolean).join(' · ')
})

// Facture normalisée (FNE)
const fneCertified = computed(() => invoice.value?.fne?.status === 'certified')
const fneProblem = computed(() => {
  const items = [invoice.value?.fne, ...(invoice.value?.credit_notes || []).map((n) => n.fne && { ...n.fne, note: n.number })]
    .filter((f) => f && f.status !== 'certified')
  if (!items.length) return ''
  return items.map((f) => `${f.note ? `Avoir ${f.note}` : 'Facture'} ${f.status === 'failed' ? `refusé${f.note ? '' : 'e'} par la FNE` : `pas encore certifié${f.note ? '' : 'e'}`}${f.error ? ` : ${f.error}` : ''}`).join(' · ')
})

// Avoirs : droit « Annuler une facture émise ou faire un avoir »
const remaining = computed(() => (invoice.value ? invoice.value.total - invoice.value.credited_amount : 0))
const canCredit = computed(() => canManage.value && auth.can('invoices.void') && ['issued', 'paid'].includes(invoice.value?.status) && remaining.value > 0)
const credit = reactive({ open: false, full: false, reason: '', quantities: {}, extra_label: '', extra_amount: null })
const creditableLines = computed(() => (invoice.value?.lines || []).filter((l) => l.amount > 0 && l.quantity > l.credited_quantity))
const creditPreview = computed(() => {
  if (credit.full) return remaining.value
  const sum = creditableLines.value.reduce((t, l) => t + (Number(credit.quantities[l.id]) || 0) * l.unit_price, 0) + (Number(credit.extra_amount) || 0)
  const r = invoice.value?.vat_rate || 0
  return invoice.value?.vat_basis === 'ttc' || !r ? sum : sum + Math.round(sum * r / 100)
})
function openCredit() {
  Object.assign(credit, { open: true, full: false, reason: '', quantities: {}, extra_label: '', extra_amount: null })
}
async function createCredit() {
  const payload = {
    reason: credit.reason,
    full: credit.full,
    lines: credit.full ? [] : Object.entries(credit.quantities).filter(([, q]) => q > 0).map(([id, q]) => ({ line_id: Number(id), quantity: q })),
    extra: !credit.full && credit.extra_amount > 0 ? { label: credit.extra_label || 'Avoir', amount: credit.extra_amount } : null,
  }
  await run(() => http.post(`/client-invoices/${invoice.value.id}/credit-notes`, payload), 'Avoir émis.')
  credit.open = false
}
const printingNote = ref(null)
function printNote(n) {
  printingNote.value = n
  setTimeout(() => {
    window.print()
    printingNote.value = null
  }, 50)
}
const hasDepartments = computed(() => (invoice.value?.orders || []).some((o) => o.department))

async function exportCsv() {
  try {
    const { data } = await http.get(`/client-invoices/${invoice.value.id}/export`, { responseType: 'blob' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(data)
    a.download = `facture-${invoice.value.number || invoice.value.id}.csv`
    a.click()
    URL.revokeObjectURL(a.href)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}
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
  Object.assign(line, { label: '', quantity: 1, unit_price: 0, department_id: line.department_id })
}

const ICONS = { bank_transfer: '🏦', mobile_money: '📱', cheque: '🧾', cash: '💵', other: '💳' }

// IBAN ou numéro copié pour le coller dans l'application de la banque ou du Mobile Money
async function copy(value) {
  try {
    await navigator.clipboard.writeText(value.replace(/\s+/g, ''))
    toasts.success('Copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le texte.')
  }
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
