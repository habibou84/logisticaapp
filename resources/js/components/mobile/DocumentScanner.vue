<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[70] flex flex-col bg-slate-950 text-white" role="dialog" aria-label="Scanner de document">
      <header class="flex items-center justify-between px-4 py-3">
        <button type="button" class="text-sm text-slate-300" @click="$emit('cancel')">Annuler</button>
        <p class="font-semibold">{{ stage === 'crop' ? 'Cadrez le document' : 'Document' }}</p>
        <span class="w-14" />
      </header>

      <!-- Analyse, premier chargement du scanner -->
      <div v-if="stage === 'loading'" class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
        <span class="h-10 w-10 animate-spin rounded-full border-4 border-slate-600 border-t-white" />
        <p>{{ message }}</p>
      </div>

      <!-- Scanner indisponible : la photo part telle quelle -->
      <div v-else-if="stage === 'error'" class="flex flex-1 flex-col items-center justify-center gap-4 p-6 text-center">
        <p>{{ message }}</p>
        <button type="button" class="m-btn-primary" :disabled="busy" @click="sendPlain">Envoyer la photo telle quelle</button>
        <button type="button" class="text-sm text-slate-300 underline" @click="$emit('retake')">Reprendre la photo</button>
      </div>

      <!-- Cadrage : quatre coins à placer sur ceux de la feuille -->
      <template v-else-if="stage === 'crop'">
        <div class="flex flex-1 items-center justify-center overflow-hidden p-4">
          <div class="relative" :style="frameStyle">
            <canvas ref="view" class="block h-full w-full" />
            <svg ref="overlay" class="absolute inset-0 h-full w-full touch-none" :viewBox="`0 0 ${viewW} ${viewH}`" preserveAspectRatio="none"
                 @pointermove="moveCorner" @pointerup="dragging = -1" @pointercancel="dragging = -1">
              <polygon :points="polygon" fill="rgba(59,130,246,.18)" stroke="#60a5fa" stroke-width="3" vector-effect="non-scaling-stroke" />
              <circle v-for="(c, i) in corners" :key="i" :cx="c[0] * viewW" :cy="c[1] * viewH" :r="handle" fill="rgba(255,255,255,.35)" stroke="#fff" stroke-width="3"
                      vector-effect="non-scaling-stroke" :aria-label="`Coin ${i + 1}`" @pointerdown.prevent="grab(i, $event)" />
            </svg>
          </div>
        </div>
        <p v-if="noDocument" class="px-4 text-center text-sm text-amber-300">{{ WARNING_LABELS.no_document }}</p>
        <p v-else-if="checkCorners" class="px-4 text-center text-sm text-amber-300">{{ WARNING_LABELS.check_corners }}</p>
        <footer class="grid grid-cols-2 gap-2 p-4">
          <button type="button" class="m-btn-secondary" @click="wholePhoto">Toute la photo</button>
          <button type="button" class="m-btn-primary" :disabled="busy" @click="process">Valider le cadrage</button>
        </footer>
      </template>

      <!-- Résultat : rendu au choix, contrôle de qualité -->
      <template v-else>
        <div class="flex flex-1 items-center justify-center overflow-hidden p-4">
          <img v-if="resultUrl" :src="resultUrl" alt="Document scanné" class="max-h-full max-w-full rounded bg-white shadow-lg" :class="busy ? 'opacity-50' : ''">
        </div>
        <div class="space-y-3 p-4">
          <div class="flex justify-center gap-2" role="group" aria-label="Rendu">
            <button v-for="(label, key) in MODES" :key="key" type="button" :aria-pressed="mode === key"
                    :class="['rounded-full px-4 py-1.5 text-sm', mode === key ? 'bg-white text-slate-900 font-semibold' : 'bg-slate-800 text-slate-200']"
                    :disabled="busy" @click="changeMode(key)">{{ label }}</button>
          </div>
          <ul v-if="warnings.length" class="space-y-1 text-sm">
            <li v-for="w in warnings" :key="w" :class="isBlocking(w) ? 'text-red-300' : 'text-amber-300'">⚠️ {{ WARNING_LABELS[w] }}</li>
          </ul>
          <div class="grid grid-cols-2 gap-2">
            <button type="button" class="m-btn-secondary" @click="$emit('retake')">📷 Reprendre</button>
            <button type="button" class="m-btn-secondary" :disabled="busy" @click="stage = 'crop'">✂️ Recadrer</button>
          </div>
          <button v-if="!blocked" type="button" class="m-btn-primary w-full" :disabled="busy" @click="finish(false)">Utiliser cette photo</button>
          <template v-else>
            <p class="text-center text-sm text-slate-300">Reprenez la photo pour qu'elle soit lisible.</p>
            <!-- Après plusieurs essais (appareil photo médiocre) : envoi possible, signalé à l'agence -->
            <button v-if="attempt >= 3" type="button" class="w-full text-sm text-slate-300 underline" :disabled="busy" @click="finish(true)">Envoyer quand même</button>
          </template>
        </div>
      </template>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { loadBitmap, originalPhoto, pixels, shrinkPhoto, toJpeg, toPng } from '../../utils/photos'
