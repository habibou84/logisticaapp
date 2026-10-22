<template>
  <div v-if="mission" class="space-y-4 pb-36">
    <DispatchMessages :order-id="order.id" />

    <!-- Où aller -->
    <section class="m-card p-4 space-y-2">
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold uppercase tracking-wide text-[var(--app-color)]">{{ mission.type_label }}</span>
        <StatusBadge :status="order.status" :label="order.status_label" />
      </div>
      <p v-if="mission.offline" class="rounded-xl bg-sky-50 text-sky-900 p-3 text-sm">
        ⏳ Enregistré sur votre téléphone : envoi automatique au retour du réseau.
      </p>
      <p v-if="shipping" class="rounded-xl bg-indigo-50 text-indigo-900 p-3 font-medium">
        🚌 Expédition : déposez le colis à la gare ou chez le transporteur, payez l'envoi et gardez le ticket.
      </p>
      <p v-if="mission.gain" class="text-sm font-semibold text-emerald-700">💰 Votre gain prévu : + {{ money(mission.gain) }}</p>
      <p class="text-xl font-bold">{{ target.name }}</p>
      <p class="text-slate-700">📍 {{ target.zone }}<span v-if="target.address"> · {{ target.address }}</span></p>
      <p v-if="target.landmark" class="text-slate-500">Repère : {{ target.landmark }}</p>
      <p v-if="mission.type === 'delivery' && order.delivery.scheduled_date" class="text-sm text-slate-500">Prévue le {{ date(order.delivery.scheduled_date) }} {{ order.delivery.time_slot ? `(${order.delivery.time_slot}h)` : '' }}</p>

      <div class="grid grid-cols-3 gap-2 pt-2">
        <a :href="telLink(target.phone)" class="tap rounded-2xl bg-slate-100 py-3 flex flex-col items-center gap-1 text-sm font-medium active:bg-slate-200">
          <span class="text-2xl">📞</span>Appeler
        </a>
        <a :href="whatsappLink(target.phone)" target="_blank" class="tap rounded-2xl bg-slate-100 py-3 flex flex-col items-center gap-1 text-sm font-medium active:bg-slate-200">
          <span class="text-2xl">💬</span>WhatsApp
        </a>
        <a :href="mapsLink(target.lat, target.lng, `${target.address || ''} ${target.zone}`)" target="_blank" class="tap rounded-2xl bg-slate-100 py-3 flex flex-col items-center gap-1 text-sm font-medium active:bg-slate-200">
          <span class="text-2xl">🗺️</span>Itinéraire
        </a>
      </div>
      <a v-if="target.phone2" :href="telLink(target.phone2)" class="block text-center text-sm text-[var(--app-color)] font-medium pt-1">📞 Autre numéro : {{ target.phone2 }}</a>
    </section>

    <!-- Argent et colis -->
    <section class="m-card p-4 space-y-2">
      <div v-if="mission.type !== 'pickup' && !(shipping && !order.amounts.cod_amount)" class="flex items-center justify-between">
        <span class="text-slate-600">À encaisser</span>
        <span class="text-3xl font-bold">{{ money(order.amounts.cod_amount) }}</span>
      </div>
      <p v-if="order.from_warehouse && mission.type === 'delivery' && ['delivery_assigned', 'at_hub'].includes(order.status)" class="rounded-xl bg-sky-50 p-3 text-sky-900">
        🏬 À récupérer à l'entrepôt <strong>{{ order.pickup.hub_name }}</strong><span v-if="order.pickup.address"> · {{ order.pickup.address }}</span>
      </p>
      <ul v-if="order.items?.length" class="text-slate-700 space-y-0.5">
        <li v-for="item in order.items" :key="item.id">📦 <strong>{{ item.quantity }} ×</strong> {{ item.label }}</li>
      </ul>
      <p v-else class="text-slate-700">📦 {{ order.package.description || 'Colis' }}</p>
      <div class="flex flex-wrap gap-2">
        <span v-if="order.package.is_fragile" class="rounded-full bg-red-100 text-red-700 text-sm font-semibold px-3 py-1">🥚 Fragile</span>
        <span v-if="order.package.is_express" class="rounded-full bg-amber-100 text-amber-800 text-sm font-semibold px-3 py-1">⚡ Express</span>
        <span v-if="order.attempts_count" class="rounded-full bg-slate-100 text-slate-700 text-sm px-3 py-1">Tentative {{ order.attempts_count + 1 }} / {{ order.max_attempts }}</span>
      </div>
      <p v-if="order.package.merchant_note" class="rounded-xl bg-amber-50 p-3 text-amber-900">📝 {{ order.package.merchant_note }}</p>
      <p class="font-mono text-xs text-slate-400">{{ order.tracking_code }}<span v-if="mission.type === 'pickup'"> · {{ order.merchant?.business_name }}</span></p>
    </section>

    <!-- Frais déclarés -->
    <section v-if="order.expenses?.length" class="m-card p-4 space-y-2">
      <h2 class="font-semibold">Mes frais sur cette course</h2>
      <div v-for="e in order.expenses" :key="e.id" class="flex justify-between gap-3 text-sm">
        <span class="text-slate-600">{{ e.description }}</span>
        <span class="font-semibold whitespace-nowrap">{{ money(e.amount) }}</span>
      </div>
      <p class="text-xs text-slate-500">Remboursés sur votre prochain versement à la caisse.</p>
    </section>

    <!-- Actions -->
    <div class="fixed bottom-16 inset-x-0 z-20 bg-white border-t pb-safe">
      <div class="mx-auto max-w-lg p-3 space-y-2">
        <template v-if="mission.status === 'assigned'">
          <div class="grid grid-cols-3 gap-2">
            <button class="m-btn-secondary" @click="sheet = 'refuse'">Refuser</button>
            <button class="m-btn-success col-span-2" :disabled="busy" @click="accept">✓ Accepter</button>
          </div>
        </template>
        <template v-else-if="active">
          <button v-if="primary" :class="[primary.class, 'text-lg py-5']" :disabled="busy" @click="primary.action()">{{ primary.label }}</button>
          <div class="grid grid-cols-4 gap-2">
            <button v-if="canReportIncident" class="m-btn-secondary py-3 px-1 text-sm text-red-600" @click="openIncident">⚠️ Problème</button>
            <button class="m-btn-secondary py-3 px-1 text-sm" @click="sheet = 'note'">💬 Note</button>
            <button class="m-btn-secondary py-3 px-1 text-sm" @click="openExpense">💸 Frais</button>
            <label class="m-btn-secondary py-3 px-1 text-sm cursor-pointer">
              📷 Photo
              <input type="file" accept="image/*" capture="environment" class="hidden" @change="uploadPhoto">
            </label>
          </div>
          <!-- Mission confiée d'office, pas encore commencée : le livreur peut la rendre -->
          <button v-if="mission.can_report_impediment" class="w-full text-center text-sm text-slate-500 underline py-1" @click="sheet = 'refuse'">
            Je ne peux pas faire cette mission
          </button>
        </template>
      </div>
    </div>

    <!-- Livraison réussie (ou dépôt à la gare pour une expédition) -->
    <BottomSheet :open="sheet === 'delivered'" :title="shipping ? 'Colis expédié' : 'Colis livré'" @close="sheet = null">
      <div class="space-y-4">
        <template v-if="shipping">
          <div>
            <label class="m-label" for="carrier">Compagnie ou gare</label>
            <input id="carrier" v-model="delivery.shipping_carrier" class="m-input" list="carriers" placeholder="Ex. : UTB Adjamé" autocomplete="off">
            <datalist id="carriers"><option v-for="c in recentCarriers" :key="c" :value="c" /></datalist>
          </div>
          <div>
            <label class="m-label" for="shipfee">Frais d'expédition payés</label>
            <div class="relative">
              <input id="shipfee" v-model.number="delivery.shipping_fee" type="number" min="0" inputmode="numeric" class="m-input text-2xl font-bold pr-12">
              <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 font-semibold">F</span>
            </div>
            <p class="text-sm text-slate-500 mt-1">Facturés au marchand et déduits de ce que vous versez à la caisse.</p>
          </div>
          <div>
            <label class="m-label" for="ticket">Numéro du ticket</label>
            <input id="ticket" v-model="delivery.shipping_reference" class="m-input" placeholder="Facultatif">
          </div>
          <label class="m-btn-secondary cursor-pointer">
            📷 Photographier le ticket
            <input type="file" accept="image/*" capture="environment" class="hidden" @change="uploadPhoto">
          </label>
        </template>
        <div v-if="!shipping || order.amounts.cod_amount > 0">
          <label class="m-label" for="collected">Montant encaissé</label>
          <div class="relative">
            <input id="collected" v-model.number="delivery.collected_amount" type="number" min="0" inputmode="numeric" class="m-input text-2xl font-bold pr-12">
            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 font-semibold">F</span>
          </div>
          <p v-if="delivery.collected_amount !== order.amounts.cod_amount" class="text-sm text-amber-700 mt-1">Attendu : {{ money(order.amounts.cod_amount) }}</p>
        </div>
        <div v-if="delivery.collected_amount > 0">
          <p class="m-label">Le client a payé en</p>
          <ChoiceChips v-model="delivery.payment_method" :options="paymentOptions" :columns="2" label="Mode de paiement" />
        </div>
        <template v-if="delivery.collected_amount > 0 && delivery.payment_method !== 'cash'">
          <ChoiceChips
            v-model="delivery.received_by_company"
            :options="[
              { value: false, label: 'Sur mon téléphone', description: 'je le verserai à la caisse' },
              { value: true, label: 'Sur le compte de l\'agence', description: 'rien à verser' },
            ]"
            label="Paiement reçu"
          />
          <input v-model="delivery.transaction_ref" class="m-input" placeholder="Référence de la transaction (facultatif)">
        </template>
        <div v-if="auth.user?.company?.require_delivery_code && !shipping">
          <label class="m-label" for="code">Code donné par le client</label>
          <input id="code" v-model="delivery.delivery_code" class="m-input text-center text-3xl font-bold tracking-[0.5em]" inputmode="numeric" maxlength="4" autocomplete="one-time-code" placeholder="••••">
          <p v-if="offlineEnabled && !pwa.online" class="text-xs text-slate-500 mt-1">Hors connexion : le code sera vérifié au retour du réseau. S'il est faux, l'agence vérifiera la livraison auprès du client.</p>
        </div>
        <p v-if="sheetError" class="text-red-600 text-sm">{{ sheetError }}</p>
        <button class="m-btn-success text-lg py-5" :disabled="busy" @click="confirmDelivered">{{ shipping ? '✓ Confirmer l\'expédition' : '✓ Confirmer la livraison' }}</button>
      </div>
    </BottomSheet>

    <!-- Problème -->
    <BottomSheet :open="sheet === 'incident'" title="Que se passe-t-il ?" @close="sheet = null">
      <div class="space-y-4">
        <ChoiceChips v-model="incident.reason" :options="reasonOptions" label="Motif" />
        <div v-if="selectedReason?.requires_date">
          <p class="m-label">Nouvelle date demandée par le client</p>
          <ChoiceChips v-model="incident.when" :options="dateOptions" :columns="3" label="Date" />
          <input v-if="incident.when === 'other'" v-model="incident.date" type="date" :min="today()" class="m-input mt-2" aria-label="Date">
        </div>
        <textarea v-model="incident.note" rows="2" class="m-input" placeholder="Précisez (facultatif) : ex. téléphone éteint, 3 appels" />
        <p class="text-xs text-slate-500">L'agence et le marchand sont prévenus immédiatement.</p>
        <p v-if="sheetError" class="text-red-600 text-sm">{{ sheetError }}</p>
        <button class="m-btn-danger" :disabled="busy || !incident.reason" @click="confirmIncident">Envoyer</button>
      </div>
    </BottomSheet>

    <!-- Frais payés pour la course -->
    <BottomSheet :open="sheet === 'expense'" title="J'ai payé des frais" @close="sheet = null">
      <div class="space-y-4">
        <ChoiceChips v-model="expense.type" :options="expenseOptions" :columns="2" label="Type de frais" />
        <input v-model="expense.label" class="m-input" :placeholder="expense.type === 'other' ? 'Précisez (obligatoire)' : 'Précision (facultatif) : ex. taxi Adjamé → Cocody'">
        <div>
          <label class="m-label" for="expense-amount">Montant payé</label>
          <div class="relative">
            <input id="expense-amount" v-model.number="expense.amount" type="number" min="1" inputmode="numeric" class="m-input text-2xl font-bold pr-12">
            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 font-semibold">F</span>
          </div>
        </div>
        <p class="text-sm text-slate-500">Payé de votre poche ou avec une avance de la caisse : le montant est déduit de votre prochain versement et facturé au marchand.</p>
        <label class="m-btn-secondary cursor-pointer">
          📷 Photographier le reçu
          <input type="file" accept="image/*" capture="environment" class="hidden" @change="uploadPhoto">
        </label>
        <p v-if="sheetError" class="text-red-600 text-sm">{{ sheetError }}</p>
        <button class="m-btn-primary" :disabled="busy || !expense.type || !(expense.amount > 0)" @click="saveExpense">Enregistrer les frais</button>
      </div>
    </BottomSheet>

    <!-- Refus de mission -->
    <BottomSheet :open="sheet === 'refuse'" :title="impediment ? 'Que se passe-t-il ?' : 'Pourquoi refusez-vous ?'" @close="sheet = null">
      <div class="space-y-4">
        <p v-if="impediment" class="text-sm text-slate-600">La mission passe à votre remplaçant ou revient à l'agence, qui est prévenue.</p>
        <ChoiceChips v-model="refusal" :options="refusalOptions" label="Raison" />
        <button class="m-btn-danger" :disabled="busy || !refusal" @click="refuse">{{ impediment ? 'Rendre la mission' : 'Refuser la mission' }}</button>
      </div>
    </BottomSheet>

    <!-- Note -->
    <BottomSheet :open="sheet === 'note'" title="Note pour l'agence et le marchand" @close="sheet = null">
      <div class="space-y-3">
        <textarea v-model="noteText" rows="3" class="m-input" placeholder="Ex. : le client demande de rappeler après 17h" />
        <button class="m-btn-primary" :disabled="busy || !noteText.trim()" @click="sendNote">Envoyer</button>
      </div>
    </BottomSheet>
  </div>
  <p v-else class="text-center text-slate-400 py-10">Chargement…</p>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import StatusBadge from '../../components/StatusBadge.vue'
