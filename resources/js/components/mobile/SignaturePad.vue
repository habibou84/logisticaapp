<template>
  <div class="space-y-1">
    <canvas
      ref="canvas"
      class="w-full h-40 rounded-xl bg-white ring-1 ring-slate-300 touch-none"
      aria-label="Zone de signature"
      @pointerdown="start"
      @pointermove="draw"
      @pointerup="end"
      @pointerleave="end"
    />
    <div class="flex justify-between text-xs text-slate-500">
      <span>{{ empty ? 'Signez avec le doigt dans le cadre' : 'Signature enregistrée' }}</span>
      <button v-if="!empty" type="button" class="text-blue-600" @click="clear">Effacer</button>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'

const emit = defineEmits(['update:modelValue'])
defineProps({ modelValue: { type: String, default: null } })

const canvas = ref(null)
const empty = ref(true)
let ctx
let drawing = false

function point(e) {
  const r = canvas.value.getBoundingClientRect()
  return [e.clientX - r.left, e.clientY - r.top]
}

function start(e) {
  drawing = true
  canvas.value.setPointerCapture?.(e.pointerId)
  ctx.beginPath()
  ctx.moveTo(...point(e))
}

function draw(e) {
  if (!drawing) return
  ctx.lineTo(...point(e))
  ctx.stroke()
  empty.value = false
}

function end() {
  if (!drawing) return
  drawing = false
  if (!empty.value) emit('update:modelValue', canvas.value.toDataURL('image/png'))
}

function clear() {
  ctx.clearRect(0, 0, canvas.value.width, canvas.value.height)
  empty.value = true
  emit('update:modelValue', null)
}

onMounted(() => {
  // Résolution réelle de l'écran pour un trait net
  const ratio = window.devicePixelRatio || 1
  const { width, height } = canvas.value.getBoundingClientRect()
  canvas.value.width = width * ratio
  canvas.value.height = height * ratio
  ctx = canvas.value.getContext('2d')
  ctx.scale(ratio, ratio)
  ctx.lineWidth = 2.5
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.strokeStyle = '#0f172a'
})
</script>
