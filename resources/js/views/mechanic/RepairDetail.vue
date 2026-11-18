<template>
  <div v-if="repair" class="space-y-4 pb-4">
    <!-- La panne -->
    <div :class="['m-card border-l-4 p-4 space-y-2', SEVERITY_BORDERS[repair.severity] || 'border-l-slate-300']">
      <div class="flex items-start gap-3">
        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-slate-100 text-2xl" aria-hidden="true">{{ repair.icon || '🛠️' }}</span>
        <div class="min-w-0 flex-1">
          <p class="text-xs text-slate-500">Panne n° {{ repair.number }} · {{ dateTime(repair.created_at) }}</p>
          <p class="text-lg font-semibold leading-snug">{{ repair.category_label ? `${repair.category_label}${repair.part ? ' · ' + repair.part : ''}` : repair.title }}</p>
        </div>
        <span :class="['shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold', REPAIR_STATUS_STYLES[repair.status]]">{{ repair.status_label }}</span>
      </div>
      <p class="flex flex-wrap gap-x-3 gap-y-1 text-sm text-slate-600">
        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', SEVERITY_STYLES[repair.severity]]">{{ repair.severity_label }}</span>
        <span v-if="repair.event">{{ repair.event.capacity_label }}</span>
        <span v-if="repair.event?.orders_count">📦 {{ repair.event.orders_count }} colis en cours</span>
        <span v-if="repair.distance_km !== null">📍 à {{ repair.distance_km }} km</span>
      </p>
      <p v-if="repair.note" class="rounded-xl bg-slate-50 px-3 py-2 text-sm">« {{ repair.note }} »</p>
      <div v-if="media.length" class="flex flex-wrap items-center gap-2">
        <template v-for="m in media" :key="m.id">
          <img v-if="m.kind === 'photo'" :src="m.src" alt="Photo du livreur" class="h-20 w-20 rounded-xl object-cover ring-1 ring-slate-200">
          <audio v-else :src="m.src" controls class="h-9 w-56" />
        </template>
      </div>
      <a v-if="repair.lat !== null" :href="mapsLink(repair.lat, repair.lng)" target="_blank" rel="noopener" class="m-btn-secondary py-3 text-[15px]">🧭 Itinéraire jusqu'à la panne</a>
    </div>

    <!-- Le livreur et le véhicule -->
    <div class="m-card divide-y">
      <div v-if="repair.courier" class="flex items-center gap-3 p-4">
        <span class="grid h-10 w-10 place-items-center rounded-full bg-slate-100" aria-hidden="true">👤</span>
        <div class="min-w-0 flex-1">
          <p class="font-semibold">{{ repair.courier.name }}</p>
          <p class="text-sm text-slate-600">{{ localPhone(repair.courier.phone) }}</p>
        </div>
        <a v-if="repair.courier.phone" :href="telLink(repair.courier.phone)" class="rounded-full bg-[var(--app-color)] px-4 py-2 text-sm font-semibold text-white">📞 Appeler</a>
      </div>
      <RouterLink v-if="repair.vehicle" :to="`/mecanicien/vehicules/${repair.vehicle.id}`" class="flex items-center gap-3 p-4 active:bg-slate-50">
        <span class="grid h-10 w-10 place-items-center rounded-full bg-slate-100" aria-hidden="true">🛵</span>
        <div class="min-w-0 flex-1">
          <p class="font-semibold">{{ repair.vehicle.plate }}</p>
          <p class="text-sm text-slate-600">{{ repair.vehicle.name }}{{ repair.vehicle.odometer ? ` · ${repair.vehicle.odometer.toLocaleString('fr-FR')} km` : '' }}</p>
        </div>
        <span class="text-slate-400">›</span>
      </RouterLink>
    </div>

    <!-- Étapes et leurs heures -->
    <ol class="m-card space-y-2.5 p-4" aria-label="Étapes de l'intervention">
      <li v-for="s in steps" :key="s.label" class="flex items-center gap-3 text-sm">
        <span :class="['grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs text-white', s.at ? 'bg-emerald-600' : s.current ? 'bg-[var(--app-color)] ring-4 ring-orange-100' : 'bg-slate-300']">{{ s.at ? '✓' : '' }}</span>
        <span :class="['flex-1', s.at || s.current ? 'text-slate-900' : 'text-slate-400', s.current ? 'font-semibold' : '']">{{ s.label }}</span>
        <span class="font-mono text-xs text-slate-500">{{ s.at ? time(s.at) : '—' }}</span>
      </li>
      <li v-if="repair.status === 'cancelled'" class="text-sm text-slate-600">✖️ Annulée à {{ time(repair.cancelled_at) }}</li>
    </ol>

    <!-- Diagnostic et photos -->
    <div v-if="repair.category || hasPhotos || canWork" class="m-card space-y-3 p-4">
      <template v-if="repair.category">
        <p class="font-semibold">Diagnostic</p>
        <p class="text-sm">{{ repair.category_label }}{{ repair.part ? ` · ${repair.part}` : '' }} · <strong>{{ repair.decision_label }}</strong></p>
        <p v-if="repair.diagnosis" class="text-sm text-slate-600">{{ repair.diagnosis }}</p>
      </template>
      <div v-if="canWork || hasKind('before')">
        <p class="mb-2 text-sm font-semibold text-slate-700">Photos avant réparation</p>
        <RepairPhotos :repair="repair" kind="before" :editable="canWork" @updated="repair = $event" />
      </div>
      <div v-if="hasKind('after')">
        <p class="mb-2 text-sm font-semibold text-slate-700">Photos après réparation</p>
        <RepairPhotos :repair="repair" kind="after" />
      </div>
    </div>

    <!-- Achats sur place -->
    <div v-if="repair.purchases?.length" class="m-card space-y-2 p-4">
      <p class="font-semibold">Achats sur place</p>
      <div v-for="p in repair.purchases" :key="p.id" class="flex items-start gap-2 text-sm">
        <div class="min-w-0 flex-1">
          <p>{{ p.quantity }} × {{ p.label }} <span class="text-slate-500">(~{{ money(p.estimated_amount) }})</span></p>
          <p v-if="p.status === 'approved'" class="text-xs text-emerald-700">Accordé par {{ p.decided_by }} jusqu'à {{ money(p.max_amount) }}. Gardez la facture.</p>
          <p v-else-if="p.status === 'refused'" class="text-xs text-red-700">Refusé{{ p.decision_note ? ` : ${p.decision_note}` : '' }}</p>
          <p v-else class="text-xs text-amber-700">En attente de la réponse de l'administrateur</p>
        </div>
        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', PURCHASE_STYLES[p.status]]">{{ p.status_label }}</span>
      </div>
    </div>

    <!-- Clôture -->
    <div v-if="repair.status === 'done'" class="m-card space-y-1.5 p-4 text-sm">
      <p class="font-semibold">Intervention terminée à {{ time(repair.finished_at) }}</p>
      <p>Cause : {{ repair.cause_label }}</p>
      <p v-for="(line, i) in repair.parts" :key="i">{{ line.quantity }} × {{ line.label }} <span class="text-slate-500">· {{ money(line.amount) }}</span></p>
      <p v-if="repair.garage">Garage : {{ repair.garage }} · {{ money(repair.garage_amount) }}</p>
      <p class="font-semibold">Total : {{ money(repair.total) }}</p>
      <p v-if="repair.remark" class="text-slate-600">À prévoir : {{ repair.remark }}</p>
      <p :class="repair.courier_can_resume ? 'text-emerald-700' : 'text-amber-700'">{{ repair.courier_can_resume ? '✅ Le livreur a pu repartir' : '⏸️ Le livreur n\'est pas reparti : l\'agence s\'en occupe' }}</p>
    </div>

    <!-- Échange avec le livreur -->
    <div v-if="repair.event && (canWork || repair.event.messages.length)" class="m-card space-y-3 p-4">
      <p class="font-semibold">Échange avec le livreur</p>
      <p v-if="!repair.event.messages.length" class="text-sm text-slate-500">Prévenez le livreur, posez-lui une question : il reçoit votre message dans son application.</p>
      <div v-for="m in repair.event.messages" :key="m.id" :class="['max-w-[85%] rounded-2xl px-3 py-2 text-sm', m.from_courier ? 'bg-slate-100' : 'ml-auto bg-[var(--app-color)] text-white']">
        <p v-if="m.from_courier || m.sender !== auth.user?.name" :class="['text-xs font-semibold', m.from_courier ? 'text-slate-500' : 'text-white/80']">{{ m.sender }}</p>
        <p class="whitespace-pre-line">{{ m.body }}</p>
        <p :class="['text-right text-[11px]', m.from_courier ? 'text-slate-400' : 'text-white/70']">{{ time(m.created_at) }}</p>
      </div>
      <div v-if="canWork" class="flex gap-2">
        <input v-model="body" class="m-input flex-1" placeholder="Votre message" aria-label="Votre message au livreur" maxlength="1000" @keydown.enter="sendMessage">
        <button type="button" class="m-btn-primary w-auto px-4" :disabled="busy || !body.trim()" @click="sendMessage">Envoyer</button>
      </div>
    </div>

    <!-- Action de l'étape -->
    <div class="space-y-2">
      <template v-if="repair.status === 'offered' && repair.mine">
        <button type="button" class="m-btn-primary text-lg" :disabled="busy" @click="answer('accept')">J'accepte cette panne</button>
        <button type="button" class="m-btn-secondary" :disabled="busy" @click="answer('decline')">Je ne peux pas</button>
      </template>
      <button v-else-if="repair.status === 'searching'" type="button" class="m-btn-primary text-lg" :disabled="busy" @click="answer('accept')">Je prends cette panne</button>
      <button v-else-if="repair.status === 'accepted' && repair.mine" type="button" class="m-btn-primary text-lg" :disabled="busy" @click="step('depart')">🛵 Je pars</button>
      <button v-else-if="repair.status === 'en_route' && repair.mine" type="button" class="m-btn-primary text-lg" :disabled="busy" @click="step('arrive')">📍 Je suis arrivé</button>
      <RouterLink v-else-if="repair.status === 'on_site' && repair.mine" :to="`/mecanicien/pannes/${repair.id}/diagnostic`" class="m-btn-primary text-lg">🔍 Faire le diagnostic</RouterLink>
      <template v-else-if="repair.status === 'repairing' && repair.mine">
        <RouterLink :to="`/mecanicien/pannes/${repair.id}/cloture`" class="m-btn-success text-lg">✅ Réparation terminée</RouterLink>
        <RouterLink :to="`/mecanicien/pannes/${repair.id}/diagnostic`" class="m-btn-secondary">Modifier le diagnostic</RouterLink>
      </template>
      <button v-if="canWork" type="button" class="m-btn-secondary" @click="purchaseOpen = true">🛒 Acheter une pièce sur place</button>
    </div>

    <PurchaseSheet :open="purchaseOpen" :repair-id="repair.id" @close="purchaseOpen = false" @sent="purchaseSent" />
  </div>
  <p v-else-if="error" class="py-10 text-center text-slate-600">{{ error }}</p>
  <p v-else class="py-10 text-center text-slate-400">Chargement…</p>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import PurchaseSheet from '../../components/mechanic/PurchaseSheet.vue'
