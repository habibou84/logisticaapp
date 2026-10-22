<template>
  <div class="space-y-4 max-w-6xl">
    <div>
      <h1 class="text-xl font-bold">Colis chez les livreurs</h1>
      <p class="text-sm text-gray-600">
        Colis ramassés, en livraison, en échec ou reportés que les livreurs n'ont pas encore rendus au dépôt.
        <template v-if="alertHours">Au-delà de {{ alertHours }} h, le colis est signalé en rouge et le dispatch est alerté.</template>
      </p>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
      <StatCard title="Colis chez les livreurs" :value="store.counts.total" icon="📦" />
      <StatCard :title="alertHours ? `Depuis plus de ${alertHours} h` : 'En retard'" :value="store.counts.overdue" icon="⏰" />
      <StatCard title="Livreurs concernés" :value="couriers.length" icon="🛵" />
    </div>

    <div class="flex flex-wrap gap-2 items-center">
      <button v-for="f in filters" :key="f.value" :class="['btn whitespace-nowrap', overdueOnly === f.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']" @click="overdueOnly = f.value; load()">
        {{ f.label }}
      </button>
      <input v-model="search" type="search" class="input sm:w-64 ml-auto" placeholder="Livreur, code, client…" aria-label="Rechercher">
    </div>

    <section v-for="c in visible" :key="c.courier_id" class="card">
      <header class="flex flex-wrap items-center justify-between gap-2 p-4 border-b">
        <div>
          <p class="font-semibold">
            🛵 {{ c.name }}
            <span :class="['ml-1 rounded-full px-2 py-0.5 text-xs', c.is_available ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600']">{{ c.is_available ? 'en service' : 'hors service' }}</span>
          </p>
          <p class="text-sm text-gray-600">
            <a v-if="c.phone" :href="telLink(c.phone)" class="text-blue-600">{{ c.phone }}</a>
            · {{ c.count }} colis<span v-if="c.overdue" class="text-red-600 font-medium"> dont {{ c.overdue }} en retard</span>
          </p>
        </div>
        <div v-if="canReceive" class="flex gap-2">
          <button type="button" class="btn-secondary text-xs" @click="toggleAll(c)">{{ allSelected(c) ? 'Tout décocher' : 'Tout cocher' }}</button>
          <button type="button" class="btn-success" :disabled="!selectedOf(c).length" @click="receive(c)">✓ Rendus au dépôt ({{ selectedOf(c).length }})</button>
        </div>
      </header>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th v-if="canReceive" class="p-2 w-8" /><th class="p-2">Colis</th><th class="p-2">Destinataire</th><th class="p-2">Situation</th><th class="p-2 text-right">À encaisser</th><th class="p-2">Chez le livreur</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="p in c.parcels" :key="p.id" :class="isOverdue(p) ? 'bg-red-50' : ''">
              <td v-if="canReceive" class="p-2">
                <input v-if="!p.on_the_road" v-model="selected" type="checkbox" :value="p.id" :aria-label="`Colis ${p.tracking_code} rendu`">
              </td>
              <td class="p-2">
                <RouterLink :to="`/admin/courses/${p.id}`" class="font-mono text-blue-700">{{ p.tracking_code }}</RouterLink>
                <div class="text-xs text-gray-500">{{ p.merchant }}</div>
              </td>
              <td class="p-2">{{ p.recipient_name || 'Client' }} <a :href="telLink(p.recipient_phone)" class="text-blue-600 text-xs">{{ p.recipient_phone }}</a><div class="text-xs text-gray-500">{{ p.zone }}</div></td>
              <td class="p-2">
                <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', p.on_the_road ? 'bg-sky-100 text-sky-800' : 'bg-amber-100 text-amber-900']">{{ p.status_label }}</span>
                <div v-if="p.incident" class="text-xs text-gray-600 mt-0.5">{{ p.incident }}</div>
                <div v-if="p.rescheduled_to" class="text-xs text-gray-600">📅 {{ date(p.rescheduled_to) }}</div>
                <div v-if="p.return_requested" class="text-xs text-rose-700 font-medium">↩️ Retour au marchand à organiser</div>
                <div v-if="p.kept_for" class="text-xs text-indigo-700 font-medium">🌙 Gardé pour sa livraison du {{ date(p.kept_for) }}</div>
                <div v-else-if="p.custody_plan === 'depot' && p.status === 'picked_up'" class="text-xs text-slate-700 font-medium">🏢 À rapporter au dépôt (mission ramassage)</div>
              </td>
              <td class="p-2 text-right">{{ p.cod_amount ? money(p.cod_amount) : '—' }}</td>
              <td :class="['p-2 whitespace-nowrap', isOverdue(p) ? 'text-red-700 font-semibold' : 'text-gray-600']">
                {{ heldFor(p.held_since) }}
                <div class="text-xs font-normal text-gray-500">{{ dateTime(p.held_since) }}</div>
                <RouterLink v-if="maybeLost(p)" :to="`/admin/courses/${p.id}`" class="text-xs font-semibold text-red-700 underline">🚨 Peut-être perdu : vérifier</RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div v-if="loaded && !visible.length" class="card p-8 text-center text-gray-500">
      {{ overdueOnly ? '🎉 Aucun colis en retard.' : '🎉 Aucun colis chez les livreurs : tout est livré ou au dépôt.' }}
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import StatCard from '../../components/StatCard.vue'
import { orderChanges } from '../../composables/useRealtime'
import { useAuthStore } from '../../stores/auth'
import { useHeldParcelStore } from '../../stores/heldParcels'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime, money, telLink } from '../../utils/format'

