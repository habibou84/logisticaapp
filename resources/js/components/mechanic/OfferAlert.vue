<template>
  <!-- Panne proposée : plein écran, sonnerie, réponse en un geste -->
  <div class="fixed inset-0 z-50 flex flex-col bg-gradient-to-b from-red-700 to-red-900 px-5 pb-6 text-white" :style="{ paddingTop: 'max(1.5rem, env(safe-area-inset-top))', paddingBottom: 'max(1.5rem, env(safe-area-inset-bottom))' }" role="alertdialog" aria-labelledby="offer-title">
    <div class="mx-auto mt-6 mb-4 grid h-20 w-20 place-items-center rounded-full bg-white/15 text-4xl ring-[10px] ring-white/10" aria-hidden="true">{{ repair.icon || '🛠️' }}</div>
    <p class="text-center text-xs font-semibold uppercase tracking-widest text-white/80">Nouvelle panne · {{ repair.severity_label }}</p>
    <h2 id="offer-title" class="mt-1 text-center text-3xl font-extrabold leading-tight">{{ repair.title }}</h2>

    <div class="mt-5 space-y-1.5 rounded-2xl bg-white/10 p-4 text-[15px]">
      <p v-if="repair.courier">👤 {{ repair.courier.name }}</p>
      <p v-if="repair.vehicle">🛵 {{ [repair.vehicle.plate, repair.vehicle.name].filter(Boolean).join(' · ') }}</p>
      <p v-if="repair.distance_km !== null">📍 à {{ repair.distance_km }} km</p>
      <p v-if="repair.note">« {{ repair.note }} »</p>
    </div>

    <div class="flex-1" />
    <p v-if="error" class="mb-3 rounded-xl bg-white/15 p-3 text-sm">{{ error }}</p>
    <button type="button" class="m-btn bg-white text-red-700 text-lg" :disabled="busy" @click="answer('accept')">J'accepte</button>
    <button type="button" class="m-btn mt-3 text-white ring-1 ring-white/40" :disabled="busy" @click="answer('decline')">Je ne peux pas</button>
    <p v-if="left !== null" class="mt-3 text-center font-mono text-sm text-white/80">Proposée à un autre mécanicien dans {{ left }}</p>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { playAlertSound } from '../../composables/useAlertSound'

const props = defineProps({ repair: { type: Object, required: true } })
const emit = defineEmits(['answered'])
const router = useRouter()
const busy = ref(false)
const error = ref('')
const left = ref(null)

function countdown() {
  if (!props.repair.offer_expires_at) return
  const seconds = Math.max(0, Math.round((new Date(props.repair.offer_expires_at) - Date.now()) / 1000))
  left.value = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
  if (seconds === 0) emit('answered', props.repair.id)
}

async function answer(action) {
  busy.value = true
  error.value = ''
  try {
    await http.post(`/mechanic/repairs/${props.repair.id}/${action}`, { at: new Date().toISOString() })
    emit('answered', props.repair.id)
    if (action === 'accept') router.push(`/mecanicien/pannes/${props.repair.id}`)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Réponse impossible. Vérifiez votre réseau.')
    if (e.response) setTimeout(() => emit('answered', props.repair.id), 2500)
  } finally {
    busy.value = false
  }
}

let timer
let ring
onMounted(() => {
  countdown()
  timer = setInterval(countdown, 1000)
  playAlertSound('alert', true)
  ring = setInterval(() => playAlertSound('alert', true), 6000)
  navigator.vibrate?.([500, 200, 500, 200, 500])
})
onBeforeUnmount(() => {
  clearInterval(timer)
  clearInterval(ring)
})
</script>
