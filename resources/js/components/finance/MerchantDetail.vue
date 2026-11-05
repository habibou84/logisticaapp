<template>
  <!-- Détail d'un client pour la caisse : à payer par date, versements avec leurs courses, grand livre -->
  <Modal open wide :title="`${merchant.business_name} · détail`" @close="$emit('close')">
    <div class="-mx-4 -mt-4 mb-4 flex gap-1 overflow-x-auto border-b px-3 pt-1 text-sm" role="tablist">
      <button v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
              :class="['whitespace-nowrap border-b-2 px-3 py-2', tab === t.key ? 'border-slate-900 font-semibold' : 'border-transparent text-gray-500']" @click="tab = t.key">
        {{ t.label }}
      </button>
    </div>

    <p v-if="error" class="rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
    <p v-else-if="!data" class="text-sm text-gray-500">Chargement…</p>

    <!-- À payer -->
    <div v-else-if="tab === 'unpaid'" class="space-y-3 text-sm">
      <div class="grid grid-cols-3 gap-2">
        <div class="rounded-lg bg-emerald-50 p-2"><p class="text-xs text-emerald-800">Prêt à payer</p><p class="font-bold tabular-nums text-emerald-700">{{ money(data.available) }}</p></div>
        <div :class="['rounded-lg p-2', data.pending_cash ? 'bg-amber-50' : 'bg-slate-50']"><p :class="['text-xs', data.pending_cash ? 'text-amber-800' : 'text-gray-500']">Chez les livreurs</p><p :class="['font-bold tabular-nums', data.pending_cash ? 'text-amber-800' : '']">{{ money(data.pending_cash) }}</p></div>
        <div class="rounded-lg bg-slate-50 p-2"><p class="text-xs text-gray-500">Payer avec</p><p class="truncate font-semibold">{{ merchant.payment_method ? merchant.payment_method.summary : '⚠️ aucun moyen' }}<template v-if="merchant.payment_method?.status === 'verified'"> ✔</template></p></div>
      </div>

      <div v-for="d in data.days" :key="d.day || 'none'" class="overflow-hidden rounded-xl border">
        <button type="button" :class="['flex w-full items-center gap-2 px-3 py-2 text-left', isChosen(d.day) ? 'bg-amber-50' : 'bg-white hover:bg-slate-50']" :aria-expanded="opened.has(d.day)" @click="toggle(d.day)">
          <span class="min-w-0 flex-1 font-semibold">
            {{ opened.has(d.day) ? '▾' : '▸' }} <span class="first-letter:uppercase">{{ d.day ? dayLabel(d.day) : 'Sans date de livraison' }}</span>
            <span v-if="isChosen(d.day)" class="text-xs font-normal text-amber-700"> · date choisie</span>
          </span>
          <span class="whitespace-nowrap text-xs text-gray-500">{{ d.count }} course{{ d.count > 1 ? 's' : '' }}</span>
          <span class="w-28 whitespace-nowrap text-right font-bold tabular-nums">{{ money(d.net) }}</span>
        </button>
        <div v-if="opened.has(d.day)" class="overflow-x-auto border-t">
          <table class="w-full text-xs">
            <thead class="bg-slate-50 text-left font-semibold text-slate-600">
              <tr><th class="px-3 py-1.5">Course</th><th class="px-3 py-1.5">Destinataire</th><th class="px-3 py-1.5 text-right">Encaissé</th><th class="px-3 py-1.5 text-right">Frais</th><th class="px-3 py-1.5 text-right">Net</th><th class="px-3 py-1.5">Argent</th></tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="o in d.orders" :key="o.order_id">
                <td class="whitespace-nowrap px-3 py-1.5 font-mono"><RouterLink :to="`/admin/courses/${o.order_id}`" class="hover:underline">{{ o.tracking_code }}</RouterLink></td>
                <td class="px-3 py-1.5">{{ o.recipient || '—' }}</td>
                <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ number(o.collected) }}</td>
                <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ o.fees ? number(o.fees) : '—' }}<span v-if="o.other" class="block text-gray-500">autres {{ number(o.other) }}</span></td>
                <td class="whitespace-nowrap px-3 py-1.5 text-right font-semibold tabular-nums">{{ number(o.net) }}</td>
                <td :class="['whitespace-nowrap px-3 py-1.5', WHERE[o.where].cls]">{{ WHERE[o.where].icon }} {{ o.where === 'courier' ? (o.courier || 'livreur') : WHERE[o.where].label }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="data.others.length" class="rounded-xl border px-3 py-2">
        <p class="mb-1 text-xs font-semibold text-gray-600">Sans course (ajustements, stockage…)</p>
        <p v-for="o in data.others" :key="o.id" class="flex justify-between text-xs"><span>{{ o.label }} <span class="text-gray-400">· {{ dateTime(o.created_at) }}</span></span><span class="tabular-nums">{{ money(o.amount) }}</span></p>
      </div>
      <p v-if="!data.days.length && !data.others.length" class="text-gray-500">Rien à payer pour le moment.</p>

      <div v-if="canManage && data.available > 0" class="flex flex-wrap gap-2 pt-1">
        <button v-if="periodKey !== 'all' && chosenReady" type="button" class="btn-primary flex-1" @click="$emit('pay', false)">Payer {{ chosenDate }} · {{ money(chosenReady) }} prêts</button>
        <button type="button" :class="['flex-1', periodKey === 'all' || !chosenReady ? 'btn-primary' : 'btn-secondary']" @click="$emit('pay', true)">Payer tout · {{ money(data.available) }} prêts</button>
      </div>
      <p v-if="data.pending_cash" class="text-xs text-gray-500">L'argent encore chez un livreur sera payé au prochain versement, quand il arrivera à la caisse.</p>
    </div>

    <!-- Versements -->
    <div v-else-if="tab === 'payouts'" class="space-y-2 text-sm">
      <p v-if="!data.payouts.length" class="text-gray-500">Aucun versement pour le moment.</p>
      <div v-for="p in data.payouts" :key="p.id" class="overflow-hidden rounded-xl border">
        <button type="button" class="flex w-full flex-wrap items-center gap-2 px-3 py-2 text-left hover:bg-slate-50" :aria-expanded="openedPayouts.has(p.id)" @click="togglePayout(p.id)">
          <span class="min-w-0 flex-1">
            <b>{{ openedPayouts.has(p.id) ? '▾' : '▸' }} {{ p.reference }}</b>
            <span class="text-gray-600"> · {{ p.paid_at ? `payé le ${dateTime(p.paid_at)}` : `préparé le ${dateTime(p.created_at)}` }}</span>
            <span :class="['ml-1 whitespace-nowrap rounded-full px-2 text-xs', STATUS[p.status] || 'bg-slate-100 text-slate-700']">{{ p.status_label }}<template v-if="p.method"> · {{ p.method }}</template></span>
          </span>
          <span class="whitespace-nowrap text-xs text-gray-500">{{ p.days.length }} date{{ p.days.length > 1 ? 's' : '' }} · {{ p.days.reduce((n, d) => n + d.count, 0) }} courses</span>
          <span class="w-28 whitespace-nowrap text-right font-bold tabular-nums">{{ money(p.net_amount) }}</span>
        </button>
        <div v-if="openedPayouts.has(p.id)" class="space-y-1 border-t bg-slate-50/60 px-3 py-2 text-xs">
          <template v-for="d in p.days" :key="d.day || 'none'">
            <p class="flex justify-between font-semibold"><span class="first-letter:uppercase">{{ d.day ? dayLabel(d.day) : 'Sans date' }} · {{ d.count }} course{{ d.count > 1 ? 's' : '' }}</span><span class="tabular-nums">{{ money(d.net) }}</span></p>
            <p class="break-words pl-3 font-mono text-gray-600">{{ d.codes.join(' · ') }}</p>
          </template>
          <p v-if="p.others" class="flex justify-between"><span>Ajustements et autres lignes</span><span class="tabular-nums">{{ money(p.others) }}</span></p>
          <p class="pt-1 text-gray-500">
            Préparé par {{ p.created_by || '—' }}<template v-if="p.paid_by"> · payé par {{ p.paid_by }}</template><template v-if="p.transaction_ref"> · réf. {{ p.transaction_ref }}</template>
            · <RouterLink :to="`/admin/caisse/reversements/${p.id}`" class="text-blue-600 hover:underline">Ouvrir ›</RouterLink>
          </p>
        </div>
      </div>
      <p v-if="data.payouts.length >= 20" class="text-xs text-gray-500">Les 20 derniers versements. Les plus anciens sont dans l'onglet « Paiements aux clients ».</p>
    </div>

    <!-- Grand livre -->
    <div v-else-if="tab === 'ledger'">
      <LedgerTable :entries="ledger.entries" by-day />
      <button v-if="ledger.page < ledger.lastPage" type="button" class="btn-secondary mt-2 w-full" @click="moreLedger">Voir les lignes plus anciennes ({{ ledger.total - ledger.entries.length }})</button>
      <form v-if="canManage" class="mt-4 space-y-2 border-t pt-3" @submit.prevent="saveAdjust">
        <p class="text-sm font-medium">Ajustement (+ en faveur de le client, − à sa charge)</p>
        <div class="flex flex-wrap gap-2">
          <input v-model.number="adjust.amount" type="number" class="input w-32" placeholder="Montant" required>
          <input v-model="adjust.description" class="input min-w-40 flex-1" placeholder="Motif" required>
          <button class="btn-secondary" :disabled="adjust.busy">Ajouter</button>
        </div>
        <p v-if="adjust.error" class="field-error">{{ adjust.error }}</p>
      </form>
    </div>
  </Modal>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import LedgerTable from '../LedgerTable.vue'
import Modal from '../Modal.vue'
import { dateTime, money } from '../../utils/format'

const props = defineProps({
  merchant: { type: Object, required: true },
  canManage: { type: Boolean, default: false },
  inPeriod: { type: Function, required: true },
  periodKey: { type: String, default: 'all' },
  chosenDate: { type: String, default: '' },
  dayLabel: { type: Function, required: true },
})
const emit = defineEmits(['close', 'pay', 'changed'])

const WHERE = {
  desk: { icon: '✓', label: 'caisse', cls: 'text-emerald-700' },
  courier: { icon: '🛵', label: 'livreur', cls: 'text-amber-700' },
  unverified: { icon: '⏳', label: 'code à vérifier', cls: 'text-red-700' },
  paid: { icon: '✓', label: 'payé', cls: 'text-gray-500' },
}
const STATUS = { paid: 'bg-emerald-100 text-emerald-800', draft: 'bg-amber-100 text-amber-800', cancelled: 'bg-slate-100 text-slate-500 line-through' }

const tab = ref('unpaid')
const data = ref(null)
const error = ref('')
const opened = ref(new Set())
const openedPayouts = ref(new Set())
const ledger = reactive({ entries: [], page: 1, lastPage: 1, total: 0, loaded: false })
const adjust = reactive({ amount: null, description: '', error: '', busy: false })

const number = (n) => new Intl.NumberFormat('fr-FR').format(n)
const isChosen = (day) => props.periodKey !== 'all' && !!day && props.inPeriod(day)
const chosenReady = computed(() => (data.value?.days || []).filter((d) => isChosen(d.day)).reduce((n, d) => n + d.available, 0))
const tabs = computed(() => [
  { key: 'unpaid', label: `À payer${data.value ? ` · ${money(data.value.unpaid_total)}` : ''}` },
  { key: 'payouts', label: `Versements${data.value ? ` (${data.value.payouts.length})` : ''}` },
  { key: 'ledger', label: 'Grand livre' },
])

function toggle(day) {
  const next = new Set(opened.value)
  next.has(day) ? next.delete(day) : next.add(day)
  opened.value = next
}
function togglePayout(id) {
  const next = new Set(openedPayouts.value)
  next.has(id) ? next.delete(id) : next.add(id)
  openedPayouts.value = next
}

async function load() {
  try {
    data.value = (await http.get(`/finance/merchants/${props.merchant.merchant_id}/statement`)).data.data
    // Dates choisies dépliées d'emblée ; sinon la plus récente
    const chosen = data.value.days.filter((d) => isChosen(d.day)).map((d) => d.day)
    opened.value = new Set(chosen.length ? chosen : data.value.days.slice(0, 1).map((d) => d.day))
    if (data.value.payouts[0]) openedPayouts.value = new Set([data.value.payouts[0].id])
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function loadLedger(page = 1) {
  const { data: res } = await http.get(`/finance/merchants/${props.merchant.merchant_id}/ledger`, { params: { page } })
  ledger.entries = page === 1 ? res.data : [...ledger.entries, ...res.data]
  Object.assign(ledger, { page: res.meta.current_page, lastPage: res.meta.last_page, total: res.meta.total, loaded: true })
}
const moreLedger = () => loadLedger(ledger.page + 1).catch((e) => { error.value = apiErrorMessage(e) })

async function saveAdjust() {
  adjust.error = ''
  adjust.busy = true
  try {
    await http.post(`/finance/merchants/${props.merchant.merchant_id}/adjustments`, { amount: adjust.amount, description: adjust.description })
    Object.assign(adjust, { amount: null, description: '' })
    await Promise.all([loadLedger(), load()])
    emit('changed')
  } catch (e) {
    adjust.error = apiErrorMessage(e)
  } finally {
    adjust.busy = false
  }
}

watch(tab, (t) => { if (t === 'ledger' && !ledger.loaded) moreLedgerFirst() })
const moreLedgerFirst = () => loadLedger().catch((e) => { error.value = apiErrorMessage(e) })
onMounted(load)
</script>
