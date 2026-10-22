<template>
  <div v-if="order" class="space-y-4">
    <!-- L'essentiel : où en est la course, en une phrase -->
    <section class="m-card p-4 space-y-4">
      <div class="flex items-start gap-3">
        <span class="h-12 w-12 shrink-0 rounded-2xl grid place-items-center bg-[color-mix(in_srgb,var(--app-color)_10%,white)] text-[var(--app-color)]" aria-hidden="true">
          <StatusIcon name="package" class="h-6 w-6" />
        </span>
        <div class="min-w-0 flex-1">
          <p :class="['text-lg font-bold leading-snug flex items-start gap-2', state.color]">
            <StatusIcon :name="state.icon" class="h-[18px] w-[18px] shrink-0 mt-[5px]" /><span>{{ headline }}</span>
          </p>
          <p v-if="subline" class="text-sm text-slate-600">{{ subline }}</p>
          <button class="mt-0.5 font-mono text-xs text-slate-400" @click="copy(order.tracking_code)">{{ order.tracking_code }} ⧉</button>
        </div>
      </div>
      <OrderProgress :status="order.status" :incident="order.last_incident?.label" :rescheduled-to="order.delivery.scheduled_date" :shipping="order.is_shipping" />
      <p v-if="plannedDay" class="flex items-center gap-2 rounded-xl bg-sky-50 px-3 py-2 text-sm text-sky-900"><StatusIcon name="calendar" class="h-4 w-4 shrink-0" /><span>Livraison prévue le <strong>{{ plannedDay }}</strong></span></p>

      <!-- Échec de livraison : la décision d'abord (seulement si elle est encore attendue) -->
      <div v-if="order.needs_decision" class="grid grid-cols-2 gap-2">
        <button class="m-btn-primary py-3" @click="openReschedule">📅 Reporter</button>
        <button class="m-btn-secondary py-3" @click="openEdit(true)">✏️ Corriger et relivrer</button>
        <button class="m-btn-secondary py-3 col-span-2" @click="sheet = 'return'">↩️ Faire revenir le colis</button>
      </div>
      <!-- Décision prise : on peut encore la changer tant qu'aucun livreur n'est reparti -->
      <p v-else-if="canChangeDecision" class="text-sm text-slate-600">
        Décision prise. <button class="font-semibold text-[var(--app-color)]" @click="openReschedule">Changer la décision</button>
      </p>

      <!-- Actions utiles en un toucher -->
      <div :class="['grid gap-2 text-center text-xs font-medium', courier ? 'grid-cols-3' : 'grid-cols-2']">
        <a :href="telLink(order.recipient.phone)" class="tap rounded-2xl bg-slate-50 py-3 ring-1 ring-slate-200"><span class="block text-xl">📞</span>Client</a>
        <a v-if="courier" :href="telLink(courier.phone)" class="tap rounded-2xl bg-slate-50 py-3 ring-1 ring-slate-200"><span class="block text-xl">🛵</span>{{ courier.name.split(' ')[0] }}</a>
        <a v-if="!isFinal" :href="whatsappShare" target="_blank" class="tap rounded-2xl bg-emerald-50 py-3 ring-1 ring-emerald-200 text-emerald-800"><span class="block text-xl">🔗</span>Envoyer le suivi</a>
        <button v-else class="tap rounded-2xl bg-slate-50 py-3 ring-1 ring-slate-200" @click="copy(trackingUrl)"><span class="block text-xl">🔗</span>Lien de suivi</button>
      </div>
    </section>

    <!-- Code à donner au livreur (tant que la course n'est pas terminée) -->
    <section v-if="!isFinal" class="m-card p-4 flex items-center justify-between">
      <div>
        <p class="text-sm text-slate-500">Code de livraison du client</p>
        <p class="font-mono text-2xl font-bold tracking-[0.3em]">{{ order.delivery_code }}</p>
      </div>
      <button class="rounded-xl bg-slate-100 px-3 py-2 text-sm font-medium" @click="copy(order.delivery_code)">Copier</button>
    </section>

    <!-- Client -->
    <section class="m-card p-4 space-y-1">
      <div class="flex justify-between">
        <p class="text-sm text-slate-500">Client</p>
        <button v-if="canEdit" class="text-sm font-medium text-[var(--app-color)]" @click="openEdit">Modifier</button>
      </div>
      <p class="font-semibold text-lg">{{ order.recipient.name || '—' }}</p>
      <div class="flex gap-3 text-sm">
        <a :href="telLink(order.recipient.phone)" class="text-[var(--app-color)] font-medium">📞 {{ localPhone(order.recipient.phone) }}</a>
        <a v-if="order.recipient.phone2" :href="telLink(order.recipient.phone2)" class="text-[var(--app-color)]">{{ localPhone(order.recipient.phone2) }}</a>
      </div>
      <p class="pt-1">📍 {{ order.delivery.zone_name }}<span v-if="order.delivery.address"> · {{ order.delivery.address }}</span></p>
      <p v-if="order.delivery.landmark" class="text-sm text-slate-500">Repère : {{ order.delivery.landmark }}</p>
    </section>

    <!-- Articles -->
    <section v-if="order.items?.length || order.from_warehouse" class="m-card p-4 space-y-1 text-sm">
      <p class="text-sm text-slate-500">{{ order.from_warehouse ? `🏬 Préparée à ${order.pickup.hub_name}` : 'Articles' }}</p>
      <p v-for="item in order.items" :key="item.id">
        <strong>{{ item.quantity }} ×</strong> {{ item.label }}
        <span v-if="item.stock_state === 'reserved'" class="text-xs text-amber-700">· réservé</span>
        <span v-else-if="item.stock_state === 'released'" class="text-xs text-slate-500">· remis en stock</span>
      </p>
    </section>

    <!-- L'argent de la course : ce qui vous revient -->
    <section class="m-card p-4 space-y-2 text-sm">
      <h2 class="font-semibold text-base">L'argent de la course</h2>
      <div class="flex justify-between">
        <span class="text-slate-500">{{ collected !== null ? 'Encaissé chez le client' : 'À encaisser chez le client' }}</span>
        <span>{{ money(collected ?? order.amounts.cod_amount) }}</span>
      </div>
      <div class="flex justify-between">
        <span class="text-slate-500">Livraison (payée par {{ order.amounts.fee_payer === 'recipient' ? 'le client' : 'vous' }})</span>
        <span>− {{ money(order.amounts.total_fees) }}</span>
      </div>
      <div v-if="order.is_shipping" class="flex justify-between">
        <span class="text-slate-500">🚌 Expédition</span>
        <span>− {{ order.shipping.fee !== null ? money(order.shipping.fee) : (order.shipping.fee_estimate ? '≈ ' + money(order.shipping.fee_estimate) : 'au réel') }}</span>
      </div>
      <div v-if="expensesTotal" class="flex justify-between">
        <span class="text-slate-500">Autres frais</span><span>− {{ money(expensesTotal) }}</span>
      </div>
      <div v-if="showNet" class="flex justify-between items-baseline border-t pt-2">
        <span class="font-semibold">{{ order.status === 'delivered' ? 'Pour vous' : 'Pour vous, une fois livrée' }}</span>
        <span :class="['text-xl font-bold', net < 0 ? 'text-red-600' : 'text-emerald-700']">{{ money(net) }}</span>
      </div>
    </section>

    <!-- Frais facturés au marchand -->
    <section v-if="order.expenses?.length" class="m-card p-4 space-y-2 text-sm">
      <h2 class="font-semibold text-base">Frais de la course</h2>
      <div v-for="e in order.expenses" :key="e.id" :class="['flex justify-between gap-3', e.cancelled_at ? 'line-through text-slate-400' : '']">
        <span class="text-slate-600">{{ e.description }}</span><span class="whitespace-nowrap">− {{ money(e.amount) }}</span>
      </div>
      <p class="text-slate-500 text-xs">Déduits de votre point.</p>
    </section>

    <!-- Expédition -->
    <section v-if="order.is_shipping && order.shipping.carrier" class="m-card p-4 space-y-1 text-sm">
      <h2 class="font-semibold text-base">🚌 Colis expédié</h2>
      <p>Par <strong>{{ order.shipping.carrier }}</strong><span v-if="order.shipping.reference"> · ticket {{ order.shipping.reference }}</span></p>
      <p class="text-slate-500">Frais d'expédition de {{ money(order.shipping.fee) }} déduits de votre point.</p>
    </section>

    <!-- Autres actions -->
    <section v-if="!isFinal" class="space-y-2">
      <button class="m-btn-secondary" @click="sheet = 'note'">💬 Écrire au livreur / à l'agence</button>
      <button v-if="!order.needs_decision && !beforePickup && !order.return_requested" class="m-btn-secondary" @click="sheet = 'return'">↩️ Demander le retour du colis</button>
      <button v-if="beforePickup" class="m-btn-secondary text-red-600" @click="sheet = 'cancel'">Annuler la course</button>
    </section>

    <!-- Historique : l'essentiel, le détail complet à un toucher -->
    <section class="m-card p-4">
      <h2 class="font-semibold mb-3">Historique</h2>
      <MerchantHistory v-if="!fullHistory" :events="[...order.events].reverse()" />
      <OrderTimeline v-else :events="[...order.events].reverse()" />
      <button class="mt-3 text-sm font-medium text-[var(--app-color)]" @click="fullHistory = !fullHistory">{{ fullHistory ? 'Voir l\'essentiel' : 'Voir le détail complet' }}</button>
    </section>

    <!-- Feuilles d'action -->
    <BottomSheet :open="sheet === 'reschedule'" title="Quand relivrer ?" @close="sheet = null">
      <div class="space-y-3">
        <ChoiceChips v-model="when" :options="deliveryOptions" :columns="3" label="Jour de la nouvelle livraison" />
        <p v-if="closed" class="text-xs text-slate-500">🌙 {{ closedReason }}.</p>
        <input v-if="when === 'other'" v-model="reschedule.delivery_scheduled_date" type="date" :min="minDate" class="m-input" aria-label="Jour de la nouvelle livraison">
        <p v-if="dateSummary" class="text-sm font-semibold text-slate-700">{{ dateSummary.replace('Votre course sera livrée', 'Nouvelle livraison') }}</p>
        <textarea v-model="reschedule.note" rows="2" class="m-input" placeholder="Message (facultatif) : ex. le client sera là après 17h" />
        <button class="m-btn-primary" :disabled="busy || !dateChosen" @click="doReschedule">Confirmer la relivraison</button>
        <button v-if="!order.return_requested" class="w-full text-sm font-medium text-slate-600 py-1" @click="sheet = 'return'">Plutôt faire revenir le colis</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'return'" title="Demander le retour" @close="sheet = null">
      <div class="space-y-3">
        <p class="text-slate-600">Le colis vous sera rapporté. Des frais de retour peuvent s'appliquer.</p>
        <textarea v-model="noteText" rows="2" class="m-input" placeholder="Raison (facultatif)" />
        <button class="m-btn-primary" :disabled="busy" @click="doReturn">Demander le retour</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'cancel'" title="Annuler la course ?" @close="sheet = null">
      <div class="space-y-3">
        <textarea v-model="noteText" rows="2" class="m-input" placeholder="Raison (facultatif)" />
        <button class="m-btn-danger" :disabled="busy" @click="doCancel">Oui, annuler la course</button>
        <button class="m-btn-secondary" @click="sheet = null">Non, garder</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'note'" title="Envoyer un message" @close="sheet = null">
      <div class="space-y-3">
        <textarea v-model="noteText" rows="3" class="m-input" placeholder="Ex. : le client préfère être livré au bureau" />
        <button class="m-btn-primary" :disabled="busy || !noteText.trim()" @click="doNote">Envoyer</button>
      </div>
    </BottomSheet>

    <BottomSheet :open="sheet === 'edit'" title="Modifier la livraison" @close="sheet = null">
      <div class="space-y-3">
        <div><label class="m-label">Téléphone</label><input v-model="edit.recipient_phone" class="m-input" inputmode="tel"></div>
        <div><label class="m-label">Autre numéro</label><input v-model="edit.recipient_phone2" class="m-input" inputmode="tel"></div>
        <div>
          <label class="m-label">Commune / quartier</label>
          <select v-model="edit.delivery_zone_id" class="m-input">
            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
          </select>
        </div>
        <div><label class="m-label">Adresse</label><input v-model="edit.delivery_address" class="m-input"></div>
        <div><label class="m-label">Repère</label><input v-model="edit.delivery_landmark" class="m-input"></div>
        <button class="m-btn-primary" :disabled="busy" @click="doEdit">Enregistrer</button>
      </div>
    </BottomSheet>
  </div>
  <p v-else class="text-center text-slate-400 py-10">{{ error || 'Chargement…' }}</p>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import OrderTimeline from '../../components/OrderTimeline.vue'
import OrderProgress from '../../components/mobile/OrderProgress.vue'
import MerchantHistory from '../../components/mobile/MerchantHistory.vue'
import { merchantStatus } from '../../utils/merchantStatus'
import StatusIcon from '../../components/mobile/StatusIcon.vue'
import { longDay, useDeliveryDate } from '../../composables/useDeliveryDate'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { orderChanges, lastOrderChange } from '../../composables/useRealtime'
import { localPhone, money, telLink, today } from '../../utils/format'
import { BEFORE_PICKUP, FINAL } from '../../utils/workflow'
import { siteOrigin } from '../../native'

const route = useRoute()
const auth = useAuthStore()
const toasts = useToastStore()

const order = ref(null)
const error = ref('')
const sheet = ref(null)
const busy = ref(false)
const noteText = ref('')
const zones = ref([])
const edit = reactive({})
const reschedule = reactive({ delivery_scheduled_date: null, note: '' })
// Jours de relivraison : mêmes règles que la nouvelle course (« Aujourd'hui » grisé si la journée est fermée)
const { when, options: deliveryOptions, closed, closedReason, minDate, summary: dateSummary, isChosen: dateChosen, load: loadDeliveryDay, reset: resetDate } = useDeliveryDate(reschedule)
// Après « Corriger et relivrer », le choix du jour suit l'enregistrement
const relivrer = ref(false)

const fullHistory = ref(false)

const isFinal = computed(() => FINAL.includes(order.value?.status))
const state = computed(() => merchantStatus(order.value))

// « mardi 20 octobre » ou « aujourd'hui » / « hier »
const dayWord = (value) => {
  const d = new Date(value)
  const diff = Math.round((new Date().setHours(0, 0, 0, 0) - new Date(d).setHours(0, 0, 0, 0)) / 86400000)
  return diff === 0 ? 'aujourd\'hui' : diff === 1 ? 'hier' : `le ${d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })}`
}
const hour = (value) => new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const recipientName = computed(() => order.value.recipient.name || order.value.recipient.phone)

// Phrase en tête : ce qui compte pour l'e-commerçant
const headline = computed(() => {
  const o = order.value
  if (o.status === 'delivered') return o.is_shipping ? `Expédiée pour ${recipientName.value}` : `Livrée à ${recipientName.value}`
  if (o.status === 'out_for_delivery') return `En route vers ${recipientName.value}`
  return state.value.text
})
const subline = computed(() => {
  const o = order.value
  if (o.status === 'delivered' && o.delivered_at) return `${dayWord(o.delivered_at)} à ${hour(o.delivered_at)}${o.delivery_courier ? ` par ${o.delivery_courier.name}` : ''}`.replace(/^./, (c) => c.toUpperCase())
  if (o.status === 'out_for_delivery' && o.delivery_courier) return `Livreur : ${o.delivery_courier.name}`
  if (['pickup_assigned', 'pickup_in_progress'].includes(o.status) && o.pickup_courier) return `Livreur : ${o.pickup_courier.name}`
  return ''
})

// Livraison prévue un autre jour (date choisie, report ou journée fermée)
const plannedDay = computed(() => {
  const d = order.value.delivery.scheduled_date
  return d && !isFinal.value && order.value.status !== 'rescheduled' && d > today() ? longDay(d) : null
})

// Le livreur à joindre : celui de la livraison, sinon celui du ramassage, tant que la course est en cours
const courier = computed(() => (isFinal.value ? null : order.value.delivery_courier || order.value.pickup_courier || null))

// Ce qui revient à l'e-commerçant : encaissé − livraison − expédition − autres frais
const collected = computed(() => order.value.amounts.collected_amount)
const expensesTotal = computed(() => (order.value.expenses || []).filter((e) => !e.cancelled_at).reduce((sum, e) => sum + e.amount, 0))
const shippingFee = computed(() => (order.value.is_shipping ? (order.value.shipping.fee ?? order.value.shipping.fee_estimate ?? 0) : 0))
const net = computed(() => (collected.value ?? order.value.amounts.cod_amount) - order.value.amounts.total_fees - shippingFee.value - expensesTotal.value)
const showNet = computed(() => order.value.status === 'delivered' || !isFinal.value)
const trackingUrl = computed(() => `${siteOrigin()}/suivi/${order.value.tracking_code}`)
const beforePickup = computed(() => BEFORE_PICKUP.includes(order.value?.status))
const canEdit = computed(() => !isFinal.value && order.value?.status !== 'out_for_delivery')

// Décision prise par le marchand (report sans livreur reparti) : encore modifiable
const canChangeDecision = computed(() => !!order.value.decision_editable)

function openReschedule() {
  resetDate()
  reschedule.note = ''
  loadDeliveryDay()
  sheet.value = 'reschedule'
}

const whatsappShare = computed(() => {
  const o = order.value
  const text = `Bonjour, votre commande ${auth.user?.merchant?.business_name || ''} sera livrée bientôt.\nSuivi : ${siteOrigin()}/suivi/${o.tracking_code}\nCode de livraison à donner au livreur : ${o.delivery_code}`
  return `https://wa.me/${o.recipient.phone.replace(/\D/g, '')}?text=${encodeURIComponent(text)}`
})

async function load() {
  try {
    order.value = (await http.get(`/orders/${route.params.id}`)).data.data
    // « Reporter » depuis la liste des courses : le report s'ouvre directement
    if (route.query.action === 'reporter' && order.value.needs_decision && !sheet.value) openReschedule()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function run(action, success) {
  busy.value = true
  try {
    const { data } = await action()
    if (data?.data?.id) order.value = data.data
    else await load()
    sheet.value = null
    noteText.value = ''
    toasts.success(success)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

const doReschedule = () => run(() => http.post(`/orders/${order.value.id}/status`, {
  status: 'rescheduled', rescheduled_to: reschedule.delivery_scheduled_date, note: reschedule.note || undefined,
}), 'Relivraison prévue.')
const doReturn = () => run(() => http.post(`/orders/${order.value.id}/return-request`, { note: noteText.value || undefined }), 'Retour demandé.')
const doCancel = () => run(() => http.post(`/orders/${order.value.id}/status`, { status: 'cancelled', cancel_reason: noteText.value || undefined }), 'Course annulée.')
const doNote = () => run(() => http.post(`/orders/${order.value.id}/notes`, { note: noteText.value }), 'Message envoyé.')

async function openEdit(thenRedeliver = false) {
  relivrer.value = thenRedeliver === true
  if (!zones.value.length) zones.value = (await http.get('/zones')).data.data
  Object.assign(edit, {
    recipient_phone: order.value.recipient.phone,
    recipient_phone2: order.value.recipient.phone2 || '',
    delivery_zone_id: order.value.delivery.zone_id,
    delivery_address: order.value.delivery.address || '',
    delivery_landmark: order.value.delivery.landmark || '',
  })
  sheet.value = 'edit'
}

async function doEdit() {
  await run(
    () => http.patch(`/orders/${order.value.id}`, Object.fromEntries(Object.entries(edit).filter(([, v]) => v !== ''))),
    'Livraison modifiée.',
  )
  // Adresse corrigée : reste à choisir le jour de la nouvelle livraison
  if (relivrer.value && order.value.needs_decision) openReschedule()
  relivrer.value = false
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Copié.')
  } catch {
    toasts.push(text)
  }
}

watch(orderChanges, () => {
  if (lastOrderChange.value?.order?.id === order.value?.id) load()
})
onMounted(load)
</script>
