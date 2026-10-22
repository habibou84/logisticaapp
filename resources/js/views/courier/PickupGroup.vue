<template>
  <div v-if="missions.length" class="space-y-4 pb-36">
    <!-- La boutique -->
    <section class="m-card p-4 space-y-2">
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold uppercase tracking-wide text-[var(--app-color)]">Ramassage groupé</span>
        <span class="rounded-full bg-indigo-100 text-indigo-800 text-xs font-semibold px-2 py-0.5">{{ missions.length }} colis</span>
      </div>
      <p v-if="offlineCount" class="rounded-xl bg-sky-50 text-sky-900 p-3 text-sm">
        ⏳ Enregistré sur votre téléphone : envoi automatique au retour du réseau.
      </p>
      <p v-if="gain" class="text-sm font-semibold text-emerald-700">💰 Votre gain prévu : + {{ money(gain) }}</p>
      <p class="text-xl font-bold">{{ shop.name }}</p>
      <p class="text-slate-700">📍 {{ shop.zone }}<span v-if="shop.address"> · {{ shop.address }}</span></p>
      <p v-if="shop.landmark" class="text-slate-500">Repère : {{ shop.landmark }}</p>

      <div class="grid grid-cols-3 gap-2 pt-2">
        <a :href="telLink(shop.phone)" class="tap rounded-2xl bg-slate-100 py-3 flex flex-col items-center gap-1 text-sm font-medium active:bg-slate-200">
          <span class="text-2xl">📞</span>Appeler
        </a>
        <a :href="whatsappLink(shop.phone)" target="_blank" class="tap rounded-2xl bg-slate-100 py-3 flex flex-col items-center gap-1 text-sm font-medium active:bg-slate-200">
          <span class="text-2xl">💬</span>WhatsApp
        </a>
        <a :href="mapsLink(shop.lat, shop.lng, `${shop.address || ''} ${shop.zone}`)" target="_blank" class="tap rounded-2xl bg-slate-100 py-3 flex flex-col items-center gap-1 text-sm font-medium active:bg-slate-200">
          <span class="text-2xl">🗺️</span>Itinéraire
        </a>
      </div>
    </section>

    <!-- Les colis : décocher ceux qui ne sont pas prêts -->
    <section class="m-card divide-y">
      <p class="p-4 text-sm text-slate-600">
        {{ toAccept.length ? 'Acceptez les colis, puis cochez ceux que vous récupérez.' : 'Décochez un colis que la boutique ne vous remet pas.' }}
      </p>
      <label v-for="m in missions" :key="m.id" class="flex items-start gap-3 p-4">
        <input
          v-model="checked"
          type="checkbox"
          :value="m.id"
          class="mt-1 h-6 w-6 rounded accent-[var(--app-color)]"
          :disabled="m.status === 'assigned'"
          :aria-label="`Colis ${m.order.tracking_code}`"
        >
        <span class="flex-1 min-w-0">
          <span class="block font-semibold truncate">{{ m.order.recipient.name || m.order.recipient.phone }}</span>
          <span class="block text-sm text-slate-500 truncate">🛵 vers {{ m.order.delivery.zone_name }} · {{ m.order.package.description || 'Colis' }}</span>
          <span class="flex flex-wrap items-center gap-2 mt-1">
            <span v-if="m.status === 'assigned'" class="rounded-full bg-amber-100 text-amber-800 text-xs font-semibold px-2 py-0.5">À accepter</span>
            <StatusBadge v-else :status="m.order.status" :label="m.order.status_label" />
            <span v-if="m.offline" class="rounded-full bg-sky-100 text-sky-800 text-xs font-semibold px-2 py-0.5">⏳ À envoyer</span>
            <span v-if="m.order.package.is_fragile" class="rounded-full bg-red-100 text-red-700 text-xs font-semibold px-2 py-0.5">🥚 Fragile</span>
            <span v-if="m.order.package.is_express" class="rounded-full bg-red-100 text-red-700 text-xs font-semibold px-2 py-0.5">⚡ Express</span>
            <span class="font-mono text-xs text-slate-400">{{ m.order.tracking_code }}</span>
          </span>
        </span>
        <RouterLink :to="`/livreur/missions/${m.id}`" class="text-[var(--app-color)] text-sm font-medium whitespace-nowrap" @click.stop>Détail ›</RouterLink>
      </label>
    </section>

    <!-- Actions -->
    <div class="fixed bottom-16 inset-x-0 z-20 bg-white border-t pb-safe">
      <div class="mx-auto max-w-lg p-3 space-y-2">
        <button v-if="toAccept.length" class="m-btn-success text-lg py-5" :disabled="busy" @click="acceptAll">
          ✓ Accepter {{ toAccept.length > 1 ? `les ${toAccept.length} colis` : 'le colis' }}
        </button>
        <template v-if="active.length">
          <button v-if="toStart.length" class="m-btn-primary text-lg py-5" :disabled="busy" @click="leave">🛵 Je pars chez {{ shop.name }}</button>
          <button v-else class="m-btn-success text-lg py-5" :disabled="busy || !pickedCount" @click="openPicked">
            ✓ J'ai tout récupéré ({{ pickedCount }}/{{ active.length }})
          </button>
        </template>
        <p v-if="toAccept.length" class="text-center text-xs text-slate-500">Pour refuser un colis, ouvrez son détail.</p>
      </div>
    </div>

    <!-- Colis non remis : motif -->
    <BottomSheet :open="sheet === 'missing'" :title="`${missing.length} colis non remis`" @close="sheet = null">
      <div class="space-y-4">
        <p class="text-sm text-slate-600">Ces colis retournent à l'agence pour un prochain ramassage :</p>
        <ul class="text-sm font-medium space-y-0.5">
          <li v-for="m in missing" :key="m.id">📦 {{ m.order.recipient.name || m.order.recipient.phone }} <span class="font-mono text-xs text-slate-400">{{ m.order.tracking_code }}</span></li>
        </ul>
        <ChoiceChips v-model="incident.reason" :options="reasonOptions" label="Motif" />
        <textarea v-model="incident.note" rows="2" class="m-input" placeholder="Précisez (facultatif) : ex. colis pas encore emballé" />
        <p v-if="sheetError" class="text-red-600 text-sm">{{ sheetError }}</p>
        <button class="m-btn-success" :disabled="busy || !incident.reason" @click="confirmPicked">
          ✓ Valider : {{ pickedCount }} récupéré{{ pickedCount > 1 ? 's' : '' }}
        </button>
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
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import { useAuthStore } from '../../stores/auth'
import { cachedGet } from '../../composables/useCachedApi'
import { useToastStore } from '../../stores/toasts'
import { currentPosition } from '../../composables/useGeolocation'
import { sendStatus, withPending } from '../../composables/useOfflineQueue'
import { mapsLink, money, telLink, whatsappLink } from '../../utils/format'

