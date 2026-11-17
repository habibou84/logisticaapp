<template>
  <!-- Scan des QR codes d'étiquettes à la caméra (plein écran) : chaque code lu est envoyé au parent -->
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[60] flex flex-col bg-black text-white">
      <div class="flex items-center justify-between p-3 pt-safe">
        <p class="font-semibold">{{ title }}</p>
        <button type="button" class="rounded-full bg-white/15 px-4 py-2 font-semibold" @click="$emit('close')">Terminer</button>
      </div>
      <div class="relative min-h-0 flex-1">
        <video ref="video" class="h-full w-full object-cover" playsinline muted />
        <!-- Cadre de visée -->
        <div class="pointer-events-none absolute inset-0 grid place-items-center">
          <div :class="['h-60 w-60 rounded-3xl border-4 transition-colors', flash === 'ok' ? 'border-emerald-400' : flash === 'ko' ? 'border-red-500' : 'border-white/70']" />
        </div>
        <p v-if="error" class="absolute inset-x-4 top-4 rounded-xl bg-red-600 p-3 text-sm">{{ error }}</p>
      </div>
      <div class="space-y-2 p-3 pb-safe">
        <p v-if="message" :class="['rounded-xl p-3 text-center font-semibold', message.ok ? 'bg-emerald-600' : 'bg-red-600']">{{ message.text }}</p>
        <slot />
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import jsQR from 'jsqr'

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: 'Scanner les colis' },
  // Dernier résultat à afficher : { ok, text }
  message: { type: Object, default: null },
})
const emit = defineEmits(['close', 'code'])

const video = ref(null)
const error = ref('')
const flash = ref('')
let stream = null
let detector = null
let timer = null
let canvas = null
const recent = new Map()

/** Code de suivi lu dans le QR (lien « …/suivi/CODE ») ou le texte brut */
function trackingCode(text) {
  const raw = String(text || '').trim()
  const match = raw.match(/\/suivi\/([A-Za-z0-9-]+)/)
  return (match ? match[1] : raw).toUpperCase()
}

function found(text) {
  const code = trackingCode(text)
  if (!code) return
  // Même étiquette encore devant la caméra : lue une seule fois
  const last = recent.get(code)
  if (last && Date.now() - last < 2500) return
  recent.set(code, Date.now())
  emit('code', code)
}

async function tick() {
  const v = video.value
  if (!v || v.readyState < 2) return
  try {
    if (detector) {
      for (const b of await detector.detect(v)) found(b.rawValue)
      return
    }
    canvas ??= document.createElement('canvas')
    const w = 640
    const h = Math.round((v.videoHeight / v.videoWidth) * w) || 480
    canvas.width = w
    canvas.height = h
    const ctx = canvas.getContext('2d', { willReadFrequently: true })
    ctx.drawImage(v, 0, 0, w, h)
    const result = jsQR(ctx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'dontInvert' })
    if (result?.data) found(result.data)
  } catch { /* image suivante */ }
}

async function start() {
  error.value = ''
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false })
  } catch {
    error.value = 'Caméra indisponible : autorisez-la dans les réglages du téléphone, ou tapez le code du colis.'
    return
  }
  await nextTick()
  if (!video.value) return stop()
  video.value.srcObject = stream
  await video.value.play().catch(() => {})
  try {
    if ('BarcodeDetector' in window && (await window.BarcodeDetector.getSupportedFormats()).includes('qr_code')) {
      detector = new window.BarcodeDetector({ formats: ['qr_code'] })
    }
  } catch { detector = null }
  timer = setInterval(tick, 200)
}

function stop() {
  clearInterval(timer)
  timer = null
  stream?.getTracks().forEach((t) => t.stop())
  stream = null
  recent.clear()
}

// Retour visuel et vibration à chaque résultat
watch(() => props.message, (m) => {
  if (!m) return
  flash.value = m.ok ? 'ok' : 'ko'
  try { navigator.vibrate?.(m.ok ? 80 : [60, 60, 60]) } catch { /* sans vibreur */ }
  setTimeout(() => { flash.value = '' }, 700)
})
watch(() => props.open, (o) => (o ? start() : stop()))
onBeforeUnmount(stop)
</script>