import { BLOCKING, FAR_COVERAGE, WARNING_LABELS, detectDocument, renderDocument } from '../../utils/scanner'

/**
 * Scanner de document : la feuille est trouvée sur la photo, redressée, débarrassée des ombres, rendue en
 * couleur, gris ou noir et blanc, puis contrôlée (flou, photo sombre, reflet, prise de trop loin).
 * Émet done({ file, original, meta }), retake (reprendre la photo) ou cancel.
 */
const props = defineProps({
  file: { type: File, required: true },
  mode: { type: String, default: 'color' },
  qualityCheck: { type: String, default: 'block' },
  maxSide: { type: Number, default: 2000 },
  keepOriginal: { type: Boolean, default: true },
  // Nombre de prises pour ce document : après la troisième, une photo refusée peut partir quand même
  attempt: { type: Number, default: 1 },
})
const emit = defineEmits(['done', 'retake', 'cancel'])

const MODES = { color: 'Couleur', gray: 'Gris', bw: 'Noir et blanc' }
const FULL = [[0, 0], [1, 0], [1, 1], [0, 1]]

const stage = ref('loading')
const message = ref('Analyse du document…')
const busy = ref(false)
const mode = ref(MODES[props.mode] ? props.mode : 'color')
const corners = ref(FULL.map((c) => [...c]))
const detected = ref(null)
const noDocument = ref(false)
// Feuille trouvée mais cadrage incertain (bords peu nets, bandeau foncé au bord) : le livreur vérifie les coins
const checkCorners = ref(false)
// Part de la photo occupée par la feuille cadrée (petite : prise de trop loin)
const coverage = computed(() => {
  const p = corners.value
  let s = 0
  for (let i = 0; i < 4; i++) s += p[i][0] * p[(i + 1) % 4][1] - p[(i + 1) % 4][0] * p[i][1]
  return Math.round((Math.abs(s) / 2) * 1000) / 1000
})
const quality = ref(null)
const result = ref(null)
const resultUrl = ref('')
const view = ref(null)
const overlay = ref(null)
const viewW = ref(1)
const viewH = ref(1)
const dragging = ref(-1)
let bitmap = null

const handle = computed(() => Math.max(viewW.value, viewH.value) * 0.035)
const polygon = computed(() => corners.value.map(([x, y]) => `${x * viewW.value},${y * viewH.value}`).join(' '))
// Cadre de la photo : garde ses proportions dans l'écran
const frameStyle = computed(() => ({ aspectRatio: `${viewW.value} / ${viewH.value}`, maxWidth: '100%', maxHeight: '100%', height: viewH.value > viewW.value ? '100%' : 'auto', width: viewH.value > viewW.value ? 'auto' : '100%' }))
const far = computed(() => !!detected.value && coverage.value < FAR_COVERAGE)
const warnings = computed(() => [
  ...(quality.value?.warnings || []),
  ...(noDocument.value && !detected.value ? ['no_document'] : []),
  ...(far.value ? ['far'] : []),
].filter((w, i, a) => a.indexOf(w) === i))
const isBlocking = (w) => props.qualityCheck === 'block' && BLOCKING.includes(w)
const blocked = computed(() => props.qualityCheck === 'block' && warnings.value.some(isBlocking))

onMounted(async () => {
  try {
    bitmap = await loadBitmap(props.file)
    viewW.value = bitmap.width
    viewH.value = bitmap.height
    // Premier usage : le scanner se télécharge une fois (3,5 Mo), puis reste sur le téléphone
    const slow = setTimeout(() => { message.value = 'Préparation du scanner (première fois seulement)…' }, 1500)
    const found = await detectDocument(pixels(bitmap, 1000)).finally(() => clearTimeout(slow))
    detected.value = found?.corners || null
    if (found) {
      corners.value = found.corners.map(([x, y]) => [x, y])
      if (found.confident) {
        await process()
      } else {
        checkCorners.value = true
        stage.value = 'crop'
      }
    } else {
      noDocument.value = true
      corners.value = [[0.05, 0.05], [0.95, 0.05], [0.95, 0.95], [0.05, 0.95]]
      stage.value = 'crop'
    }
  } catch (e) {
    console.warn('Scanner :', e?.message || e)
    message.value = navigator.onLine
      ? 'Le scanner n\'a pas pu traiter cette photo.'
      : 'Scanner indisponible hors connexion pour sa première utilisation.'
    stage.value = 'error'
  }
})