import DispatchMessages from '../../components/mobile/DispatchMessages.vue'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import { useAuthStore } from '../../stores/auth'
import { cachedGet } from '../../composables/useCachedApi'
import { useToastStore } from '../../stores/toasts'
import { currentPosition } from '../../composables/useGeolocation'
import { enqueue, isNetworkError, sendStatus, withPending } from '../../composables/useOfflineQueue'
import { pwa } from '../../composables/usePwa'
import { date, DELIVERY_PAYMENT_METHODS, mapsLink, money, PAYMENT_METHODS, telLink, today, whatsappLink } from '../../utils/format'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

const mission = ref(null)
const order = computed(() => mission.value?.order)
const impediment = computed(() => mission.value?.status === 'accepted')
const reasons = ref([])
const busy = ref(false)
const sheet = ref(null)
const sheetError = ref('')
const noteText = ref('')
const refusal = ref(null)
const delivery = reactive({
  collected_amount: 0, payment_method: 'cash', received_by_company: false, transaction_ref: '', delivery_code: '',
  shipping_carrier: '', shipping_fee: 0, shipping_reference: '',
})
const shipping = computed(() => order.value?.is_shipping && mission.value?.type === 'delivery')
const CARRIERS_KEY = 'recent_carriers'
const recentCarriers = ref(readCarriers())

