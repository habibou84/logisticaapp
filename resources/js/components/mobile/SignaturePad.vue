<template>
  <div class="space-y-1">
    <canvas
      ref="canvas"
      class="w-full h-40 rounded-xl bg-white ring-1 ring-slate-300 touch-none"
      aria-label="Zone de signature"
      @pointerdown="start"
      @pointermove="draw"
      @pointerup="end"
      @pointercancel="end"
      @pointerleave="end"
    />
    <div class="flex justify-between text-xs text-slate-500">
      <span>{{ empty ? 'Signez avec le doigt dans le cadre' : 'Signature enregistrée' }}</span>
      <button v-if="!empty" type="button" class="text-blue-600" @click="clear">Effacer</button>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * Signature au doigt. Le tracé suit le doigt même si la zone change de taille (téléphone tourné, clavier) ;
 * vide, la zone reprend la résolution de l'écran. « Effacer » renvoie '' (zone gardée ouverte), le parent remet
 * null ou '' pour tout effacer.
 */
const props = defineProps({ modelValue: { type: String, default: null } })
const emit = defineEmits(['update:modelValue'])

const canvas = ref(null)
const empty = ref(true)
let ctx
let drawing = false
let pointerId = null
let observer = null

// Résolution réelle de l'écran pour un trait net (refaite tant que rien n'est signé)
function fit() {
  const c = canvas.value
  if (!c) return
  const ratio = window.devicePixelRatio || 1
  const { width, height } = c.getBoundingClientRect()
  if (!width || !height) return
  const w = Math.round(width * ratio)
  const h = Math.round(height * ratio)
  if (c.width === w && c.height === h) return
  c.width = w
  c.height = h
  ctx = c.getContext('2d')
  ctx.lineWidth = 2.5 * ratio
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.strokeStyle = '#0f172a'
}

// Position du doigt en pixels du dessin, quelle que soit la taille affichée de la zone
function point(e) {
  const r = canvas.value.getBoundingClientRect()
  return [(e.clientX - r.left) * (canvas.value.width / r.width), (e.clientY - r.top) * (canvas.value.height / r.height)]
}

function start(e) {
  if (drawing) return
  if (empty.value) fit()
  if (!ctx) return
  drawing = true
  pointerId = e.pointerId
  canvas.value.setPointerCapture?.(e.pointerId)
  ctx.beginPath()
  ctx.moveTo(...point(e))
}

function draw(e) {
  if (!drawing || e.pointerId !== pointerId) return
  ctx.lineTo(...point(e))
  ctx.stroke()
  empty.value = false
}

function end(e) {
  if (!drawing || (e && e.pointerId !== pointerId)) return
  drawing = false
  pointerId = null
  if (!empty.value) emit('update:modelValue', canvas.value.toDataURL('image/png'))
}

function wipe() {
  ctx?.clearRect(0, 0, canvas.value.width, canvas.value.height)
  empty.value = true
}

function clear() {
  wipe()
  emit('update:modelValue', '')
}

// Remise à zéro par le parent (nouvelle course, envoi fait)
watch(() => props.modelValue, (v) => {
  if (!v && !empty.value && !drawing) wipe()
})

onMounted(() => {
  fit()
  // Zone affichée après coup (feuille qui s'ouvre) ou redimensionnée : résolution ajustée tant qu'elle est vide
  observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(() => { if (empty.value) fit() }) : null
  observer?.observe(canvas.value)
})
onBeforeUnmount(() => observer?.disconnect())
</script>
