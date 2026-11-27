<template>
  <!-- Mon itinéraire : tous les arrêts dans l'ordre (ramassages ou collectes, livraisons, retours), carte, trajet Google Maps -->
  <div class="space-y-4 pb-6">
    <div class="m-card space-y-3 p-4">
      <p class="text-sm text-slate-600">
        Tous vos arrêts : ramassages, livraisons et retours. L'ordre est calculé à vol d'oiseau depuis votre position : colis express
        d'abord, puis le plus court chemin. Un colis est toujours ramassé avant d'être livré. Changez l'ordre avec les flèches.
      </p>
      <button type="button" class="m-btn-primary" :disabled="busy" @click="optimize"><Icone v-if="!busy" nom="organiser" />{{ busy ? 'Calcul…' : 'Organiser depuis ma position' }}</button>
      <p v-if="route?.unplaced && route.stops.length > route.unplaced" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
        {{ route.unplaced }} nouvel{{ route.unplaced > 1 ? 's' : '' }} arrêt{{ route.unplaced > 1 ? 's' : '' }} pas encore placé{{ route.unplaced > 1 ? 's' : '' }} : réorganisez pour l'inclure.
      </p>
      <p v-if="route?.total_meters" class="text-sm text-slate-600">≈ {{ meters(route.total_meters) }} à vol d'oiseau · {{ route.stops.length }} arrêt{{ route.stops.length > 1 ? 's' : '' }}</p>
    </div>

    <p v-if="loading" class="py-8 text-center text-slate-400">Chargement…</p>
    <p v-else-if="error" class="py-8 text-center text-red-600">{{ error }}</p>
    <p v-else-if="!route?.stops.length" class="m-card p-4 text-center text-slate-600">Aucun arrêt pour le moment.</p>

    <template v-else>
      <div ref="mapEl" class="h-64 overflow-hidden rounded-2xl ring-1 ring-slate-200" />

      <!-- Trajet dans Google Maps, par morceaux (nombre d'arrêts limité par trajet) -->
      <div class="flex flex-wrap gap-2">
        <a v-for="(link, i) in mapsLinks" :key="i" :href="link.url" target="_blank" rel="noopener" class="m-btn-secondary w-auto flex-1 px-3 text-center text-sm">
          <Icone nom="trajet" />{{ mapsLinks.length > 1 ? `Arrêts ${link.from} à ${link.to}` : 'Ouvrir le trajet dans Google Maps' }}
        </a>
      </div>

      <ol class="m-card divide-y overflow-hidden">
        <li v-for="(s, i) in route.stops" :key="s.assignment_ids[0]" class="flex items-start gap-3 p-3">
          <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-bold text-white', s.is_express ? 'bg-red-600' : 'bg-[var(--app-color)]']">{{ i + 1 }}</span>
          <RouterLink :to="`/livreur/missions/${s.assignment_ids[0]}`" class="min-w-0 flex-1">
            <span class="block font-semibold">{{ [...new Set(s.orders.map((o) => o.recipient))].join(', ') }}</span>
            <span class="block text-sm font-medium text-slate-800"><template v-for="(t, k) in tasks(s)" :key="t.icon"><template v-if="k"> · </template><Icone :nom="t.icon" taille="petite" class="inline" /> {{ t.text }}</template></span>
            <span class="block text-sm text-slate-600"><Icone nom="adresse" taille="petite" class="inline" /> {{ s.zone }}<template v-if="s.address"> · {{ s.address }}</template></span>
            <span class="mt-1 flex flex-wrap gap-1.5 text-xs">
              <span v-if="s.is_express" class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 font-semibold text-red-700"><Icone nom="rapide" taille="petite" />Express</span>
              <span v-if="s.precision === 'zone'" class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800">Position approximative (quartier)</span>
              <span v-if="s.precision === 'unknown'" class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">Position inconnue</span>
              <span v-if="!s.placed" class="rounded-full bg-sky-100 px-2 py-0.5 text-sky-800">Nouvelle</span>
              <span v-if="s.leg_meters !== null" class="text-slate-600">{{ meters(s.leg_meters) }}</span>
            </span>
          </RouterLink>
          <span class="flex flex-col">
            <button type="button" class="px-2 py-1 text-lg text-slate-600 disabled:opacity-30" :disabled="busy || i === 0" :aria-label="`Monter l'arrêt ${i + 1}`" @click="move(i, -1)">↑</button>
            <button type="button" class="px-2 py-1 text-lg text-slate-600 disabled:opacity-30" :disabled="busy || i === route.stops.length - 1" :aria-label="`Descendre l'arrêt ${i + 1}`" @click="move(i, 1)">↓</button>
          </span>
        </li>
      </ol>
    </template>
  </div>
</template>

<script setup>
import Icone from '../../components/ui/Icone.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { currentPosition } from '../../composables/useGeolocation'
import { useToastStore } from '../../stores/toasts'
import { meters } from '../../utils/shifts'

const toasts = useToastStore()

// Ce qu'il y a à faire à cet arrêt : « Ramasser 2 colis · Livrer », chacun avec son icône
const TASKS = { pickup: ['ramasser', 'Ramasser'], delivery: ['livrer', 'Livrer'], return: ['retourner', 'Rendre'] }
function tasks(stop) {
  return Object.entries(TASKS).map(([type, [icon, verb]]) => {
    const n = stop.orders.filter((o) => o.type === type).length
    return n ? { icon, text: `${verb}${n > 1 ? ` ${n} colis` : ''}` } : null
  }).filter(Boolean)
}
const route = ref(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const here = ref(null)
const mapEl = ref(null)
let map = null
let layer = null

async function load() {
  try {
    here.value = await currentPosition(4000)
    route.value = (await http.get('/courier/route', { params: here.value || {} })).data.data
    error.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger l\'itinéraire.')
  } finally {
    loading.value = false
  }
}

async function optimize() {
  busy.value = true
  try {
    here.value = await currentPosition(8000)
    if (!here.value) toasts.error('Position introuvable : l\'itinéraire part de votre mission la plus ancienne.')
    route.value = (await http.post('/courier/route/optimize', here.value || {})).data.data
    toasts.success('Itinéraire organisé : vos arrêts suivent cet ordre.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function move(i, d) {
  const stops = [...route.value.stops]
  ;[stops[i], stops[i + d]] = [stops[i + d], stops[i]]
  route.value = { ...route.value, stops }
  busy.value = true
  try {
    route.value = (await http.put('/courier/route', { assignment_ids: stops.flatMap((s) => s.assignment_ids), ...(here.value || {}) })).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

// Google Maps : départ de ma position, au plus 9 arrêts intermédiaires par trajet
const mapsLinks = computed(() => {
  const located = (route.value?.stops || []).map((s, i) => ({ ...s, n: i + 1 })).filter((s) => s.lat !== null)
  const links = []
  for (let k = 0; k < located.length; k += 10) {
    const part = located.slice(k, k + 10)
    const last = part[part.length - 1]
    const origin = k === 0 ? (here.value ? `&origin=${here.value.lat},${here.value.lng}` : '') : `&origin=${located[k - 1].lat},${located[k - 1].lng}`
    const waypoints = part.slice(0, -1).map((s) => `${s.lat},${s.lng}`).join('|')
    links.push({
      from: part[0].n,
      to: last.n,
      url: `https://www.google.com/maps/dir/?api=1${origin}&destination=${last.lat},${last.lng}${waypoints ? `&waypoints=${encodeURIComponent(waypoints)}` : ''}&travelmode=driving`,
    })
  }
  return links
})

function draw() {
  if (!mapEl.value || !route.value?.stops.length) return
  if (!map) {
    map = L.map(mapEl.value, { zoomControl: true, attributionControl: false })
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map)
  }
  layer?.remove()
  layer = L.layerGroup().addTo(map)
  const points = []
  if (here.value) {
    L.circleMarker([here.value.lat, here.value.lng], { radius: 7, color: '#2563eb', fillOpacity: 0.9 }).addTo(layer)
    points.push([here.value.lat, here.value.lng])
  }
  route.value.stops.forEach((s, i) => {
    if (s.lat === null) return
    const icon = L.divIcon({
      className: '',
      iconSize: [28, 28],
      iconAnchor: [14, 14],
      html: `<div style="width:28px;height:28px;border-radius:9999px;display:grid;place-items:center;color:#fff;font-weight:700;font-size:13px;background:${s.is_express ? '#dc2626' : 'var(--app-color,#1f2937)'};opacity:${s.precision === 'exact' ? 1 : 0.6};box-shadow:0 1px 3px rgba(0,0,0,.4)">${i + 1}</div>`,
    })
    L.marker([s.lat, s.lng], { icon }).addTo(layer)
    points.push([s.lat, s.lng])
  })
  if (points.length > 1) L.polyline(points, { color: '#64748b', weight: 2, dashArray: '6 6' }).addTo(layer)
  if (points.length) map.fitBounds(points, { padding: [30, 30], maxZoom: 16 })
}

watch(route, async () => {
  await nextTick()
  draw()
})
onMounted(load)
onBeforeUnmount(() => map?.remove())
</script>