function readCarriers() {
  try {
    return JSON.parse(localStorage.getItem(CARRIERS_KEY)) || []
  } catch {
    return []
  }
}
const incident = reactive({ reason: null, when: 'tomorrow', date: null, note: '' })
const expense = reactive({ type: 'transport', label: '', amount: null })
const expenseOptions = [
  { value: 'transport', label: 'Transport', icon: '🚕' },
  { value: 'packaging', label: 'Emballage', icon: '📦' },
  { value: 'parking', label: 'Parking, péage', icon: '🅿️' },
  { value: 'shipping', label: 'Gare', icon: '🚌' },
  { value: 'other', label: 'Autre', icon: '➕' },
]

const active = computed(() => ['accepted', 'in_progress'].includes(mission.value?.status))
// Mode hors connexion de l'entreprise : actions gardées sur le téléphone sans réseau
const offlineEnabled = computed(() => !!auth.user?.company?.offline_courier_actions)
const canReportIncident = computed(() => ['pickup_assigned', 'pickup_in_progress', 'out_for_delivery'].includes(order.value?.status))

// Coordonnées utiles selon le type de mission : marchand (ramassage) ou client (livraison, retour au marchand)
const target = computed(() => {
  const o = order.value
  const toMerchant = mission.value.type !== 'delivery'
  return toMerchant
    ? { name: o.merchant?.business_name || o.pickup.contact_name, phone: o.pickup.phone, phone2: null, zone: o.pickup.zone_name, address: o.pickup.address, landmark: o.pickup.landmark, lat: o.pickup.lat, lng: o.pickup.lng }
    : { name: o.recipient.name || 'Client', phone: o.recipient.phone, phone2: o.recipient.phone2, zone: o.delivery.zone_name, address: o.delivery.address, landmark: o.delivery.landmark, lat: o.delivery.lat, lng: o.delivery.lng }
})

