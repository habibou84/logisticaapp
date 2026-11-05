<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <RouterLink to="/admin/courses" class="text-sm text-blue-600">‹ Courses</RouterLink>
        <h1 class="text-xl font-bold">⏳ Courses non traitées</h1>
        <p class="text-sm text-gray-600">
          Date de livraison dépassée, et pas encore livrées, retournées ni annulées. La distribution n'y arrive qu'après
          l'échéance de son lot, la MAD jamais (Réglages › Courses, « Livraisons du jour »).
        </p>
      </div>
      <p v-if="data" class="rounded-full bg-red-100 px-3 py-1 text-sm font-semibold text-red-800">{{ data.total }} course{{ data.total > 1 ? 's' : '' }}</p>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="Regrouper">
      <span class="text-gray-600">Regrouper :</span>
      <button
        v-for="g in GROUPINGS" :key="g.value" type="button"
        :class="['rounded-full px-3 py-1 ring-1', by === g.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
        :aria-pressed="by === g.value" @click="setBy(g.value)"
      >
        {{ g.label }}
      </button>
      <template v-if="activityChoices.length > 1">
        <span class="ml-2 text-gray-600">Activité :</span>
        <button
          v-for="a in [{ value: '', label: 'Toutes' }, ...activityChoices]" :key="a.value || 'all'" type="button"
          :class="['rounded-full px-3 py-1 ring-1', activity === a.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
          :aria-pressed="activity === a.value" @click="setActivity(a.value)"
        >
          {{ a.label }}
        </button>
      </template>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-else-if="data && !data.total" class="card p-6 text-center text-gray-500">✅ Aucune course non traitée : tout est à jour.</p>

    <div v-else-if="data" class="grid gap-4 lg:grid-cols-[18rem_1fr]">
      <!-- Groupes : date, livreur ou client -->
      <nav class="card divide-y self-start" aria-label="Groupes">
        <button
          type="button"
          :class="['flex w-full items-center justify-between px-3 py-2 text-left text-sm', selectedGroup === null ? 'bg-slate-100 font-semibold' : 'hover:bg-slate-50']"
          @click="select(null)"
        >
          Toutes <span class="rounded-full bg-slate-200 px-2 text-xs">{{ data.total }}</span>
        </button>
        <button
          v-for="g in data.groups" :key="g.key" type="button"
          :class="['flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm', selectedGroup === g.key ? 'bg-slate-100 font-semibold' : 'hover:bg-slate-50']"
          @click="select(g.key)"
        >
          <span class="min-w-0">
            <span class="block truncate">{{ g.label }}</span>
            <span v-if="groupHint(g)" class="block text-xs font-normal text-gray-500">{{ groupHint(g) }}</span>
          </span>
          <span class="shrink-0 rounded-full bg-red-100 px-2 text-xs font-semibold text-red-800">{{ g.count }}</span>
        </button>
      </nav>

      <!-- Courses du groupe choisi -->
      <section class="space-y-2">
        <input v-model="search" class="input" placeholder="Code, téléphone, nom…" aria-label="Rechercher" @input="debounced">

        <!-- Actions groupées : nouvelle date, livreur, annulation -->
        <div v-if="selected.length" class="card flex flex-wrap items-center gap-2 border-blue-200 bg-blue-50 p-3 text-sm">
          <span class="font-medium">{{ selected.length }} sélectionnée(s)</span>
          <input v-model="newDate" type="date" class="input w-auto" :min="todayIso" aria-label="Nouvelle date">
          <button type="button" class="btn-primary" :disabled="busy || !newDate" @click="reschedule">📅 Nouvelle date</button>
          <span class="mx-1 h-6 w-px bg-blue-200" aria-hidden="true" />
          <select v-model="courierId" class="input w-auto" aria-label="Livreur">
            <option :value="null" disabled>Livreur…</option>
            <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.active_assignments_count }} en cours){{ c.is_available ? '' : ' · indisponible' }}{{ madSuffix(c) }}</option>
          </select>
          <button type="button" class="btn-secondary" :disabled="busy || !courierId" @click="assign">🛵 Confier</button>
          <button type="button" class="btn-secondary" :disabled="busy || !returnable.length" @click="returnMany()">↩️ Retour ({{ returnable.length }})</button>
          <button v-if="auth.can('orders.cancel')" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="cancel">Annuler les courses</button>
          <button type="button" class="btn-secondary" @click="selected = []">Désélectionner</button>
        </div>

        <p v-if="loading" class="card p-6 text-center text-gray-500">Chargement…</p>
        <p v-else-if="!orders.length" class="card p-6 text-center text-gray-500">Aucune course.</p>
        <div v-else class="card divide-y">
          <label class="flex items-center gap-2 px-3 py-2 text-xs text-gray-500">
            <input type="checkbox" :checked="allSelected" aria-label="Tout sélectionner" @change="toggleAll"> Tout sélectionner
          </label>
          <div v-for="o in orders" :key="o.id" class="flex flex-wrap items-start gap-x-3 gap-y-2 px-3 py-2 text-sm hover:bg-slate-50">
            <input v-model="selected" type="checkbox" :value="o.id" class="mt-1" :aria-label="`Sélectionner ${o.tracking_code}`">
            <RouterLink :to="`/admin/courses/${o.id}`" class="min-w-[12rem] flex-1">
              <span class="font-mono text-xs font-semibold text-blue-700">{{ o.tracking_code }}</span>
              <span v-if="activityChoices.length > 1" :class="['ml-1 rounded-full px-1.5 text-[11px] font-medium', activityBadge(o.activity)]">{{ activityShort(o.activity) }}</span>
              <span class="ml-1">{{ o.merchant?.business_name }} → {{ o.recipient.name || o.recipient.phone }}</span>
              <span class="block text-xs text-gray-500">
                {{ o.delivery.zone_name || o.delivery.address }} ·
                <span v-if="o.billed_monthly" class="text-indigo-700">facturé au mois</span><template v-else>{{ money(o.amounts.cod_amount) }}</template>
              </span>
            </RouterLink>
            <div class="w-36">
              <StatusBadge :status="o.status" :label="o.status_label" />
              <span class="block text-xs text-red-700">prévue le {{ date(dayOf(o)) }}</span>
            </div>
            <CourierCell :order="o" :couriers="couriers" can-dispatch class="w-48" @updated="replace" />
            <!-- Actions à portée de main, selon l'état de la course -->
            <div class="flex flex-wrap items-center gap-1">
              <button v-if="o.needs_decision || o.status === 'delivery_failed'" type="button" class="btn-primary !px-2 !py-1 text-xs" @click="deciding = o">Décider</button>
              <template v-if="canReschedule(o)">
                <button type="button" class="btn-secondary !px-2 !py-1 text-xs" :disabled="busy" title="Livrer aujourd'hui" @click="rescheduleOne(o, todayIso)">Aujourd'hui</button>
                <button type="button" class="btn-secondary !px-2 !py-1 text-xs" :disabled="busy" title="Livrer demain" @click="rescheduleOne(o, tomorrowIso)">Demain</button>
              </template>
              <button v-if="canReturn(o)" type="button" class="btn-secondary !px-2 !py-1 text-xs" :disabled="busy" @click="returnMany([o])">↩️ Retour</button>
              <a v-if="o.recipient.phone" :href="`tel:${o.recipient.phone}`" class="btn-secondary !px-2 !py-1 text-xs" :title="`Appeler ${o.recipient.name || 'le client'}`">📞</a>
            </div>
          </div>
        </div>
        <Pagination :meta="meta" @change="load" />
      </section>
    </div>

    <OrderDecision :open="!!deciding" :order="deciding" :couriers="couriers" @close="deciding = null" @decided="deciding = null; refresh()" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Pagination from '../../components/Pagination.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import CourierCell from '../../components/CourierCell.vue'
