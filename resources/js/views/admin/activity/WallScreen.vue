<template>
  <!-- Écran mural : télévision au dépôt, gros caractères, sans montant d'argent -->
  <div class="fixed inset-0 z-[60] overflow-y-auto bg-slate-900 p-6 lg:p-10 text-slate-50">
    <div class="flex flex-wrap items-baseline gap-4">
      <img :src="branding.logo_url || DEFAULT_LOGO" alt="" class="h-12 lg:h-16 rounded-xl bg-white px-3 py-1.5 object-contain">
      <h1 class="flex-1 text-2xl lg:text-4xl font-bold first-letter:uppercase">{{ dateLabel }}</h1>
      <span class="text-2xl lg:text-4xl font-bold tabular-nums">{{ clock }}</span>
    </div>
    <div class="mt-3 flex gap-2">
      <button type="button" class="rounded-lg border border-slate-600 px-3 py-2 text-sm hover:bg-slate-800" @click="fullscreen">Plein écran</button>
      <RouterLink to="/admin/activite" class="rounded-lg border border-slate-600 px-3 py-2 text-sm hover:bg-slate-800">Quitter</RouterLink>
    </div>

    <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="rounded-2xl bg-slate-800 p-5"><p class="text-lg text-slate-400">Livrés</p><p class="text-4xl lg:text-5xl font-extrabold text-emerald-400">{{ totals.delivered ?? '—' }} / {{ totals.deliveries ?? '—' }}</p></div>
      <div class="rounded-2xl bg-slate-800 p-5"><p class="text-lg text-slate-400">Avancement</p><p class="text-4xl lg:text-5xl font-extrabold">{{ totals.progress ?? 0 }} %</p></div>
      <div class="rounded-2xl bg-slate-800 p-5"><p class="text-lg text-slate-400">Échecs</p><p class="text-4xl lg:text-5xl font-extrabold text-red-400">{{ totals.failed ?? 0 }}</p></div>
      <div class="rounded-2xl bg-slate-800 p-5"><p class="text-lg text-slate-400">Fin estimée</p><p class="text-4xl lg:text-5xl font-extrabold">{{ totals.estimated_end ? time(totals.estimated_end) : '—' }}</p></div>
    </div>

    <div class="mt-8 space-y-4">
      <div v-for="c in shown" :key="c.id" class="flex items-center gap-4 text-xl lg:text-2xl">
        <span :class="['w-40 lg:w-72 shrink-0 truncate font-bold', c.flags.length ? 'text-orange-400' : c.state === 'offline' ? 'text-slate-400' : '']">{{ c.name }}<template v-if="c.flags.length"> ⚠</template></span>
        <!-- En MAD : chez quel client, sans barre d'avancement -->
        <span v-if="c.mad && !c.deliveries.total" class="flex-1 truncate rounded-full bg-violet-900/60 px-4 py-1 text-lg text-violet-200">En MAD chez {{ c.mad.merchant }}<template v-if="c.mad.from"> · {{ c.mad.from }}–{{ c.mad.to }}</template></span>
        <div v-else class="h-4 flex-1 rounded-full bg-slate-700">
          <div :class="['h-4 rounded-full', c.flags.length ? 'bg-orange-400' : c.state === 'offline' ? 'bg-slate-500' : c.mad ? 'bg-violet-400' : 'bg-emerald-400']" :style="{ width: `${barWidth(c)}%` }" />
        </div>
        <span class="w-28 lg:w-44 shrink-0 text-right tabular-nums">
          <template v-if="c.deliveries.total">{{ c.deliveries.done }} / {{ c.deliveries.total }}<template v-if="c.deliveries.done === c.deliveries.total"> ✓</template></template>
          <span v-if="c.deliveries.units_total > c.deliveries.total" class="block text-base text-slate-400">{{ c.deliveries.units_done }} / {{ c.deliveries.units_total }} plis</span>
        </span>
      </div>
      <p v-if="!shown.length" class="text-xl text-slate-400">Aucune livraison prévue aujourd'hui.</p>
    </div>
    <p class="mt-8 text-sm text-slate-500">Se met à jour tout seul.</p>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import http from '../../../bootstrap/axios'
import { orderChanges } from '../../../composables/useRealtime'
import { DEFAULT_LOGO, branding, loadBranding } from '../../../composables/useBranding'

const couriers = ref([])
const totals = ref({})
const now = ref(new Date())
let timer = null
let tick = null

const time = (at) => new Date(at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', ' h ')
const clock = computed(() => now.value.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }))
const dateLabel = computed(() => now.value.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }))
// Livreurs avec des livraisons aujourd'hui, ou en MAD chez un client, à surveiller d'abord
const shown = computed(() => couriers.value.filter((c) => c.deliveries.total > 0 || c.mad))
const barWidth = (c) => (c.deliveries.total ? Math.round((100 * c.deliveries.done) / c.deliveries.total) : 0)

async function load() {
  const { data } = await http.get('/couriers/activity')
  couriers.value = data.data
  totals.value = data.totals
}
function fullscreen() {
  document.documentElement.requestFullscreen?.().catch(() => {})
}

watch(orderChanges, () => load().catch(() => {}))
onMounted(() => {
  loadBranding()
  load()
  timer = setInterval(() => load().catch(() => {}), 30000)
  tick = setInterval(() => { now.value = new Date() }, 15000)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  clearInterval(tick)
})
</script>
