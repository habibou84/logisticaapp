<template>
  <!-- Pointage d'arrivée : lieu du jour (agence, client MAD ou première étape du parcours), distance en direct -->
  <div v-if="state?.place && !state.shift" class="rounded-2xl bg-white p-4 ring-2 ring-[var(--app-color)] space-y-3">
    <div class="flex items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-xl">📍</span>
      <div class="min-w-0 flex-1">
        <p class="font-semibold">Pointer mon arrivée</p>
        <p class="text-sm text-slate-700">
          <template v-if="state.place.stops.length">Étape 1 du parcours « {{ state.place.routine }} » : </template>
          <strong>{{ state.place.label }}</strong>
        </p>
        <p v-if="state.place.address" class="text-sm text-slate-600">{{ state.place.address }}</p>
        <p class="text-sm text-slate-600">
          <template v-if="state.place.expected_at">Attendu à {{ hm(state.place.expected_at) }}</template>
          <template v-if="state.place.merchant"> · MAD chez {{ state.place.merchant }}</template>
        </p>
      </div>
    </div>

    <p v-if="!located" class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">Ce lieu n'a pas encore de position sur la carte : demandez à l'équipe de vous pointer.</p>
    <template v-else>
      <p :class="['rounded-xl px-3 py-2 text-sm', near ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-50 text-slate-700']">
        <template v-if="gpsError">{{ gpsError }}</template>
        <template v-else-if="here === null">Recherche de votre position…</template>
        <template v-else-if="near">✅ Vous êtes sur place ({{ meters(here.distance) }}, GPS ± {{ here.accuracy }} m).</template>
        <template v-else>Vous êtes à {{ meters(here.distance) }} du lieu. Il faut être à moins de {{ state.place.radius }} m pour pointer.</template>
        <span v-if="here && here.accuracy > state.settings.min_accuracy" class="block text-xs">GPS imprécis (± {{ here.accuracy }} m) : sortez à découvert et attendez quelques secondes.</span>
      </p>
      <div class="flex gap-2">
        <button type="button" class="m-btn-primary" :disabled="busy || (here !== null && !near)" @click="clockIn">{{ busy ? 'Pointage…' : '✋ Pointer mon arrivée' }}</button>
        <a :href="directionsLink(state.place.lat, state.place.lng)" target="_blank" rel="noopener" class="m-btn-secondary w-auto px-4">Itinéraire</a>
      </div>
    </template>
  </div>

  <!-- Pointé : heure, et le parcours à suivre -->
  <div v-else-if="state?.shift" class="rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-200 text-emerald-900">
    <p class="font-semibold">✅ Pointé à {{ hm(state.shift.clocked_in_at) }}<template v-if="state.shift.place_label"> · {{ state.shift.place_label }}</template></p>
    <p v-if="state.shift.late_minutes" class="text-sm">{{ state.shift.late_minutes }} min de retard</p>
    <p v-if="state.shift.method === 'manual'" class="text-sm">Pointé par {{ state.shift.recorded_by || 'l\'équipe' }}</p>
    <ol v-if="state.place?.stops?.length" class="mt-3 space-y-2">
      <li v-for="s in state.place.stops" :key="s.position" class="flex gap-3 rounded-xl bg-white/70 p-2 text-sm text-slate-800">
        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-600 text-xs font-bold text-white">{{ s.position }}</span>
        <span class="min-w-0 flex-1">
          <strong>{{ s.action_label }}</strong> · {{ s.label }}<template v-if="s.expected_time"> · vers {{ s.expected_time }}</template>
          <span v-if="s.address" class="block text-slate-600">{{ s.address }}</span>
          <span v-if="s.instructions" class="block text-slate-600">« {{ s.instructions }} »</span>
        </span>
        <a v-if="s.lat" :href="directionsLink(s.lat, s.lng)" target="_blank" rel="noopener" class="self-center font-semibold text-[var(--app-color)]">Aller</a>
      </li>
    </ol>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { directionsLink, distance, hm, meters } from '../../utils/shifts'

const emit = defineEmits(['clocked'])
const toasts = useToastStore()
const state = ref(null)
const busy = ref(false)
const position = ref(null)
const gpsError = ref('')
let watchId = null

const located = computed(() => state.value?.place?.lat != null && state.value?.place?.lng != null)
const here = computed(() => {
  if (!position.value || !located.value) return null
  return { ...position.value, distance: Math.round(distance(position.value.lat, position.value.lng, state.value.place.lat, state.value.place.lng)) }
})
// Même règle que le serveur : la distance, moins l'imprécision du GPS, dans le rayon
const near = computed(() => here.value !== null && here.value.accuracy <= state.value.settings.min_accuracy
  && Math.max(0, here.value.distance - here.value.accuracy) <= state.value.place.radius)

async function load() {
  try {
    state.value = (await http.get('/courier/shift')).data.data
  } catch {
    state.value = null
  }
}

function startWatch() {
  if (watchId !== null || !navigator.geolocation) return
  watchId = navigator.geolocation.watchPosition(
    (p) => {
      gpsError.value = ''
      position.value = { lat: p.coords.latitude, lng: p.coords.longitude, accuracy: Math.round(p.coords.accuracy || 9999) }
    },
    () => { gpsError.value = 'Position introuvable : activez la localisation du téléphone.' },
    { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 },
  )
}
function stopWatch() {
  if (watchId !== null) navigator.geolocation.clearWatch(watchId)
  watchId = null
}
watch(() => state.value?.place && !state.value?.shift && located.value, (on) => (on ? startWatch() : stopWatch()))

async function clockIn() {
  busy.value = true
  try {
    const p = position.value
    const { data } = await http.post('/courier/shift', p ? { lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)), accuracy: p.accuracy } : {})
    state.value = data.data
    toasts.success(data.message)
    emit('clocked', data.data.is_available)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(load)
onBeforeUnmount(stopWatch)
defineExpose({ load })
</script>