import RepairPhotos from '../../components/mechanic/RepairPhotos.vue'
import { authImage } from '../../composables/useAuthImage'
import { SEVERITY_STYLES } from '../../composables/useFieldEvents'
import { currentPosition } from '../../composables/useGeolocation'
import { REPAIR_STATUS_STYLES, SEVERITY_BORDERS, loadBoard, sendStep, time } from '../../composables/useMechanic'
import { useAuthStore } from '../../stores/auth'
import { useNotificationStore } from '../../stores/notifications'
import { useToastStore } from '../../stores/toasts'
import { dateTime, localPhone, mapsLink, money, telLink } from '../../utils/format'

const PURCHASE_STYLES = { pending: 'bg-amber-100 text-amber-800', approved: 'bg-emerald-100 text-emerald-800', refused: 'bg-red-100 text-red-800' }

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const notifications = useNotificationStore()
const repair = ref(null)
const media = ref([])
const error = ref('')
const body = ref('')
const busy = ref(false)
const purchaseOpen = ref(false)

const canWork = computed(() => repair.value?.mine && ['accepted', 'en_route', 'on_site', 'repairing'].includes(repair.value.status))
const hasKind = (kind) => (repair.value?.files || []).some((f) => f.kind === kind)
const hasPhotos = computed(() => hasKind('before') || hasKind('after'))

