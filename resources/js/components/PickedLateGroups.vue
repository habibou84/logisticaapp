<template>
  <!-- Courses en retard › À décider : colis ramassés dont la date est passée, regroupés par client -->
  <div class="space-y-3">
    <div class="card flex flex-wrap items-center gap-3 border-red-200 bg-red-50 p-3 text-sm text-red-900">
      <p class="min-w-[16rem] flex-1"><b>Ces colis sont chez un livreur ou au dépôt.</b> Renvoyez-les au client ou choisissez une nouvelle date. Regroupés par client.</p>
      <input v-model="search" type="search" class="input w-full sm:w-64" placeholder="Chercher un client" aria-label="Chercher un client">
    </div>

    <p v-if="!loaded" class="card p-6 text-center text-slate-600">Chargement…</p>
    <p v-else-if="!groups.length" class="card flex items-center justify-center gap-2 p-6 text-emerald-700">✅ {{ orders.length ? 'Aucun client ne correspond.' : 'Aucun colis ramassé en retard : tout est décidé.' }}</p>

    <section v-for="g in groups" :key="g.id" class="card overflow-hidden" :aria-label="g.name">
      <div class="flex flex-wrap items-center gap-3 bg-slate-50 p-4" :class="opened[g.id] ? 'border-b' : ''">
        <button type="button" class="min-w-[14rem] flex-1 text-left" :aria-expanded="!!opened[g.id]" @click="opened[g.id] = !opened[g.id]">
          <span class="block text-lg font-bold">{{ g.name }}</span>
          <span class="block text-sm text-slate-600">
            {{ g.phone }}<template v-if="g.phone"> · </template><b>{{ g.orders.length }} colis</b> · le plus ancien prévu le {{ shortDay(g.oldest) }} ({{ daysAgo(g.oldest) }})
          </span>
        </button>
        <button type="button" class="btn-primary" :disabled="!checkedOf(g).length" @click="decide('return', checkedOf(g))">↩️ Tout renvoyer ({{ checkedOf(g).length }})</button>
        <button type="button" class="btn-secondary" :disabled="!checkedOf(g).length" @click="decide('reschedule', checkedOf(g))">📅 Tout reporter ({{ checkedOf(g).length }})</button>
        <button type="button" class="text-sm font-semibold text-blue-700" @click="opened[g.id] = !opened[g.id]">{{ opened[g.id] ? 'Masquer' : 'Voir les colis' }}</button>
      </div>

      <ul v-if="opened[g.id]" class="divide-y">
        <li v-for="o in g.orders" :key="o.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm">
          <input type="checkbox" :checked="!unchecked[o.id]" :aria-label="`Inclure ${o.tracking_code}`" @change="unchecked[o.id] = !$event.target.checked">
          <span class="min-w-[12rem] flex-1">
            <RouterLink :to="`/admin/courses/${o.id}`" class="font-mono font-semibold text-blue-700 hover:underline">{{ o.tracking_code }}</RouterLink>
            <span v-if="many" :class="['ml-1 rounded-full px-1.5 text-[11px] font-medium', activityBadge(o.activity)]">{{ activityShort(o.activity) }}</span>
            <span class="ml-2 text-slate-600">{{ o.recipient?.name || o.recipient?.phone }}<template v-if="o.delivery?.zone_name"> · {{ o.delivery.zone_name }}</template></span>
          </span>
          <span :class="['min-w-[8rem] inline-flex items-center gap-1', o.held_by ? 'text-amber-800' : 'text-emerald-700']">
            {{ o.held_by ? '🛵' : '🏬' }} {{ o.held_by ? `chez ${o.held_by.name}` : 'au dépôt' }}
          </span>
          <span class="min-w-[8rem] font-semibold text-red-700">prévue le {{ shortDay(dayOf(o)) }}</span>
          <span class="min-w-[10rem] text-slate-600">{{ o.last_incident?.label || o.status_label }}</span>
          <span class="ml-auto flex gap-1">
            <button type="button" class="btn-primary !px-2 !py-1 text-xs" @click="decide('return', [o])">↩️ Retour</button>
            <button type="button" class="btn-secondary !px-2 !py-1 text-xs" @click="decide('reschedule', [o])">📅 Reporter</button>
          </span>
        </li>
      </ul>
    </section>

    <PickedLateDialog :orders="dialog.orders" :mode="dialog.mode" :couriers="couriers" @close="dialog.orders = []" @done="decided" />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http from '../bootstrap/axios'
import { useDispatchCountStore } from '../stores/dispatchCounts'
import { useAuthStore } from '../stores/auth'
import { activityBadge, activityShort, myActivities } from '../utils/activities'
import PickedLateDialog from './PickedLateDialog.vue'

const counts = useDispatchCountStore()
// Pastille d'activité sur chaque colis quand le compte en voit plusieurs
const many = myActivities(useAuthStore().user).length > 1
const orders = ref([])
const couriers = ref([])
const loaded = ref(false)
const search = ref('')
// Groupes dépliés (le premier l'est d'office) et colis décochés (exclus de « Tout renvoyer / reporter »)
const opened = reactive({})
const unchecked = reactive({})
const dialog = reactive({ mode: 'return', orders: [] })

const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const dayOf = (o) => o.delivery?.scheduled_date || (o.created_at || '').slice(0, 10)
const shortDay = (d) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) : '')
function daysAgo(d) {
  const n = Math.round((Date.now() - new Date(`${d}T12:00:00`).getTime()) / 86400000)
  return n <= 1 ? 'hier' : `il y a ${n} jours`
}

// Par client, le plus ancien colis d'abord
const groups = computed(() => {
  const map = new Map()
  for (const o of orders.value) {
    const id = o.merchant?.id ?? o.merchant_id ?? 0
    if (!map.has(id)) map.set(id, { id, name: o.merchant?.business_name || 'Client', phone: o.merchant?.phone, orders: [] })
    map.get(id).orders.push(o)
  }
  return [...map.values()]
    .map((g) => ({ ...g, orders: g.orders.sort((a, b) => dayOf(a).localeCompare(dayOf(b))), oldest: g.orders.map(dayOf).sort()[0] }))
    .filter((g) => !search.value || norm(g.name).includes(norm(search.value)))
    .sort((a, b) => a.oldest.localeCompare(b.oldest))
})
const checkedOf = (g) => g.orders.filter((o) => !unchecked[o.id])

function decide(mode, list) {
  dialog.mode = mode
  dialog.orders = list
}

async function load() {
  const { data } = await http.get('/orders', { params: { queue: 'picked_late', per_page: 200 } })
  orders.value = data.data
  loaded.value = true
  const first = groups.value[0]
  if (first && Object.keys(opened).length === 0) opened[first.id] = true
}

function decided() {
  load()
  counts.fetchCounts().catch(() => {})
}

onMounted(async () => {
  load()
  couriers.value = (await http.get('/couriers').catch(() => ({ data: { data: [] } }))).data.data
})
</script>
