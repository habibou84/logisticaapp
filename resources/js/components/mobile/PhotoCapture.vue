<template>
  <label v-bind="$attrs" :class="['cursor-pointer', busy ? 'opacity-60 pointer-events-none' : '']">
    <slot :busy="busy" />
    <input ref="input" type="file" accept="image/*" capture="environment" class="hidden" :disabled="disabled || busy" @change="pick">
  </label>
  <DocumentScanner v-if="file" :key="attempt" :file="file" :mode="settings.mode" :quality-check="settings.quality_check" :max-side="maxSide"
                   :keep-original="settings.keep_original" :attempt="attempt" @done="done" @retake="retake" @cancel="file = null" />
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import DocumentScanner from './DocumentScanner.vue'
import { photoSettings } from '../../composables/usePhotoSettings'
import { currentPosition } from '../../composables/useGeolocation'
import { shrinkPhoto } from '../../utils/photos'
import { warmUpScanner } from '../../utils/scanner'

/**
 * Bouton « prendre une photo » de l'application livreur. Document (accusé, décharge, ticket, reçu…) et
 * scanner activé dans les réglages : la photo passe par le scanner. Sinon : photo réduite et recompressée.
 * Émet captured({ file, original, meta }).
 */
defineOptions({ inheritAttrs: false })

const props = defineProps({
  // Où la photo est prise (réglage du scanner) : delivery_proof, receipts, returns, vehicle ; vide : pas un document
  scan: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['captured'])

const input = ref(null)
const file = ref(null)
const attempt = ref(0)
const busy = ref(false)
const settings = computed(() => photoSettings())
const maxSide = computed(() => (settings.value.resolution === 'high' ? 3000 : 2000))
// Position du téléphone au moment de la prise (preuve du lieu), cherchée pendant que le livreur cadre
let position = Promise.resolve(null)
async function withPosition(photo) {
  const where = await position
  return where ? { ...photo, meta: { ...photo.meta, ...where } } : photo
}

// Bouton de document à l'écran : le scanner se prépare pendant que le livreur remplit la feuille
onMounted(() => {
  if (props.scan && settings.value.scan[props.scan]) setTimeout(warmUpScanner, 500)
})

async function pick(e) {
  const picked = e.target.files[0]
  e.target.value = ''
  if (!picked) return
  position = currentPosition(8000, true)
  if (props.scan && settings.value.scan[props.scan]) {
    attempt.value++
    file.value = picked
    return
  }
  busy.value = true
  try {
    const shrunk = await shrinkPhoto(picked, { maxSide: maxSide.value })
    emit('captured', await withPosition({ file: shrunk, original: null, meta: { scanned: false, taken_at: new Date(picked.lastModified || Date.now()).toISOString() } }))
  } finally {
    busy.value = false
  }
}

async function done(photo) {
  file.value = null
  attempt.value = 0
  busy.value = true
  try {
    emit('captured', await withPosition(photo))
  } finally {
    busy.value = false
  }
}

// Le bouton « Reprendre » est un geste de l'utilisateur : l'appareil photo peut s'ouvrir directement
function retake() {
  input.value?.click()
}
</script>
