<template>
  <div class="space-y-2">
    <div class="flex flex-wrap gap-2">
      <button type="button" :class="buttonClass" :disabled="locating" @click="useMyPosition">
        {{ locating ? 'Localisation…' : gpsLabel }}
      </button>
      <form v-if="allowLink" class="flex flex-1 min-w-56 gap-2" @submit.prevent="useLink">
        <input v-model.trim="link" class="input flex-1" placeholder="Coller un lien Google Maps ou « 5.36, -3.97 »" aria-label="Lien Google Maps">
        <button type="submit" class="btn-secondary" :disabled="!link || resolving">{{ resolving ? '…' : 'Placer' }}</button>
      </form>
    </div>
    <p v-if="message" :class="['text-sm', messageError ? 'text-red-600' : 'text-slate-600']">{{ message }}</p>

    <div class="relative rounded-xl overflow-hidden ring-1 ring-slate-200">
      <div ref="mapEl" :class="['w-full z-0', height]" role="application" aria-label="Carte : touchez pour placer le repère" />
      <p v-if="!hasPoint" class="absolute inset-x-3 top-3 z-[400] rounded-lg bg-white/95 px-3 py-2 text-xs text-slate-600 shadow">
        Touchez la carte à l'emplacement exact, ou utilisez votre position.
      </p>
    </div>
    <p v-if="hasPoint" class="text-xs text-slate-500">Déplacez le repère ou touchez la carte pour corriger.</p>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import http, { apiErrorMessage } from '../bootstrap/axios'

/**
 * Choix d'un point sur la carte : position GPS du téléphone, lien Google Maps collé,
 * ou repère placé à la main. v-model : { lat, lng, accuracy } ou null.
 */
const props = defineProps({
  modelValue: { type: Object, default: null },
  allowLink: { type: Boolean, default: false },
  gpsLabel: { type: String, default: '📍 Utiliser ma position actuelle' },
  buttonClass: { type: String, default: 'btn-secondary' },
  height: { type: String, default: 'h-64' },
})
const emit = defineEmits(['update:modelValue'])

const ABIDJAN = [5.345, -4.024]
// Repère en forme d'épingle, pointe sur la position
const PIN = L.divIcon({
  className: '',
  iconSize: [32, 40],
  iconAnchor: [16, 40],
  html: '<div class="location-pin"><span>📦</span></div>',
})
const mapEl = ref(null)
const locating = ref(false)
const resolving = ref(false)
const link = ref('')
const message = ref('')
const messageError = ref(false)
let map = null
let marker = null
let circle = null

const hasPoint = computed(() => props.modelValue?.lat != null && props.modelValue?.lng != null)

function say(text, error = false) {
  message.value = text
  messageError.value = error
}

function place(lat, lng, accuracy = null, zoom = true) {
  emit('update:modelValue', { lat: Number(lat.toFixed(7)), lng: Number(lng.toFixed(7)), accuracy })
  if (zoom && map) map.setView([lat, lng], Math.max(map.getZoom(), 17))
}

function draw() {
  if (!map) return
  if (!hasPoint.value) {
    marker?.remove()
    circle?.remove()
    marker = circle = null
    return
  }
  const { lat, lng, accuracy } = props.modelValue
  if (!marker) {
    marker = L.marker([lat, lng], { draggable: true, autoPan: true, icon: PIN, title: 'Lieu de ramassage' }).addTo(map)
    marker.on('dragend', () => {
      const p = marker.getLatLng()
      place(p.lat, p.lng, null, false)
    })
  } else {
    marker.setLatLng([lat, lng])
  }
  circle?.remove()
  circle = accuracy ? L.circle([lat, lng], { radius: accuracy, color: '#2563eb', weight: 1, fillOpacity: 0.08 }).addTo(map) : null
}

function useMyPosition() {
  if (!navigator.geolocation) return say('La localisation n\'est pas disponible sur cet appareil.', true)
  locating.value = true
  say('')
  navigator.geolocation.getCurrentPosition(
    (p) => {
      locating.value = false
      const accuracy = Math.round(p.coords.accuracy)
      place(p.coords.latitude, p.coords.longitude, accuracy)
      say(accuracy > 100
        ? `Position approximative (à ${accuracy} m près) : sortez à découvert ou ajustez le repère sur la carte.`
        : `Position trouvée (à ${accuracy} m près). Vérifiez le repère.`, accuracy > 100)
    },
    (e) => {
      locating.value = false
      say(e.code === 1
        ? 'Localisation refusée : autorisez-la pour ce site dans les réglages du navigateur, ou placez le repère sur la carte.'
        : 'Position introuvable pour le moment : réessayez, ou placez le repère sur la carte.', true)
    },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
  )
}

async function useLink() {
  resolving.value = true
  say('')
  try {
    const { data } = await http.post('/geo/link', { link: link.value })
    place(data.data.lat, data.data.lng)
    link.value = ''
    say('Repère placé d\'après le lien. Vérifiez-le sur la carte.')
  } catch (e) {
    say(apiErrorMessage(e), true)
  } finally {
    resolving.value = false
  }
}

watch(() => props.modelValue, draw, { deep: true })

onMounted(() => {
  map = L.map(mapEl.value, { zoomControl: true }).setView(hasPoint.value ? [props.modelValue.lat, props.modelValue.lng] : ABIDJAN, hasPoint.value ? 17 : 12)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; contributeurs <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map)
  map.on('click', (e) => place(e.latlng.lat, e.latlng.lng, null, false))
  draw()
  // La carte peut être montée dans une fenêtre qui s'ouvre : recalcule sa taille
  setTimeout(() => map?.invalidateSize(), 250)
})

onBeforeUnmount(() => {
  map?.remove()
  map = null
})
</script>

<style scoped>
:deep(.location-pin) {
  width: 32px;
  height: 32px;
  border-radius: 50% 50% 50% 0;
  transform: rotate(-45deg);
  background: #ea580c;
  border: 3px solid #fff;
  box-shadow: 0 2px 6px rgb(0 0 0 / 0.4);
  display: grid;
  place-items: center;
}

:deep(.location-pin span) {
  transform: rotate(45deg);
  font-size: 14px;
}
</style>