import OrderDecision from '../../components/OrderDecision.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { useDispatchCountStore } from '../../stores/dispatchCounts'
import { orderChanges } from '../../composables/useRealtime'
import { date, money } from '../../utils/format'
import { madSuffix } from '../../utils/mad'
import { activityBadge, activityShort, myActivities } from '../../utils/activities'

/**
 * Courses non traitées (repris de Jibiat) : jour de livraison dépassé, course pas terminée et plus dans la règle de son
 * activité. Regroupées par date prévue, par livreur ou par client ; sélection pour une nouvelle date, un livreur ou
 * l'annulation (le livreur ne peut plus faire ces courses tant que l'agence ne les a pas réglées).
 */
const GROUPINGS = [
  { value: 'date', label: '📅 Par date' },
  { value: 'courier', label: '🛵 Par livreur' },
  { value: 'merchant', label: '🏪 Par client' },
]
const auth = useAuthStore()
const toasts = useToastStore()
const dispatchCounts = useDispatchCountStore()
const route = useRoute()
const router = useRouter()
const activityChoices = computed(() => myActivities(auth.user))
const by = ref(GROUPINGS.some((g) => g.value === route.query.by) ? route.query.by : 'date')
const activity = ref(activityChoices.value.some((a) => a.value === route.query.activity) ? route.query.activity : '')
const selectedGroup = ref(null)
const data = ref(null)
const orders = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref('')
const search = ref('')
const selected = ref([])
const couriers = ref([])
const courierId = ref(null)
const busy = ref(false)
const todayIso = new Date().toLocaleDateString('sv-SE')
const newDate = ref(todayIso)
const tomorrowIso = new Date(Date.now() + 86400000).toLocaleDateString('sv-SE')
const deciding = ref(null)
const allSelected = computed(() => orders.value.length > 0 && selected.value.length === orders.value.length)