// Prochaine étape selon le type de mission et l'avancement
const primary = computed(() => {
  const s = order.value.status
  const t = mission.value.type
  if (t === 'pickup' && s === 'pickup_assigned') return { label: '🛵 Je pars chercher le colis', class: 'm-btn-primary', action: () => move('pickup_in_progress') }
  if (t === 'pickup' && ['pickup_assigned', 'pickup_in_progress'].includes(s)) return { label: '✓ J\'ai récupéré le colis', class: 'm-btn-success', action: () => move('picked_up') }
  if (t === 'delivery' && ['delivery_assigned', 'picked_up', 'at_hub', 'delivery_failed', 'rescheduled'].includes(s)) return { label: shipping.value ? '🚌 Je pars à la gare' : '🛵 Je pars livrer', class: 'm-btn-primary', action: () => move('out_for_delivery') }
  if (t === 'delivery' && s === 'out_for_delivery') return { label: shipping.value ? '✓ Colis expédié' : '✓ Colis livré', class: 'm-btn-success', action: openDelivered }
  if (t === 'return' && s === 'return_assigned') return { label: '🛵 Je rapporte le colis', class: 'm-btn-primary', action: () => move('returning') }
  if (t === 'return' && ['return_assigned', 'returning'].includes(s)) return { label: '✓ Colis rendu au marchand', class: 'm-btn-success', action: () => move('returned') }
  return null
})

