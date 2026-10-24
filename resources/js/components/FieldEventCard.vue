<template>
  <article :id="`evenement-${event.id}`" :class="['card border-l-4', BORDERS[event.status === 'resolved' ? 'resolved' : event.severity], highlighted ? 'ring-2 ring-amber-300' : '']">
    <!-- En-tête, toujours visible -->
    <button type="button" class="w-full p-4 text-left flex flex-wrap items-start gap-3" :aria-expanded="expanded" @click="toggle">
      <span class="text-3xl leading-none" aria-hidden="true">{{ event.icon }}</span>
      <span class="min-w-0 flex-1 space-y-1">
        <span class="flex flex-wrap items-center gap-2">
          <span class="font-semibold">{{ event.type_label }}</span>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', SEVERITY_STYLES[event.severity]]">{{ event.severity_label }}</span>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS_STYLES[event.status]]">{{ statusText }}</span>
          <span v-if="unread" class="rounded-full bg-sky-600 text-white px-2 py-0.5 text-xs">💬 {{ unread }}</span>
        </span>
        <span class="block text-sm text-gray-700">
          🛵 <strong>{{ event.courier?.name }}</strong> · {{ capacityText }} · <span class="text-gray-500">{{ dateTime(event.created_at) }}</span>
        </span>
        <span v-if="event.note" class="block text-sm">« {{ event.note }} »</span>
        <span class="block text-xs text-gray-500">
          {{ event.orders.length }} course{{ event.orders.length > 1 ? 's' : '' }} touchée{{ event.orders.length > 1 ? 's' : '' }}
          <template v-if="event.files.length"> · {{ photoCount ? `📷 ${photoCount}` : '' }}{{ voiceCount ? ` 🎙️ ${voiceCount}` : '' }}</template>
          <template v-if="event.cost"> · 💸 {{ money(event.cost) }}</template>
        </span>
      </span>
      <span class="text-gray-400">{{ expanded ? '▲' : '▼' }}</span>
    </button>

    <div v-if="expanded" class="border-t px-4 pb-4 pt-3 grid gap-4 lg:grid-cols-[1fr_18rem]">
      <div class="space-y-4 min-w-0">
        <!-- Effets appliqués -->
        <div v-if="effects.length" class="flex flex-wrap gap-1.5">
          <span v-for="e in effects" :key="e" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ e }}</span>
        </div>

        <!-- Lieu, photos, note vocale -->
        <div v-if="event.lat !== null || media.length" class="flex flex-wrap gap-3 items-start">
          <div v-if="event.lat !== null" class="space-y-1">
            <div ref="mapEl" class="h-36 w-56 rounded-lg ring-1 ring-slate-200 z-0" />
            <a :href="`https://maps.google.com/?q=${event.lat},${event.lng}`" target="_blank" rel="noopener" class="text-xs text-blue-600">📍 Ouvrir dans Google Maps</a>
          </div>
          <template v-for="m in media" :key="m.id">
            <a v-if="m.kind === 'photo'" :href="m.src" target="_blank" rel="noopener"><img :src="m.src" alt="Photo du livreur" class="h-36 w-36 rounded-lg object-cover ring-1 ring-slate-200"></a>
            <div v-else class="space-y-1">
              <audio :src="m.src" controls class="w-60" />
              <p class="text-xs text-gray-500">Note vocale{{ m.duration ? ` · ${m.duration} s` : '' }}</p>
            </div>
          </template>
        </div>

        <!-- Courses touchées -->
        <div v-if="event.orders.length" class="space-y-1">
          <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-semibold">Courses touchées</p>
            <label v-if="movable.length" class="text-xs flex items-center gap-1"><input type="checkbox" :checked="allChecked" @change="toggleAll"> Tout cocher</label>
          </div>
          <label v-for="o in event.orders" :key="o.id" class="flex items-center gap-2 rounded border border-slate-200 px-2 py-1.5 text-sm">
            <input v-model="selected" type="checkbox" :value="o.id" :disabled="!o.courier_still_assigned" :aria-label="`Choisir ${o.tracking_code}`">
            <RouterLink :to="`/admin/courses/${o.id}`" class="font-mono text-blue-700 whitespace-nowrap">{{ o.tracking_code }}</RouterLink>
            <span class="min-w-0 truncate text-gray-600">{{ o.merchant }} → {{ o.recipient_name || 'destinataire' }}{{ o.zone ? ` (${o.zone})` : '' }}</span>
            <span class="ml-auto shrink-0 text-xs text-gray-500">{{ o.status_label }}{{ o.held_by_courier ? ' · colis chez lui' : '' }}{{ o.courier_still_assigned ? '' : ' · confiée à un autre' }}</span>
          </label>
        </div>

        <!-- Échange avec le livreur -->
        <div class="space-y-2">
          <p class="text-sm font-semibold">Échange avec {{ event.courier?.name }}</p>
          <div v-for="m in event.messages" :key="m.id" :class="['max-w-[85%] rounded-lg px-3 py-2 text-sm', m.from_courier ? 'bg-slate-100' : 'ml-auto bg-sky-50']">
            <p class="text-xs text-gray-500">{{ m.from_courier ? '🛵' : '💬' }} {{ m.sender }} · {{ time(m.created_at) }}</p>
            <p class="whitespace-pre-line">{{ m.body }}</p>
          </div>
          <form v-if="event.status !== 'resolved'" class="flex gap-2" @submit.prevent="sendMessage">
            <input v-model="body" class="input text-sm flex-1" placeholder="Message au livreur" aria-label="Message au livreur">
            <button class="btn-primary text-sm" :disabled="busy || !body.trim()">Envoyer</button>
          </form>
        </div>

        <p v-if="event.status === 'resolved'" class="text-sm text-emerald-700">
          ✓ Réglé le {{ dateTime(event.resolved_at) }}{{ event.resolved_by ? ` par ${event.resolved_by}` : '' }}<span v-if="event.resolution"> : {{ event.resolution }}</span>
        </p>
      </div>

      <!-- Actions -->
      <div class="space-y-2">
        <a v-if="event.courier?.phone" :href="telLink(event.courier.phone)" class="btn-secondary w-full text-center block">📞 Appeler {{ firstName }}</a>
        <template v-if="event.status !== 'resolved'">
          <button v-if="event.status === 'open'" type="button" class="btn-primary w-full" :disabled="busy" @click="act('take')">👍 Je m'en occupe</button>
          <button type="button" class="btn-secondary w-full" :disabled="busy || !selectedMovable.length" @click="openReassign">🔁 Confier {{ selectedMovable.length ? `${selectedMovable.length} course${selectedMovable.length > 1 ? 's' : ''}` : 'des courses' }} à un autre livreur</button>
          <button v-if="hasPickups" type="button" class="btn-secondary w-full" :disabled="busy" @click="act('release', 'Ramassages retirés au livreur.')">↩️ Retirer ses ramassages</button>
          <button v-if="event.orders.length" type="button" class="btn-secondary w-full" :disabled="busy" @click="act('notify-clients', null)">📣 Prévenir les clients du retard</button>
          <textarea v-model="resolution" rows="2" class="input text-sm" placeholder="Ce qui a été fait (facultatif)" aria-label="Ce qui a été fait" />
          <button type="button" class="btn-success w-full" :disabled="busy" @click="act('resolve', 'Événement réglé.', { resolution })">✓ Marquer comme réglé</button>
        </template>
        <button v-else type="button" class="btn-secondary w-full text-xs" :disabled="busy" @click="act('reopen')">Rouvrir</button>
      </div>
    </div>

    <!-- Choix du livreur qui reprend les courses -->
    <Modal :open="reassigning" :title="`Confier ${selectedMovable.length} course(s) à…`" @close="reassigning = false">
      <p v-if="!candidates" class="text-sm text-gray-500">Recherche des livreurs en service…</p>
      <p v-else-if="!candidates.length" class="text-sm text-gray-600">Aucun autre livreur en service pour le moment.</p>
      <div v-else class="space-y-2">
        <p class="text-xs text-gray-500">Livreurs en service, du plus proche au plus loin {{ event.lat !== null ? 'du lieu de l\'événement' : 'de la dernière position connue' }}.</p>
        <label v-for="c in candidates" :key="c.id" :class="['flex items-center gap-3 rounded border px-3 py-2 cursor-pointer', target === c.id ? 'border-slate-900 bg-slate-50' : 'border-slate-200']">
          <input v-model="target" type="radio" :value="c.id">
          <span class="flex-1">
            <span class="block font-medium">{{ c.name }}</span>
            <span class="block text-xs text-gray-500">{{ c.distance_km !== null ? `à ${c.distance_km} km` : 'position inconnue' }} · {{ c.active }} mission(s) en cours</span>
          </span>
        </label>
        <p v-if="heldCount" class="text-sm rounded bg-amber-50 text-amber-900 px-3 py-2">
          {{ heldCount }} colis {{ heldCount > 1 ? 'sont' : 'est' }} déjà chez {{ firstName }} : le nouveau livreur reçoit une consigne pour aller {{ heldCount > 1 ? 'les' : 'le' }} récupérer auprès de lui{{ event.lat !== null ? ', avec sa position' : '' }}.
        </p>
        <p v-if="error" class="field-error">{{ error }}</p>
        <button type="button" class="btn-primary w-full" :disabled="busy || !target" @click="reassign">Confier les courses</button>
      </div>
    </Modal>
  </article>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { authImage } from '../composables/useAuthImage'