const steps = computed(() => {
  const r = repair.value
  const order = ['received', 'accepted', 'en_route', 'on_site', 'repairing', 'done']
  const reached = { searching: 0, offered: 0, accepted: 1, en_route: 2, on_site: 3, repairing: 4, done: 5, cancelled: -1 }[r.status]
  const list = [
    { key: 'received', label: 'Panne reçue', at: r.created_at },
    { key: 'accepted', label: r.mechanic && !r.mine ? `Acceptée par ${r.mechanic.name}` : 'Acceptée', at: r.accepted_at },
    { key: 'en_route', label: 'En route', at: r.departed_at },
    { key: 'on_site', label: 'Sur place', at: r.arrived_at },
    { key: 'repairing', label: 'Réparation commencée', at: r.started_at },
    { key: 'done', label: 'Terminée', at: r.finished_at },
  ]
  return list.map((s) => ({ ...s, current: !s.at && order.indexOf(s.key) === reached + 1 && r.status !== 'cancelled' }))
})

async function load() {
  try {
    const first = !repair.value
    repair.value = (await http.get(`/mechanic/repairs/${route.params.id}`)).data.data
    if (first && repair.value.event?.files?.length) {
      media.value = (await Promise.all(repair.value.event.files.map(async (f) => ({ ...f, src: await authImage(f.url).catch(() => null) })))).filter((m) => m.src)
    }
  } catch (e) {
    error.value = apiErrorMessage(e, 'Panne introuvable : elle a peut-être été confiée à un autre mécanicien.')
  }
}