onBeforeUnmount(() => {
  bitmap?.close?.()
  if (resultUrl.value) URL.revokeObjectURL(resultUrl.value)
})

// Cadrage affiché : la photo dessinée sous les coins
watch(stage, (s) => s === 'crop' && drawView())

async function drawView() {
  await nextTick()
  const c = view.value
  if (!c || !bitmap) return
  const img = pixels(bitmap, 1200)
  c.width = img.width
  c.height = img.height
  c.getContext('2d').putImageData(img, 0, 0)
}

function grab(i, e) {
  dragging.value = i
  overlay.value?.setPointerCapture?.(e.pointerId)
}

function moveCorner(e) {
  if (dragging.value < 0) return
  const r = overlay.value.getBoundingClientRect()
  const x = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width))
  const y = Math.min(1, Math.max(0, (e.clientY - r.top) / r.height))
  corners.value = corners.value.map((c, i) => (i === dragging.value ? [x, y] : c))
}

function wholePhoto() {
  corners.value = FULL.map((c) => [...c])
}

const isWhole = () => corners.value.every((c, i) => c[0] === FULL[i][0] && c[1] === FULL[i][1])

/**
 * Grand côté de la photo envoyée au scanner : la feuille découpée dans la photo à sa pleine résolution,
 * réduite seulement si elle dépasse la taille voulue (maxSide), pour ne rien perdre de l'appareil photo.
 */
function sourceSide() {
  const w = bitmap.width
  const h = bitmap.height
  const p = corners.value.map(([x, y]) => [x * w, y * h])
  const d = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1])
  const page = Math.max(d(p[0], p[1]), d(p[3], p[2]), d(p[0], p[3]), d(p[1], p[2]))
  const scale = Math.min(1, (props.maxSide * 1.05) / Math.max(1, page))
  return Math.round(Math.max(w, h) * scale)
}

async function process() {
  busy.value = true
  try {
    const whole = isWhole()
    const { image, quality: q } = await renderDocument(pixels(bitmap, sourceSide()), whole ? null : corners.value.map(([x, y]) => [x, y]), mode.value, props.maxSide)
    result.value = image
    quality.value = q
    if (resultUrl.value) URL.revokeObjectURL(resultUrl.value)
    resultUrl.value = URL.createObjectURL(await toJpeg(image, 'apercu.jpg', 0.8))
    checkCorners.value = false
    stage.value = 'preview'
  } catch (e) {
    console.warn('Scanner :', e?.message || e)
    message.value = 'Le scanner n\'a pas pu traiter cette photo.'
    stage.value = 'error'
  } finally {
    busy.value = false
  }
}

function changeMode(key) {
  mode.value = key
  process()
}

async function finish(overridden) {
  busy.value = true
  try {
    // Noir et blanc : PNG, sans perte (traits nets, fichier léger) ; couleur et gris : JPEG
    const file = mode.value === 'bw' ? await toPng(result.value, 'document.png') : await toJpeg(result.value, 'document.jpg', 0.85)
    // Photo d'origine : le fichier de l'appareil photo tel quel (valeur de preuve)
    const original = props.keepOriginal ? await originalPhoto(props.file) : null
    emit('done', {
      file,
      original,
      meta: {
        scanned: true,
        cropped: !isWhole(),
        coverage: coverage.value,
        mode: mode.value,
        width: result.value.width,
        height: result.value.height,
        original_width: bitmap.width,
        original_height: bitmap.height,
        quality: { ...quality.value, warnings: warnings.value },
        taken_at: new Date(props.file.lastModified || Date.now()).toISOString(),
        overridden,
      },
    })
  } finally {
    busy.value = false
  }
}

async function sendPlain() {
  busy.value = true
  try {
    const file = await shrinkPhoto(props.file, { maxSide: props.maxSide, name: 'photo.jpg' })
    emit('done', { file, original: null, meta: { scanned: false, taken_at: new Date(props.file.lastModified || Date.now()).toISOString() } })
  } finally {
    busy.value = false
  }
}
</script>