import { SEVERITY_STYLES } from '../composables/useFieldEvents'
import { useToastStore } from '../stores/toasts'
import { dateTime, money, telLink } from '../utils/format'
import Modal from './Modal.vue'

/**
 * Événement terrain côté équipe : détail (lieu, photos, note vocale, courses), échange avec le livreur et
 * actions (prise en charge, confier ses courses à un autre, retirer ses ramassages, prévenir les clients, régler).
 */
const props = defineProps({
  event: { type: Object, required: true },
  open: { type: Boolean, default: false },
  highlighted: { type: Boolean, default: false },
})
const emit = defineEmits(['updated'])

const BORDERS = { urgent: 'border-l-red-600', blocking: 'border-l-orange-500', hindrance: 'border-l-amber-400', info: 'border-l-sky-400', resolved: 'border-l-slate-300' }
const STATUS_STYLES = { open: 'bg-red-600 text-white', taken: 'bg-indigo-100 text-indigo-800', resolved: 'bg-emerald-100 text-emerald-800' }
const toasts = useToastStore()
const expanded = ref(props.open)
const media = ref([])
const body = ref('')
const resolution = ref('')
const busy = ref(false)
const error = ref('')
const selected = ref([])
const reassigning = ref(false)
const candidates = ref(null)
const target = ref(null)
const mapEl = ref(null)
let map = null

