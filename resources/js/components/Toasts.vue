<template>
  <!-- Sous l'en-tête (le menu et la cloche restent touchables) ; seules les alertes captent le doigt -->
  <div class="toasts pointer-events-none fixed right-3 left-3 sm:left-auto z-[60] flex flex-col gap-2 sm:w-96" aria-live="polite">
    <TransitionGroup name="toast">
      <div
        v-for="toast in visible"
        :key="toast.id"
        :class="['pointer-events-auto relative flex items-start gap-2.5 rounded-xl border shadow-lg pl-3 pr-1 py-2 text-sm select-none touch-pan-y', classes[toast.type]]"
        :style="drag.id === toast.id ? { transform: `translate(${drag.dx}px, ${Math.min(0, drag.dy)}px)`, opacity: dragOpacity, transition: 'none' } : null"
        role="status"
        @mouseenter="toasts.pause(toast.id)"
        @mouseleave="toasts.resume(toast.id)"
        @touchstart.passive="start(toast, $event)"
        @touchmove.passive="move($event)"
        @touchend="end(toast)"
        @touchcancel="end(toast)"
      >
        <span :class="['mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white', dots[toast.type]]" aria-hidden="true">{{ icons[toast.type] }}</span>
        <button type="button" class="min-w-0 flex-1 text-left" @click="open(toast)">
          <p v-if="toast.title" class="flex items-center gap-1.5 font-semibold leading-snug">
            <span class="truncate">{{ toast.title }}</span>
            <span v-if="toast.count > 1" class="shrink-0 rounded-full bg-black/10 px-1.5 text-[11px] font-bold">×{{ toast.count }}</span>
          </p>
          <p class="line-clamp-2 leading-snug opacity-90">{{ toast.message }}</p>
        </button>
        <button type="button" class="-my-1 grid h-9 w-9 shrink-0 place-items-center rounded-full text-lg leading-none opacity-60 hover:bg-black/5 hover:opacity-100" aria-label="Fermer l'alerte" @click.stop="toasts.dismiss(toast.id)">×</button>
      </div>
    </TransitionGroup>

    <div v-if="hidden || toasts.items.length > 1" class="pointer-events-auto flex items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white/95 px-3 py-1.5 text-xs text-slate-700 shadow">
      <button v-if="hidden" type="button" class="font-semibold text-blue-700" @click="showAll">+ {{ hidden }} autre{{ hidden > 1 ? 's' : '' }} alerte{{ hidden > 1 ? 's' : '' }}</button>
      <span v-else />
      <button type="button" class="font-medium text-slate-500 hover:text-slate-800" @click="toasts.clear()">Tout fermer</button>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useToastStore } from '../stores/toasts'
import { useAuthStore } from '../stores/auth'

const toasts = useToastStore()
const auth = useAuthStore()
const router = useRouter()

const classes = {
  info: 'bg-white border-slate-200 text-slate-800',
  success: 'bg-emerald-50 border-emerald-200 text-emerald-900',
  error: 'bg-red-50 border-red-200 text-red-900',
}
const dots = { info: 'bg-blue-500', success: 'bg-emerald-500', error: 'bg-red-500' }
const icons = { info: 'i', success: '✓', error: '!' }

// Téléphone : 2 alertes visibles au plus ; ordinateur : 4
const phone = ref(false)
let media
const syncMedia = () => { phone.value = !!media?.matches }
onMounted(() => {
  media = window.matchMedia?.('(max-width: 639px)')
  syncMedia()
  media?.addEventListener?.('change', syncMedia)
})
onBeforeUnmount(() => media?.removeEventListener?.('change', syncMedia))

const max = computed(() => (phone.value ? 2 : 4))
const visible = computed(() => toasts.items.slice(0, max.value))
const hidden = computed(() => Math.max(0, toasts.items.length - max.value))

function open(toast) {
  if (drag.moved) return
  toasts.dismiss(toast.id)
  if (toast.href) router.push(toast.href)
  else if (toast.to) router.push(`${auth.homeRoute}/courses/${toast.to}`)
}

// Le reste se lit dans la cloche (ou la page Notifications des applications mobiles)
function showAll() {
  toasts.clear()
  if (toasts.bellMounted) toasts.openBell()
  else router.push(`${auth.homeRoute}/notifications`)
}

// Glisser vers la droite, la gauche ou le haut pour fermer
const drag = reactive({ id: null, x: 0, y: 0, dx: 0, dy: 0, moved: false })
const dragOpacity = computed(() => Math.max(0.2, 1 - Math.max(Math.abs(drag.dx), -Math.min(0, drag.dy)) / 160))

function start(toast, e) {
  const t = e.touches[0]
  Object.assign(drag, { id: toast.id, x: t.clientX, y: t.clientY, dx: 0, dy: 0, moved: false })
  toasts.pause(toast.id)
}
function move(e) {
  if (drag.id === null) return
  const t = e.touches[0]
  drag.dx = t.clientX - drag.x
  drag.dy = t.clientY - drag.y
  if (Math.abs(drag.dx) > 8 || drag.dy < -8) drag.moved = true
}
function end(toast) {
  if (drag.id !== toast.id) return
  const gone = Math.abs(drag.dx) > 80 || drag.dy < -40
  const moved = drag.moved
  drag.id = null
  drag.dx = drag.dy = 0
  if (gone) toasts.dismiss(toast.id)
  else toasts.resume(toast.id)
  // Le « clic » qui suit un glissement ne doit pas ouvrir la page
  if (moved) setTimeout(() => { drag.moved = false }, 50)
}
</script>

<style scoped>
.toasts {
  /* Sous l'en-tête des applications (56 px) et la zone de l'encoche */
  top: calc(env(safe-area-inset-top, 0px) + 4rem);
}
.toast-enter-active, .toast-leave-active { transition: all 0.2s ease; }
.toast-enter-from { opacity: 0; transform: translateY(-8px); }
.toast-leave-to { opacity: 0; transform: translateX(40px); }
.toast-leave-active { position: absolute; left: 0; right: 0; }
</style>