const stage = computed(() => (mission.value?.type === 'pickup' ? 'pickup' : 'delivery'))
const reasonOptions = computed(() => reasons.value
  .filter((r) => r.applies_to === 'both' || r.applies_to === stage.value)
  .map((r) => ({ value: r.id, label: r.label })))
const selectedReason = computed(() => reasons.value.find((r) => r.id === incident.reason))

const paymentOptions = DELIVERY_PAYMENT_METHODS.map((m) => ({
  value: m,
  label: PAYMENT_METHODS[m],
  icon: { cash: '💵', wave: '🌊', orange_money: '🟠', mtn_momo: '🟡', moov_money: '🔵' }[m],
}))
const refusalOptions = [
  { value: 'Moto en panne', label: '🔧 Moto en panne' },
  { value: 'Trop loin de ma position', label: '📍 Trop loin de ma position' },
  { value: 'Trop de missions en cours', label: '📦 Trop de missions en cours' },
  { value: 'Pas disponible', label: '⏸️ Pas disponible' },
]
const dayOffset = (n) => {
  const d = new Date(Date.now() + n * 86400000)
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 10)
}
const dateOptions = [
  { value: 'tomorrow', label: 'Demain' },
  { value: 'after', label: 'Après-demain' },
  { value: 'other', label: 'Autre' },
]