const time = (v) => new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const firstName = computed(() => (props.event.courier?.name || 'le livreur').split(' ')[0])
const photoCount = computed(() => props.event.files.filter((f) => f.kind === 'photo').length)
const voiceCount = computed(() => props.event.files.filter((f) => f.kind === 'voice').length)
const unread = computed(() => props.event.messages.filter((m) => m.from_courier && !m.read_at).length)
const statusText = computed(() => ({ open: 'À prendre en charge', taken: `Suivi par ${props.event.taken_by || '—'}`, resolved: 'Réglé' })[props.event.status])
const capacityText = computed(() => {
  const e = props.event
  if (e.capacity === 'blocked' && e.blocked_until) return `bloqué jusqu'à ${time(e.blocked_until)} environ`
  return e.capacity_label
})
const movable = computed(() => props.event.orders.filter((o) => o.courier_still_assigned))
const selectedMovable = computed(() => movable.value.filter((o) => selected.value.includes(o.id)))
const allChecked = computed(() => movable.value.length > 0 && selectedMovable.value.length === movable.value.length)
const heldCount = computed(() => selectedMovable.value.filter((o) => o.held_by_courier).length)
const hasPickups = computed(() => props.event.orders.some((o) => o.courier_still_assigned && o.status === 'pickup_assigned'))
const effects = computed(() => {
  const e = props.event.effects || {}
  return [
    e.paused && (e.unpaused ? 'Mis hors service, puis remis en service' : 'Livreur mis hors service'),
    e.released?.length && `${e.released.length} ramassage(s) retiré(s)`,
    e.reassigned?.length && `${e.reassigned.length} course(s) confiée(s) à un autre`,
    e.absence_id && 'Absence du jour notée au planning',
    e.notified?.length && `${e.notified.length} client(s) prévenu(s) du retard`,
    props.event.excused && 'Retards excusés (note, primes)',
  ].filter(Boolean)
})

