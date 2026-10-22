<template>
  <div v-if="order" class="space-y-4 max-w-6xl mx-auto">
    <div class="flex flex-wrap items-center gap-3">
      <button class="btn-secondary" @click="router.back()">← Retour</button>
      <h1 class="text-xl font-bold font-mono">{{ order.tracking_code }}</h1>
      <StatusBadge :status="order.status" :label="order.status_label" />
      <span v-if="order.return_requested && !['returned', 'return_assigned', 'returning'].includes(order.status)" class="text-xs rounded-full bg-rose-100 text-rose-700 px-2 py-0.5">Retour demandé</span>
      <div class="ml-auto flex gap-2">
        <RouterLink :to="`/etiquette/${order.id}`" target="_blank" class="btn-secondary">🖨️ Étiquette</RouterLink>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="grid sm:grid-cols-2 gap-4">
          <div v-if="order.from_warehouse" class="card p-4 text-sm space-y-1">
            <h2 class="font-semibold mb-2">🏬 Départ de l'entrepôt · {{ order.pickup.hub_name }}</h2>
            <p>{{ order.pickup.zone_name }} · {{ order.pickup.address || '—' }}</p>
            <p v-if="order.pickup.landmark" class="text-gray-600">Repère : {{ order.pickup.landmark }}</p>
            <p :class="order.prepared_at ? 'text-emerald-700' : 'text-amber-700'">
              {{ order.prepared_at ? `Préparée le ${dateTime(order.prepared_at)}` : 'À préparer : pas de ramassage chez le marchand' }}
            </p>
          </div>
          <div v-else class="card p-4 text-sm space-y-1">
            <h2 class="font-semibold mb-2">Ramassage · {{ order.pickup.zone_name }}</h2>
            <p>{{ order.pickup.contact_name }} · <a :href="telLink(order.pickup.phone)" class="text-blue-600">{{ order.pickup.phone }}</a></p>
            <p>{{ order.pickup.address || '—' }}</p>
            <p v-if="order.pickup.landmark" class="text-gray-600">Repère : {{ order.pickup.landmark }}</p>
            <p v-if="order.pickup_courier" class="pt-1">Ramasseur : <strong>{{ order.pickup_courier.name }}</strong></p>
          </div>
          <div class="card p-4 text-sm space-y-1">
            <h2 class="font-semibold mb-2">Livraison · {{ order.delivery.zone_name }}</h2>
            <p>{{ order.recipient.name || '—' }} · <a :href="telLink(order.recipient.phone)" class="text-blue-600">{{ order.recipient.phone }}</a></p>
            <p v-if="order.recipient.phone2">2e numéro : <a :href="telLink(order.recipient.phone2)" class="text-blue-600">{{ order.recipient.phone2 }}</a></p>
            <p>{{ order.delivery.address || '—' }}</p>
            <p v-if="order.delivery.landmark" class="text-gray-600">Repère : {{ order.delivery.landmark }}</p>
            <p v-if="order.delivery.scheduled_date">Prévue le {{ date(order.delivery.scheduled_date) }} {{ order.delivery.time_slot ? `(${order.delivery.time_slot}h)` : '' }}</p>
            <p v-if="order.delivery_courier" class="pt-1">Livreur : <strong>{{ order.delivery_courier.name }}</strong></p>
            <button v-if="canEditDelivery" class="text-blue-600 text-xs underline pt-1" @click="openEdit">Modifier la livraison</button>
          </div>
        </div>

        <p v-if="order.status === 'lost'" class="rounded-lg bg-red-50 ring-1 ring-red-200 px-4 py-2 text-sm text-red-900">
          🚨 Colis déclaré perdu le {{ dateTime(order.lost_at) }}<span v-if="order.lost_reason"> : {{ order.lost_reason }}</span>
        </p>

        <!-- Livrée hors connexion avec un code client incorrect -->
        <div v-if="order.delivery_code_unverified_at" class="rounded-lg bg-orange-50 ring-1 ring-orange-200 px-4 py-3 text-sm text-orange-900 space-y-2">
          <p>⚠️ Livrée hors connexion avec un <strong>code client incorrect</strong> : vérifiez la livraison auprès du destinataire.</p>
          <button v-if="auth.can('orders.dispatch')" class="btn-secondary" @click="verifyDeliveryCode">Livraison vérifiée</button>
        </div>

        <!-- Garde du colis (personnel uniquement) -->
        <p v-if="order.held_by" class="rounded-lg bg-amber-50 ring-1 ring-amber-200 px-4 py-2 text-sm text-amber-900">
          🎒 Colis chez <strong>{{ order.held_by.name }}</strong> depuis le {{ dateTime(order.held_by.since) }}
          <span v-if="['delivery_failed', 'rescheduled'].includes(order.status)"> · pas encore rendu au dépôt</span>
        </p>

        <div class="card p-4 text-sm grid sm:grid-cols-4 gap-3">
          <div><p class="text-gray-500">Articles</p><p class="font-semibold">{{ money(order.amounts.items_amount) }}</p></div>
          <div>
            <p class="text-gray-500">Frais ({{ order.amounts.fee_payer === 'recipient' ? 'client' : 'marchand' }})</p>
            <p class="font-semibold">{{ money(order.amounts.total_fees) }}</p>
          </div>
          <div><p class="text-gray-500">À encaisser</p><p class="font-semibold text-lg">{{ money(order.amounts.cod_amount) }}</p></div>
          <div><p class="text-gray-500">Encaissé</p><p class="font-semibold">{{ money(order.amounts.collected_amount) }}</p></div>
          <div v-if="order.is_shipping" class="sm:col-span-4 rounded bg-indigo-50 text-indigo-900 p-2">
            🚌 <strong>Expédition</strong> ·
            <template v-if="order.shipping.carrier">
              déposé chez {{ order.shipping.carrier }}<span v-if="order.shipping.reference"> (ticket {{ order.shipping.reference }})</span>,
              frais {{ money(order.shipping.fee) }} facturés au marchand
            </template>
            <template v-else>frais du transporteur facturés au marchand au réel<span v-if="order.shipping.fee_estimate"> (≈ {{ money(order.shipping.fee_estimate) }})</span></template>
          </div>
          <div v-if="order.delivery_code" class="sm:col-span-2">
            <p class="text-gray-500">Code de livraison (à transmettre au client)</p>
            <p class="font-mono text-lg tracking-widest">{{ order.delivery_code }}</p>
          </div>
          <div class="sm:col-span-2">
            <p class="text-gray-500">Colis</p>
            <p>{{ order.package.description || '—' }}<span v-if="order.package.is_express"> · Express</span><span v-if="order.package.is_fragile"> · Fragile</span><span v-if="order.package.weight_kg"> · {{ order.package.weight_kg }} kg</span></p>
            <p v-if="order.package.merchant_note" class="text-gray-600">Instruction : {{ order.package.merchant_note }}</p>
          </div>
          <div><p class="text-gray-500">Tentatives</p><p>{{ order.attempts_count }} / {{ order.max_attempts }}</p></div>
          <div v-if="order.merchant"><p class="text-gray-500">Marchand</p><p>{{ order.merchant.business_name }}</p></div>
        </div>

        <div v-if="order.items?.length" class="card p-4 text-sm">
          <h2 class="font-semibold mb-2">Articles</h2>
          <ul class="divide-y">
            <li v-for="item in order.items" :key="item.id" class="py-1.5 flex justify-between gap-2">
              <span>
                <strong>{{ item.quantity }} ×</strong> {{ item.label }}
                <span v-if="item.stock_state" :class="['ml-1 text-xs rounded-full px-2 py-0.5', STOCK_STATES[item.stock_state].class]">{{ STOCK_STATES[item.stock_state].label }}</span>
              </span>
              <span class="text-gray-600">{{ item.unit_price ? money(item.unit_price * item.quantity) : '' }}</span>
            </li>
          </ul>
        </div>

        <div class="card p-4">
          <h2 class="font-semibold mb-3">Historique</h2>
          <OrderTimeline :events="order.events" />
        </div>
      </div>

      <!-- Actions -->
      <div class="space-y-4">
        <div v-if="isDispatcher && !isFinal" class="card p-4 space-y-3">
          <h2 class="font-semibold">Dispatch</h2>
          <p v-if="order.merchant_decision" class="rounded-lg bg-indigo-50 px-3 py-2 text-sm text-indigo-900">
            🧑‍💼 {{ order.merchant_decision.by || 'Le marchand' }} a décidé : {{ order.merchant_decision.kind === 'redeliver' ? `relivrer le ${date(order.merchant_decision.date)}` : 'faire revenir le colis' }}
          </p>
          <button v-if="order.needs_decision" class="btn-primary w-full" @click="deciding = true">🧭 Décider de la suite</button>
          <button v-else-if="order.decision_editable" class="btn-secondary w-full" @click="deciding = true">🧭 Modifier la décision</button>
          <button v-if="canDeclareLost" class="btn-secondary w-full text-red-700" @click="losing = true">🚨 Déclarer le colis perdu</button>
          <div class="flex gap-2 flex-wrap">
            <button v-if="order.status === 'pending'" class="btn-success" @click="quickMove('confirmed')">Valider</button>
            <button v-if="order.status === 'pending'" class="btn-secondary" @click="openStatus(['rejected'])">Refuser</button>
          </div>
          <!-- Livreurs actuels : retirables (erreur d'assignation, livreur indisponible) -->
          <div v-for="a in activeAssignments" :key="a.id" class="flex items-center justify-between gap-2 rounded bg-slate-50 px-3 py-2 text-sm">
            <span>{{ a.type_label }} : <strong>{{ a.courier_name }}</strong> <span class="text-gray-500">· {{ ASSIGNMENT_STATUS[a.status] }}</span></span>
            <button v-if="canUnassign(a)" class="text-red-700 font-medium whitespace-nowrap" @click="unassign(a)">Retirer</button>
          </div>
          <div v-for="type in assignableTypes" :key="type" class="space-y-1">
            <label class="label">{{ ASSIGNMENT_LABELS[type] }}</label>
            <div class="flex gap-2">
              <select v-model="assignment[type]" class="input">
                <option :value="null">Choisir un livreur…</option>
                <option v-for="c in sortedCouriers(type)" :key="c.id" :value="c.id">
                  {{ c.name }} · {{ c.active_assignments_count }} en cours{{ c.is_available ? '' : ' · indisponible' }}{{ servesZone(c, type) ? ' · ★ zone' : '' }}
                </option>
              </select>
              <button class="btn-primary" :disabled="!assignment[type]" @click="assign(type)">OK</button>
            </div>
          </div>
          <button v-if="nextStatuses.length" class="btn-secondary w-full" @click="openStatus(nextStatuses)">Changer le statut…</button>
        </div>

        <div v-if="warehouseActions.length" class="card p-4 space-y-2">
          <h2 class="font-semibold">Entrepôt</h2>
          <button v-if="warehouseActions.includes('prepare')" class="btn-success w-full" @click="quickMove('at_hub', 'Commande préparée.')">📦 Commande préparée</button>
          <button v-if="warehouseActions.includes('restock')" class="btn-secondary w-full" @click="restock">↩️ Remettre en stock</button>
        </div>

        <div v-if="isMerchant && !isFinal" class="card p-4 space-y-2">
          <h2 class="font-semibold">Actions</h2>
          <button v-if="BEFORE_PICKUP.includes(order.status)" class="btn-danger w-full" @click="openStatus(['cancelled'])">Annuler la course</button>
          <button v-if="order.status === 'delivery_failed'" class="btn-primary w-full" @click="openStatus(['rescheduled'])">Reporter la livraison</button>
          <button v-if="!BEFORE_PICKUP.includes(order.status) && !order.return_requested" class="btn-secondary w-full" @click="requestReturn">Demander le retour du colis</button>
        </div>

        <div class="card p-4 space-y-2">
          <h2 class="font-semibold">Ajouter une note</h2>
          <textarea v-model="note.text" rows="3" class="input" placeholder="Ex. : le client sera disponible après 17h" />
          <label v-if="isStaff" class="flex items-center gap-2 text-sm"><input v-model="note.internal" type="checkbox"> Note interne (invisible pour le marchand)</label>
          <button class="btn-primary w-full" :disabled="!note.text.trim()" @click="addNote">Envoyer</button>
        </div>

        <div v-if="isDispatcher && order.assignments?.length" class="card p-4 space-y-2">
          <h2 class="font-semibold">Consignes au livreur</h2>
          <CourierMessageThread :order-id="order.id" :messages="courierMessages" :composer="!isFinal" @sent="(m) => courierMessages.push(m)" />
        </div>

        <OrderExpenses v-if="isStaff" :order-id="order.id" :expenses="order.expenses || []" :can-add="canManageExpenses" @changed="load" />

        <OrderMessages v-if="isStaff" :order-id="order.id" :version="order.updated_at" />

        <div v-if="isStaff && order.assignments?.length" class="card p-4 text-sm">
          <h2 class="font-semibold mb-2">Missions</h2>
          <ul class="space-y-2">
            <li v-for="a in order.assignments" :key="a.id" class="flex justify-between gap-2">
              <span>{{ a.type_label }} · {{ a.courier_name }}</span>
              <span class="text-gray-500">{{ ASSIGNMENT_STATUS[a.status] }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <Modal :open="statusModal.open" title="Changer le statut" @close="statusModal.open = false">
      <StatusChangeForm v-if="statusModal.open" :order="order" :choices="statusModal.choices" @done="statusDone" />
    </Modal>

    <Modal :open="editModal" title="Modifier la livraison" @close="editModal = false">
      <form class="space-y-3" @submit.prevent="saveEdit">
        <div><label class="label">Téléphone</label><input v-model="edit.recipient_phone" class="input" required></div>
        <div><label class="label">Second téléphone</label><input v-model="edit.recipient_phone2" class="input"></div>
        <div>
          <label class="label">Zone</label>
          <select v-model="edit.delivery_zone_id" class="input">
            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
          </select>
        </div>
        <div><label class="label">Adresse</label><input v-model="edit.delivery_address" class="input"></div>
        <div><label class="label">Repère</label><input v-model="edit.delivery_landmark" class="input"></div>
        <p v-if="editError" class="field-error">{{ editError }}</p>
        <button class="btn-primary w-full">Enregistrer</button>
      </form>
    </Modal>

    <LostParcelDialog :open="losing" :order="order" :couriers="couriers" @close="losing = false" @declared="declaredLost" />
    <OrderDecision :open="deciding" :order="order" :couriers="couriers" @close="deciding = false" @decided="decided" />
  </div>
  <p v-else-if="error" class="text-red-600">{{ error }}</p>
  <p v-else class="text-gray-500">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import StatusBadge from '../../components/StatusBadge.vue'
import OrderTimeline from '../../components/OrderTimeline.vue'
import Modal from '../../components/Modal.vue'
import OrderDecision from '../../components/OrderDecision.vue'
import LostParcelDialog from '../../components/LostParcelDialog.vue'
import OrderMessages from '../../components/OrderMessages.vue'
import OrderExpenses from '../../components/OrderExpenses.vue'
import StatusChangeForm from '../../components/StatusChangeForm.vue'
import CourierMessageThread from '../../components/CourierMessageThread.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { orderChanges, lastOrderChange } from '../../composables/useRealtime'
import { date, dateTime, money, telLink } from '../../utils/format'
import { ASSIGNABLE, ASSIGNMENT_LABELS, BEFORE_PICKUP, FINAL, TRANSITIONS, isWarehouseStep } from '../../utils/workflow'

const STOCK_STATES = {
  reserved: { label: 'réservé', class: 'bg-amber-100 text-amber-800' },
  shipped: { label: 'sorti du stock', class: 'bg-emerald-100 text-emerald-800' },
  released: { label: 'remis en stock', class: 'bg-slate-100 text-slate-700' },
}

const ASSIGNMENT_STATUS = {
  assigned: 'Assignée', accepted: 'Acceptée', refused: 'Refusée', in_progress: 'En cours',
  completed: 'Terminée', failed: 'Échouée', cancelled: 'Annulée',
}

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

const order = ref(null)
const error = ref('')
const couriers = ref([])
// Colis perdu : administrateur (dispatch + caisse), colis ramassé et pas encore livré ou retourné
const losing = ref(false)
const canDeclareLost = computed(() => auth.can('orders.dispatch') && auth.can('finance.manage') && !BEFORE_PICKUP.includes(order.value?.status))
function declaredLost() {
  losing.value = false
  toasts.success('Colis déclaré perdu. Le marchand est prévenu.')
  load()
}

// Suite à donner à un colis non livré (relivrer, retourner, remettre en stock)
const deciding = ref(false)
function decided() {
  deciding.value = false
  toasts.success('Décision enregistrée.')
  load()
}
const zones = ref([])
const assignment = reactive({ pickup: null, delivery: null, return: null })
const note = reactive({ text: '', internal: false })
const statusModal = reactive({ open: false, choices: [] })
const editModal = ref(false)
const edit = reactive({})
const editError = ref('')
const courierMessages = ref([])

const isMerchant = computed(() => !!auth.user?.merchant_id)
const isStaff = computed(() => !isMerchant.value)
const isDispatcher = computed(() => auth.can('orders.dispatch'))
const canManageExpenses = computed(() => auth.can('orders.dispatch') || auth.can('finance.manage'))
const isFinal = computed(() => FINAL.includes(order.value?.status))
const canEditDelivery = computed(() => !isFinal.value && order.value?.status !== 'out_for_delivery' && (isMerchant.value || isDispatcher.value))
const assignableTypes = computed(() => Object.keys(ASSIGNABLE).filter((type) => {
  if (!ASSIGNABLE[type].includes(order.value.status)) return false
  // Commande d'entrepôt : ni ramassage ni retour au marchand ; livraison une fois préparée
  if (order.value.from_warehouse && type !== 'delivery') return false
  if (order.value.from_warehouse && !order.value.prepared_at) return false
  // Le retour n'est proposé qu'après un incident ou sur demande
  if (type === 'return') return order.value.return_requested || ['delivery_failed', 'rescheduled', 'at_hub', 'return_assigned'].includes(order.value.status)
  if (type === 'delivery') return order.value.attempts_count < order.value.max_attempts
  return true
}))
const nextStatuses = computed(() => (TRANSITIONS[order.value.status] || [])
  .filter((s) => !(s === 'confirmed' && order.value.status === 'pending') && !isWarehouseStep(order.value.status, s)))
// Préparation et remise en stock (dispatch, agents de dépôt)
const warehouseActions = computed(() => {
  if (!order.value?.from_warehouse || isMerchant.value) return []
  const actions = []
  if (order.value.status === 'confirmed' && (auth.can('orders.dispatch') || auth.can('orders.update_status'))) actions.push('prepare')
  if (['at_hub', 'delivery_failed', 'rescheduled'].includes(order.value.status) && (auth.can('orders.dispatch') || auth.can('stock.manage'))) actions.push('restock')
  return actions
})

async function load() {
  try {
    const { data } = await http.get(`/orders/${route.params.id}`)
    order.value = data.data
    if (isDispatcher.value) courierMessages.value = (await http.get(`/orders/${route.params.id}/courier-messages`)).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function servesZone(courier, type) {
  const zoneId = type === 'pickup' ? order.value.pickup.zone_id : order.value.delivery.zone_id
  return courier.zones?.some((z) => z.id === zoneId || z.parent_id === zoneId)
}

// Livreurs de la zone et disponibles en premier, puis les moins chargés
function sortedCouriers(type) {
  return [...couriers.value].sort((a, b) => (servesZone(b, type) - servesZone(a, type))
    || (b.is_available - a.is_available)
    || (a.active_assignments_count - b.active_assignments_count))
}

const activeAssignments = computed(() => (order.value?.assignments || []).filter((a) => ['assigned', 'accepted', 'in_progress'].includes(a.status)))
// Livreur en route avec le colis (livraison, retour) : l'issue doit d'abord être enregistrée
const canUnassign = (a) => a.type === 'pickup' || !['out_for_delivery', 'returning'].includes(order.value.status)

async function unassign(a) {
  const reason = window.prompt(`Retirer ${a.courier_name} de ce ${a.type_label.toLowerCase()} ? Il sera prévenu.\nMotif (facultatif) :`, '')
  if (reason === null) return
  try {
    const { data } = await http.post(`/orders/${order.value.id}/unassign`, { type: a.type, reason: reason.trim() || undefined })
    order.value = data.data
    toasts.success('Livreur retiré : la course attend un autre livreur.')
    loadCouriers()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function assign(type) {
  try {
    const { data } = await http.post(`/orders/${order.value.id}/assign`, { type, courier_id: assignment[type] })
    order.value = data.data
    assignment[type] = null
    toasts.success('Mission assignée.')
    loadCouriers()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function quickMove(status, message = null) {
  try {
    const { data } = await http.post(`/orders/${order.value.id}/status`, { status })
    order.value = data.data
    if (message) toasts.success(message)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function restock() {
  if (!window.confirm('Le colis est-il bien revenu à l\'entrepôt ? Les articles redeviendront disponibles.')) return
  await quickMove('returned', 'Articles remis en stock.')
}

function openStatus(choices) {
  statusModal.choices = choices
  statusModal.open = true
}

function statusDone(updated) {
  order.value = updated
  statusModal.open = false
  toasts.success('Statut mis à jour.')
}

async function addNote() {
  try {
    await http.post(`/orders/${order.value.id}/notes`, { note: note.text, visible_to_merchant: !note.internal })
    note.text = ''
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function verifyDeliveryCode() {
  const note = window.prompt('Comment la livraison a-t-elle été vérifiée ? (facultatif)')
  if (note === null) return
  try {
    const { data } = await http.post(`/orders/${order.value.id}/verify-delivery-code`, { note: note || undefined })
    order.value = data.data
    toasts.success('Livraison vérifiée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function requestReturn() {
  const reason = window.prompt('Raison du retour (facultatif) :')
  if (reason === null) return
  try {
    const { data } = await http.post(`/orders/${order.value.id}/return-request`, { note: reason || undefined })
    order.value = data.data
    toasts.success('Demande de retour envoyée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function openEdit() {
  if (!zones.value.length) zones.value = (await http.get('/zones')).data.data
  Object.assign(edit, {
    recipient_phone: order.value.recipient.phone,
    recipient_phone2: order.value.recipient.phone2,
    delivery_zone_id: order.value.delivery.zone_id,
    delivery_address: order.value.delivery.address,
    delivery_landmark: order.value.delivery.landmark,
  })
  editError.value = ''
  editModal.value = true
}

async function saveEdit() {
  try {
    const payload = Object.fromEntries(Object.entries(edit).filter(([, v]) => v !== null && v !== ''))
    const { data } = await http.patch(`/orders/${order.value.id}`, payload)
    order.value = data.data
    editModal.value = false
    toasts.success('Course modifiée.')
  } catch (e) {
    editError.value = apiErrorMessage(e)
  }
}

async function loadCouriers() {
  if (isDispatcher.value) couriers.value = (await http.get('/couriers')).data.data
}

watch(orderChanges, () => {
  if (lastOrderChange.value?.order?.id === order.value?.id) load()
})
watch(() => route.params.id, () => { order.value = null; load() })

onMounted(() => {
  load()
  loadCouriers()
})
</script>