async function load() {
  let data
  try {
    ;({ data } = await cachedGet('/courier/missions'))
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return
  }
  mission.value = withPending(data.data).find((m) => m.id === Number(route.params.id)) ?? null
  // Mission terminée ou retirée : retour à la liste
  if (!mission.value) router.replace('/livreur')
}

async function post(status, extra = {}) {
  const position = await currentPosition()
  const result = await sendStatus(order.value, { status, ...extra, ...(position || {}) }, offlineEnabled.value)
  if (result.offline) mission.value = withPending([mission.value])[0]
  return result
}

function keep(kind, payload) {
  enqueue({ kind, order_id: order.value.id, tracking_code: order.value.tracking_code, payload })
  return { offline: true }
}

const OFFLINE_SAVED = 'Enregistré sur le téléphone : envoi automatique au retour du réseau.'

async function move(status) {
  busy.value = true
  try {
    const result = await post(status)
    toasts.success(result.offline ? OFFLINE_SAVED : result.data.data.status_label)
    if (!result.offline) await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

function openDelivered() {
  Object.assign(delivery, {
    collected_amount: order.value.amounts.cod_amount, payment_method: 'cash', received_by_company: false, transaction_ref: '', delivery_code: '',
    shipping_carrier: recentCarriers.value[0] || '', shipping_fee: order.value.shipping?.fee_estimate ?? 0, shipping_reference: '',
  })
  sheetError.value = ''
  sheet.value = 'delivered'
}

async function confirmDelivered() {
  busy.value = true
  sheetError.value = ''
  try {
    const extra = { collected_amount: delivery.collected_amount }
    if (delivery.collected_amount > 0) {
      extra.payment_method = delivery.payment_method
      if (delivery.payment_method !== 'cash') {
        extra.received_by_company = delivery.received_by_company
        if (delivery.transaction_ref) extra.transaction_ref = delivery.transaction_ref
      }
    }
    if (delivery.delivery_code) extra.delivery_code = delivery.delivery_code
    if (shipping.value) {
      Object.assign(extra, {
        shipping_carrier: delivery.shipping_carrier.trim(),
        shipping_fee: delivery.shipping_fee ?? 0,
        shipping_reference: delivery.shipping_reference || undefined,
      })
    }
    const result = await post('delivered', extra)
    if (shipping.value) rememberCarrier(delivery.shipping_carrier.trim())
    sheet.value = null
    toasts.success(result.offline ? OFFLINE_SAVED : (shipping.value ? 'Expédition enregistrée. Bravo !' : 'Livraison enregistrée. Bravo !'))
    if (!result.offline) await load()
  } catch (e) {
    sheetError.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

// Les compagnies déjà utilisées sont proposées en premier
function rememberCarrier(name) {
  recentCarriers.value = [name, ...recentCarriers.value.filter((c) => c !== name)].slice(0, 5)
  try {
    localStorage.setItem(CARRIERS_KEY, JSON.stringify(recentCarriers.value))
  } catch {
    // Stockage indisponible : pas de suggestions
  }
}

function openExpense() {
  Object.assign(expense, { type: 'transport', label: '', amount: null })
  sheetError.value = ''
  sheet.value = 'expense'
}

async function saveExpense() {
  busy.value = true
  sheetError.value = ''
  try {
    await http.post(`/orders/${order.value.id}/expenses`, { type: expense.type, label: expense.label || undefined, amount: expense.amount })
    sheet.value = null
    toasts.success('Frais enregistrés : ils seront déduits de votre versement.')
    await load()
  } catch (e) {
    sheetError.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

function openIncident() {
  Object.assign(incident, { reason: null, when: 'tomorrow', date: null, note: '' })
  sheetError.value = ''
  sheet.value = 'incident'
}

async function confirmIncident() {
  busy.value = true
  sheetError.value = ''
  try {
    // Ramassage : retour à « Validée » ; livraison : échec (ou report daté selon le motif)
    const status = stage.value === 'pickup' ? 'confirmed' : 'delivery_failed'
    const extra = { incident_reason_id: incident.reason, note: incident.note || undefined }
    if (selectedReason.value?.requires_date) {
      extra.rescheduled_to = { tomorrow: dayOffset(1), after: dayOffset(2), other: incident.date }[incident.when]
    }
    const result = await post(status, extra)
    sheet.value = null
    toasts.success(result.offline ? OFFLINE_SAVED : 'Problème signalé à l\'agence et au marchand.')
    if (!result.offline) await load()
  } catch (e) {
    sheetError.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function accept() {
  busy.value = true
  try {
    await http.post(`/courier/assignments/${mission.value.id}/accept`)
    await load()
  } finally {
    busy.value = false
  }
}

async function refuse() {
  busy.value = true
  try {
    await http.post(`/courier/assignments/${mission.value.id}/refuse`, { reason: refusal.value })
    toasts.push(impediment.value ? 'Mission rendue : l\'agence est prévenue.' : 'Mission refusée.')
    router.replace('/livreur')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function sendNote() {
  busy.value = true
  try {
    const position = await currentPosition()
    const payload = { note: noteText.value, ...(position || {}) }
    let offline = offlineEnabled.value && !navigator.onLine
    if (!offline) {
      try {
        await http.post(`/orders/${order.value.id}/notes`, payload)
      } catch (e) {
        if (!offlineEnabled.value || !isNetworkError(e)) throw e
        offline = true
      }
    }
    if (offline) keep('note', payload)
    noteText.value = ''
    sheet.value = null
    toasts.success(offline ? OFFLINE_SAVED : 'Note envoyée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function uploadPhoto(e) {
  const file = e.target.files[0]
  if (!file) return
  const form = new FormData()
  form.append('file', file)
  form.append('type', mission.value.type === 'pickup' ? 'photo_pickup' : 'photo_delivery')
  try {
    await http.post(`/orders/${order.value.id}/attachments`, form)
    toasts.success('Photo envoyée.')
  } catch (err) {
    toasts.error(apiErrorMessage(err))
  }
  e.target.value = ''
}

// Actions hors connexion envoyées : état du serveur rechargé
const reloadAfterSync = () => load()
window.addEventListener('offline-synced', reloadAfterSync)
onBeforeUnmount(() => window.removeEventListener('offline-synced', reloadAfterSync))

onMounted(async () => {
  await load()
  reasons.value = (await cachedGet('/incident-reasons').catch(() => ({ data: { data: [] } }))).data.data
})
</script>