function toggleAll(e) {
  selected.value = e.target.checked ? movable.value.map((o) => o.id) : []
}

async function loadMedia() {
  if (media.value.length || !props.event.files.length) return
  media.value = (await Promise.all(props.event.files.map(async (f) => ({ ...f, src: await authImage(f.url).catch(() => null) })))).filter((m) => m.src)
}

async function drawMap() {
  await nextTick()
  if (!mapEl.value || map || props.event.lat === null) return
  map = L.map(mapEl.value, { zoomControl: false, attributionControl: false }).setView([props.event.lat, props.event.lng], 15)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map)
  L.marker([props.event.lat, props.event.lng], {
    icon: L.divIcon({ className: '', html: `<div style="font-size:26px;line-height:1">${props.event.icon || '⚠️'}</div>`, iconSize: [28, 28], iconAnchor: [14, 14] }),
  }).addTo(map)
}

async function toggle() {
  expanded.value = !expanded.value
}

watch(expanded, async (open) => {
  if (!open) {
    map?.remove()
    map = null
    return
  }
  selected.value = movable.value.map((o) => o.id)
  loadMedia()
  drawMap()
  // Messages du livreur marqués comme lus
  if (unread.value) {
    const { data } = await http.get(`/field-events/${props.event.id}`).catch(() => ({ data: null }))
    if (data) emit('updated', data.data)
  }
}, { immediate: true })

async function act(action, success = null, payload = {}) {
  busy.value = true
  try {
    const { data } = await http.post(`/field-events/${props.event.id}/${action}`, payload)
    emit('updated', data.data, data.open)
    if (action === 'notify-clients') toasts.success(data.result.notified ? `${data.result.notified} client(s) prévenu(s).` : 'Les clients concernés étaient déjà prévenus.')
    else if (action === 'release') toasts.success(data.result.released.length ? `${data.result.released.length} ramassage(s) retiré(s) : planning ou dispatch.` : 'Aucun ramassage à retirer.')
    else if (success) toasts.success(success)
    if (action === 'resolve') resolution.value = ''
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function sendMessage() {
  busy.value = true
  try {
    const { data } = await http.post(`/field-events/${props.event.id}/messages`, { body: body.value })
    body.value = ''
    emit('updated', data.data, data.open)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function openReassign() {
  reassigning.value = true
  error.value = ''
  candidates.value = null
  target.value = null
  candidates.value = (await http.get(`/field-events/${props.event.id}/candidates`)).data.data
  target.value = candidates.value[0]?.id ?? null
}

async function reassign() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/field-events/${props.event.id}/reassign`, { courier_id: target.value, order_ids: selectedMovable.value.map((o) => o.id) })
    emit('updated', data.data, data.open)
    reassigning.value = false
    const errors = Object.values(data.result.errors)
    toasts.success(`${data.result.assigned.length} course(s) confiée(s)${data.result.handover ? `, dont ${data.result.handover} colis à récupérer` : ''}.`)
    if (errors.length) toasts.error(errors.join(' · '))
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onBeforeUnmount(() => {
  map?.remove()
  media.value.forEach((m) => URL.revokeObjectURL(m.src))
})
</script>