async function answer(action) {
  busy.value = true
  try {
    if (action === 'decline') {
      await http.post(`/mechanic/repairs/${repair.value.id}/decline`)
      toasts.success('La panne est proposée à un autre mécanicien.')
      loadBoard()
      router.push('/mecanicien')
      return
    }
    repair.value = (await http.post(`/mechanic/repairs/${repair.value.id}/accept`, { at: new Date().toISOString() })).data.data
    toasts.success('Panne acceptée : le livreur est prévenu.')
    loadBoard()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    load()
  } finally {
    busy.value = false
  }
}

// « Je pars », « Je suis arrivé » : l'heure du clic est gardée même sans réseau
async function step(action) {
  busy.value = true
  const clicked = new Date().toISOString()
  try {
    const extra = action === 'arrive' ? ((await currentPosition(5000)) || {}) : {}
    const updated = await sendStep(repair.value.id, action, extra)
    if (updated) {
      repair.value = updated
    } else {
      // Hors connexion : étape affichée tout de suite, envoyée au retour du réseau
      repair.value = action === 'depart'
        ? { ...repair.value, status: 'en_route', departed_at: clicked }
        : { ...repair.value, status: 'on_site', arrived_at: clicked, departed_at: repair.value.departed_at || clicked }
      toasts.success(`Noté à ${time(clicked)}. Envoi dès le retour du réseau.`)
    }
    loadBoard()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    load()
  } finally {
    busy.value = false
  }
}

async function sendMessage() {
  if (!body.value.trim()) return
  busy.value = true
  try {
    repair.value = (await http.post(`/mechanic/repairs/${repair.value.id}/messages`, { body: body.value })).data.data
    body.value = ''
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

function purchaseSent(updated) {
  repair.value = updated
  purchaseOpen.value = false
  toasts.success('Demande envoyée à l\'administrateur. Vous serez prévenu de sa réponse.')
}

// Message du livreur, réponse de l'administrateur, annulation : rechargé en direct
watch(() => notifications.unread, load)
let timer
onMounted(() => {
  load()
  timer = setInterval(() => !document.hidden && load(), 30000)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  media.value.forEach((m) => URL.revokeObjectURL(m.src))
})
</script>