const auth = useAuthStore()
const store = useHeldParcelStore()
const toasts = useToastStore()
const couriers = ref([])
const alertHours = ref(24)
const loaded = ref(false)
const overdueOnly = ref(false)
const search = ref('')
const selected = ref([])
const filters = [{ value: false, label: 'Tous les colis' }, { value: true, label: '⏰ En retard' }]
const canReceive = computed(() => auth.can('orders.dispatch') || auth.can('finance.manage'))

const visible = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return couriers.value
  return couriers.value
    .map((c) => (c.name || '').toLowerCase().includes(q) ? c : { ...c, parcels: c.parcels.filter((p) => [p.tracking_code, p.recipient_name, p.recipient_phone, p.merchant].some((v) => (v || '').toLowerCase().includes(q))) })
    .filter((c) => c.parcels.length)
})

// Au-delà de trois fois le délai : à retrouver ou à déclarer perdu (fiche de la course)
const maybeLost = (p) => !p.kept_for && alertHours.value > 0 && Date.now() - new Date(p.held_since).getTime() >= 3 * alertHours.value * 3600000
// Gardé pour sa livraison à venir (mission ramassage) : pas en retard
const isOverdue = (p) => !p.kept_for && alertHours.value > 0 && Date.now() - new Date(p.held_since).getTime() >= alertHours.value * 3600000
function heldFor(since) {
  const hours = Math.floor((Date.now() - new Date(since).getTime()) / 3600000)
  if (hours < 1) return "moins d'1 h"
  return hours >= 24 ? `${Math.floor(hours / 24)} j ${hours % 24} h` : `${hours} h`
}

const receivable = (c) => c.parcels.filter((p) => !p.on_the_road).map((p) => p.id)
const selectedOf = (c) => receivable(c).filter((id) => selected.value.includes(id))
const allSelected = (c) => receivable(c).length > 0 && selectedOf(c).length === receivable(c).length
function toggleAll(c) {
  const ids = receivable(c)
  selected.value = allSelected(c) ? selected.value.filter((id) => !ids.includes(id)) : [...new Set([...selected.value, ...ids])]
}

async function load() {
  const { data } = await http.get('/parcels/held', { params: overdueOnly.value ? { overdue: 1 } : {} })
  couriers.value = data.data
  alertHours.value = data.meta.alert_hours
  store.setCounts(data.meta)
  const ids = new Set(data.data.flatMap((c) => c.parcels.map((p) => p.id)))
  selected.value = selected.value.filter((id) => ids.has(id))
  loaded.value = true
}

async function receive(c) {
  const ids = selectedOf(c)
  try {
    await http.post(`/couriers/${c.courier_id}/parcels/receive`, { order_ids: ids })
    toasts.success(`${ids.length} colis de ${c.name} reçu(s) au dépôt.`)
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

// Changements de courses et alertes en direct
let timer
watch([orderChanges, () => store.version], () => {
  clearTimeout(timer)
  timer = setTimeout(load, 500)
})

onMounted(load)
</script>