/**
 * Une mission par boutique : tous les colis à ramasser chez le même client, à la même adresse.
 * Le livreur accepte le lot, part, puis valide d'un geste ; un colis décoché retourne à l'agence
 * avec un motif. Les livraisons restent une mission par colis.
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

const missions = ref([])
const checked = ref([])
const reasons = ref([])
const busy = ref(false)
const sheet = ref(null)
const sheetError = ref('')
const incident = reactive({ reason: null, note: '' })

const offlineEnabled = computed(() => !!auth.user?.company?.offline_courier_actions)
const OFFLINE_SAVED = 'Enregistré sur le téléphone : envoi automatique au retour du réseau.'

const shop = computed(() => {
  const o = missions.value[0].order
  return { name: o.merchant?.business_name || o.pickup.contact_name || 'la boutique', phone: o.pickup.phone, zone: o.pickup.zone_name, address: o.pickup.address, landmark: o.pickup.landmark, lat: o.pickup.lat, lng: o.pickup.lng }
})
const toAccept = computed(() => missions.value.filter((m) => m.status === 'assigned'))
const active = computed(() => missions.value.filter((m) => ['accepted', 'in_progress'].includes(m.status) && ['pickup_assigned', 'pickup_in_progress'].includes(m.order.status)))
const toStart = computed(() => active.value.filter((m) => m.order.status === 'pickup_assigned'))
const picked = computed(() => active.value.filter((m) => checked.value.includes(m.id)))
const missing = computed(() => active.value.filter((m) => !checked.value.includes(m.id)))
const pickedCount = computed(() => picked.value.length)
const offlineCount = computed(() => missions.value.filter((m) => m.offline).length)
const gain = computed(() => missions.value.reduce((sum, m) => sum + (m.gain || 0), 0))
const reasonOptions = computed(() => reasons.value
  .filter((r) => r.applies_to === 'both' || r.applies_to === 'pickup')
  .map((r) => ({ value: r.id, label: r.label })))

async function load() {
  let data
  try {
    ;({ data } = await cachedGet('/courier/missions'))
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return
  }
  const list = withPending(data.data).filter((m) => m.type === 'pickup' && m.pickup_group === route.params.key
    // Déjà récupéré (hors connexion compris) : sort du lot
    && ['pickup_assigned', 'pickup_in_progress'].includes(m.order.status))
  const known = new Set(missions.value.map((m) => m.id))
  missions.value = list
  // Colis cochés par défaut, y compris ceux ajoutés depuis l'ouverture
  checked.value = list.filter((m) => !known.has(m.id) || checked.value.includes(m.id)).map((m) => m.id)
  if (list.length === 1) router.replace(`/livreur/missions/${list[0].id}`)
  else if (!list.length) router.replace('/livreur')
}

async function acceptAll() {
  busy.value = true
  try {
    for (const m of toAccept.value) await http.post(`/courier/assignments/${m.id}/accept`)
    toasts.success('Ramassage accepté.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    await load()
    busy.value = false
  }
}

/**
 * Même statut pour plusieurs colis. Continue après un refus du serveur (colis annulé entre-temps)
 * et renvoie le bilan.
 */