const groupHint = (g) => [g.amount ? `${money(g.amount)} à encaisser` : '', g.oldest && by.value !== 'date' ? `depuis le ${date(g.oldest)}` : ''].filter(Boolean).join(' · ')
const dayOf = (o) => (o.delivery.scheduled_date || o.created_at || '').slice(0, 10)

async function loadGroups() {
  try {
    data.value = (await http.get('/orders/untreated-groups', { params: { by: by.value, activity: activity.value || undefined } })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function filtersFor(key) {
  if (key === null) return {}
  if (by.value === 'date') return { from: key, to: key, date_basis: 'delivery' }
  if (by.value === 'courier') return key === 'none' ? { without_courier: 1 } : { lead_courier_id: key }
  return { merchant_id: key }
}

async function load(page = 1) {
  loading.value = orders.value.length === 0
  try {
    const params = { queue: 'untreated', page, per_page: 50, search: search.value || undefined, activity: activity.value || undefined, ...filtersFor(selectedGroup.value) }
    const res = (await http.get('/orders', { params })).data
    orders.value = res.data
    meta.value = res.meta
    selected.value = selected.value.filter((id) => orders.value.some((o) => o.id === id))
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

let timer = null
function debounced() {
  clearTimeout(timer)
  timer = setTimeout(() => load(1), 300)
}

function select(key) {
  selectedGroup.value = key
  selected.value = []
  load(1)
}

function syncQuery() {
  router.replace({ query: { by: by.value, ...(activity.value ? { activity: activity.value } : {}) } })
}

function setBy(value) {
  by.value = value
  selectedGroup.value = null
  syncQuery()
  refresh()
}

function setActivity(value) {
  activity.value = value
  selectedGroup.value = null
  syncQuery()
  refresh()
}

function toggleAll(e) {
  selected.value = e.target.checked ? orders.value.map((o) => o.id) : []
}

function refresh() {
  loadGroups()
  load(meta.value?.current_page || 1)
  dispatchCounts.fetchCounts().catch(() => {})
}

// Résultat d'une action groupée : réussites, puis chaque refus avec le code de la course
function report(done, errors, what) {
  if (done.length) toasts.success(`${done.length} course(s) ${what}.`)
  Object.entries(errors).forEach(([id, message]) => {
    const code = orders.value.find((o) => o.id === Number(id))?.tracking_code || id
    toasts.error(`${code} : ${message}`)
  })
  selected.value = []
  refresh()
}

async function run(action) {
  busy.value = true
  try {
    await action()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

const reschedule = () => run(async () => {
  const { data: res } = await http.post('/orders/bulk-reschedule', { order_ids: selected.value, date: newDate.value })
  report(res.data.rescheduled, res.data.errors, `prévue(s) le ${date(newDate.value)}`)
})

// Livreur : ramassage pour les courses pas encore ramassées, livraison pour les autres
const assign = () => run(async () => {
  const groups = selected.value.reduce((acc, id) => {
    const o = orders.value.find((x) => x.id === id)
    const stage = o?.awaiting_courier?.stage || (['pending', 'confirmed', 'pickup_assigned', 'pickup_in_progress'].includes(o?.status) ? 'pickup' : 'delivery')
    ;(acc[stage] ||= []).push(id)
    return acc
  }, {})
  const done = []
  const errors = {}
  for (const [type, ids] of Object.entries(groups)) {
    const { data: res } = await http.post('/orders/bulk-assign', { order_ids: ids, type, courier_id: courierId.value })
    done.push(...res.data.assigned)
    Object.assign(errors, res.data.errors)
  }
  report(done, errors, 'confiée(s) au livreur')
})

const cancel = () => run(async () => {
  const reason = window.prompt(`Annuler ${selected.value.length} course(s) ? Les clients seront prévenus.\nMotif :`, 'Course non traitée')
  if (reason === null) return
  const { data: res } = await http.post('/orders/bulk-cancel', { order_ids: selected.value, reason: reason.trim() || undefined })
  report(res.data.cancelled, res.data.errors, 'annulée(s)')
})

// ── Actions sur une course ──
const PICKED = ['picked_up', 'at_hub', 'rescheduled', 'delivery_assigned', 'delivery_failed']
const FINAL = ['delivered', 'cancelled', 'rejected', 'returned', 'lost']
const canReschedule = (o) => !o.return_requested && !FINAL.includes(o.status) && o.status !== 'delivery_failed'
const canReturn = (o) => PICKED.includes(o.status) && !o.return_requested
const returnable = computed(() => orders.value.filter((o) => selected.value.includes(o.id) && canReturn(o)))

function replace(updated) {
  const i = orders.value.findIndex((o) => o.id === updated.id)
  if (i !== -1) orders.value[i] = { ...orders.value[i], ...updated }
  dispatchCounts.fetchCounts().catch(() => {})
}

const rescheduleOne = (o, day) => run(async () => {
  const { data: res } = await http.post('/orders/bulk-reschedule', { order_ids: [o.id], date: day })
  report(res.data.rescheduled, res.data.errors, day === todayIso ? 'prévue(s) aujourd\'hui' : `prévue(s) le ${date(day)}`)
})

function returnMany(list = returnable.value) {
  if (!list.length || !window.confirm(`Faire revenir ${list.length > 1 ? `ces ${list.length} colis` : 'ce colis'} chez le client ?`)) return
  return run(async () => {
    const done = []
    const errors = {}
    for (const o of list) {
      try {
        await http.post(`/orders/${o.id}/return-request`, { note: 'Course non traitée : retour décidé par l\'agence' })
        done.push(o.id)
      } catch (e) {
        errors[o.id] = apiErrorMessage(e)
      }
    }
    report(done, errors, 'en retour')
  })
}

watch(orderChanges, refresh)

onMounted(async () => {
  loadGroups()
  load(1)
  couriers.value = (await http.get('/couriers').catch(() => ({ data: { data: [] } }))).data.data
})
</script>