async function sendAll(list, extra) {
  const position = await currentPosition()
  const report = { done: 0, offline: 0, errors: [] }
  for (const m of list) {
    try {
      const result = await sendStatus(m.order, { ...extra, ...(position || {}) }, offlineEnabled.value)
      result.offline ? report.offline++ : report.done++
    } catch (e) {
      report.errors.push(`${m.order.tracking_code} : ${apiErrorMessage(e)}`)
    }
  }
  return report
}

function announce(report, success) {
  if (report.errors.length) toasts.error(report.errors.join(' · '))
  if (report.offline) toasts.success(OFFLINE_SAVED)
  else if (report.done) toasts.success(success)
}

async function leave() {
  busy.value = true
  try {
    announce(await sendAll(toStart.value, { status: 'pickup_in_progress' }), 'Bonne route !')
    await load()
  } finally {
    busy.value = false
  }
}

function openPicked() {
  if (!missing.value.length) return confirmPicked()
  Object.assign(incident, { reason: null, note: '' })
  sheetError.value = ''
  sheet.value = 'missing'
}

async function confirmPicked() {
  busy.value = true
  try {
    const left = missing.value
    const done = picked.value
    const report = await sendAll(done, { status: 'picked_up' })
    if (left.length) {
      // Colis non remis : retour à « Validée », l'agence le reprogramme
      const back = await sendAll(left, { status: 'confirmed', incident_reason_id: incident.reason, note: incident.note || undefined })
      report.offline += back.offline
      report.errors.push(...back.errors)
    }
    sheet.value = null
    announce(report, `${done.length} colis récupéré${done.length > 1 ? 's' : ''}. Bravo !`)
    await load()
  } finally {
    busy.value = false
  }
}

// Actions hors connexion envoyées : état du serveur rechargé
window.addEventListener('offline-synced', load)
onBeforeUnmount(() => window.removeEventListener('offline-synced', load))

onMounted(async () => {
  await load()
  reasons.value = (await cachedGet('/incident-reasons').catch(() => ({ data: { data: [] } }))).data.data
})
</script>
