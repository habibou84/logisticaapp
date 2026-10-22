<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Courses</h1>
      <div class="flex gap-2">
        <RouterLink v-if="isStaff && auth.can('settings.manage')" to="/admin/reglages/courses" class="btn-secondary" title="Réglages des courses" aria-label="Réglages des courses">⚙️</RouterLink>
        <RouterLink v-if="auth.can('orders.create')" :to="`${auth.homeRoute}/courses/import`" class="btn-secondary">📥 Importer</RouterLink>
        <RouterLink v-if="auth.can('orders.create')" :to="`${auth.homeRoute}/courses/nouvelle`" :class="['btn-primary', isStaff ? 'hidden md:inline-flex' : '']">+ Nouvelle course</RouterLink>
      </div>
    </div>

    <DeliveryDayBar v-if="isStaff && auth.can('orders.dispatch')" @changed="load(1); dispatchCounts.fetchCounts().catch(() => {})" />

    <!-- Jour affiché : aujourd'hui par défaut (retards compris), gardé d'un onglet à l'autre -->
    <div v-if="isStaff" class="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="Jour affiché">
      <span class="text-gray-600">📅 Jour :</span>
      <button
        v-for="d in DAY_CHOICES" :key="d.label" type="button"
        :class="['rounded-full px-3 py-1 ring-1', filters.day === d.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
        :aria-pressed="filters.day === d.value"
        @click="setDay(d.value)"
      >
        {{ d.label }}<span v-if="d.count" class="ml-1 opacity-75">({{ d.count }})</span>
      </button>
      <input
        type="date" class="input w-auto py-1"
        :value="filters.day && filters.day !== todayIso && filters.day !== tomorrowIso ? filters.day : ''"
        aria-label="Autre jour" title="Autre jour"
        @change="$event.target.value && setDay($event.target.value)"
      >
      <span v-if="filters.day" class="hidden sm:inline text-gray-500">· courses du jour et retards encore en cours</span>
    </div>

    <!-- Files d'attente du dispatch -->
    <div v-if="isStaff" class="flex gap-2 overflow-x-auto no-scrollbar pb-1 [mask-image:linear-gradient(to_right,black_88%,transparent)] md:[mask-image:none]">
      <button
        v-for="q in visibleQueues"
        :key="q.value"
        :class="['btn whitespace-nowrap', filters.queue === q.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']"
        @click="setQueue(q.value)"
      >
        {{ q.label }}
        <span v-if="tabCount(q.value)" :class="['ml-1 rounded-full px-1.5 text-xs font-semibold', filters.queue === q.value ? 'bg-white/20' : 'bg-slate-100 text-slate-700']">{{ tabCount(q.value) }}</span>
        <span
          v-if="tabLate(q.value)"
          class="ml-1 rounded-full bg-red-600 px-1.5 text-xs font-semibold text-white"
          :title="`${tabLate(q.value)} course(s) sans livreur depuis trop longtemps`"
        >⏰ {{ tabLate(q.value) }}</span>
      </button>
    </div>

    <!-- Sans livreur : ramassage, livraison ou les deux ; de la plus ancienne attente à la plus récente -->
    <div v-if="filters.queue === 'unassigned'" class="flex flex-wrap items-center gap-2 text-sm">
      <span class="text-gray-600">Afficher :</span>
      <button
        v-for="a in AWAITING" :key="a.value" type="button"
        :class="['rounded-full px-3 py-1 ring-1', filters.awaiting === a.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
        @click="setAwaiting(a.value)"
      >
        {{ a.label }}
      </button>
      <span class="text-gray-500">· de la plus ancienne attente à la plus récente</span>
    </div>

    <!-- Jour choisi : courses prévues plus tard hors de la file, à ajouter pour un ramassage en avance -->
    <p v-if="laterCount || (filters.include_later && LATER_QUEUES[filters.queue])" class="card px-3 py-2 text-sm bg-amber-50 border-amber-200 text-amber-900">
      <template v-if="!filters.include_later">
        + {{ laterCount }} {{ LATER_QUEUES[filters.queue] }} pour une livraison après le {{ date(filters.day) }}
        · <button type="button" class="font-medium underline" @click="toggleLater">Afficher</button>
      </template>
      <template v-else>
        Courses prévues plus tard affichées (étiquette 📅)
        · <button type="button" class="font-medium underline" @click="toggleLater">Masquer</button>
      </template>
    </p>
    <p v-else-if="filters.day && filters.queue && !isWorkQueue && filters.queue !== 'scheduled'" class="text-xs text-gray-500">
      Cette file montre toutes les dates : chaque cas reste visible jusqu'à ce qu'il soit réglé.
    </p>

    <!-- Ramassages regroupés par client -->
    <PickupGroupsPanel v-if="byClient" :couriers="couriers" :day="filters.day" :include-later="filters.include_later" @assigned="dispatchCounts.fetchCounts().catch(() => {})" />

    <template v-else>
      <!-- Téléphone : recherche et filtres dans un panneau -->
      <div class="md:hidden flex gap-2">
        <input v-model="filters.search" class="input" placeholder="Code, téléphone, nom…" aria-label="Rechercher une course" @input="debouncedLoad">
        <button type="button" :class="['btn-secondary shrink-0', activeFilters ? 'ring-2 ring-slate-900' : '']" @click="filtersOpen = true">
          Filtres<span v-if="activeFilters">&nbsp;({{ activeFilters }})</span>
        </button>
      </div>
      <BottomSheet :open="filtersOpen" title="Filtres" @close="filtersOpen = false">
        <div class="space-y-3">
          <div>
            <label class="label" for="f-status">Statut</label>
            <select id="f-status" v-model="filters.status" class="input" @change="load(1)">
              <option value="">Tous les statuts</option>
              <option v-for="(label, value) in STATUS_LABELS" :key="value" :value="value">{{ label }}</option>
            </select>
          </div>
          <div v-if="isStaff">
            <label class="label" for="f-merchant">Marchand</label>
            <select id="f-merchant" v-model="filters.merchant_id" class="input" @change="load(1)">
              <option value="">Tous les marchands</option>
              <option v-for="m in merchants" :key="m.id" :value="String(m.id)">{{ m.business_name }}</option>
            </select>
          </div>
          <div>
            <p class="label">Période <span class="font-normal text-gray-500">(remplace le jour)</span></p>
            <select v-if="isStaff" v-model="filters.date_basis" class="input mb-2" aria-label="Dates selon" @change="filters.from || filters.to ? onPeriod() : null">
              <option value="delivery">📅 Selon la livraison</option>
              <option value="created">🆕 Selon la création</option>
            </select>
            <div class="grid grid-cols-2 gap-2">
              <input v-model="filters.from" type="date" class="input" aria-label="Du" @change="onPeriod">
              <input v-model="filters.to" type="date" class="input" aria-label="Au" @change="onPeriod">
            </div>
          </div>
          <div class="flex gap-2 pt-2">
            <button type="button" class="btn-secondary flex-1" @click="clearFilters">Effacer</button>
            <button type="button" class="btn-primary flex-1" @click="filtersOpen = false">Voir les courses</button>
          </div>
        </div>
      </BottomSheet>

      <div :class="['card p-3 hidden md:grid grid-cols-2 gap-2', isStaff ? 'md:grid-cols-7' : 'md:grid-cols-6']">
        <input v-model="filters.search" class="input col-span-2" placeholder="Code, téléphone, nom, référence…" @input="debouncedLoad">
        <select v-model="filters.status" class="input" @change="load(1)">
          <option value="">Tous les statuts</option>
          <option v-for="(label, value) in STATUS_LABELS" :key="value" :value="value">{{ label }}</option>
        </select>
        <select v-if="isStaff" v-model="filters.merchant_id" class="input" @change="load(1)">
          <option value="">Tous les marchands</option>
          <option v-for="m in merchants" :key="m.id" :value="String(m.id)">{{ m.business_name }}</option>
        </select>
        <!-- Dates : jour de livraison prévu (par défaut) ou jour de création ; l'e-commerçant voit toujours le jour de livraison -->
        <select v-if="isStaff" v-model="filters.date_basis" class="input" aria-label="Dates selon" title="Dates : jour de livraison prévu ou jour de création" @change="filters.from || filters.to ? onPeriod() : null">
          <option value="delivery">📅 Livraison</option>
          <option value="created">🆕 Création</option>
        </select>
        <input v-model="filters.from" type="date" class="input" :aria-label="filters.date_basis === 'created' ? 'Créées à partir du' : 'À livrer à partir du'" :title="filters.date_basis === 'created' ? 'Créées à partir du' : 'À livrer à partir du'" @change="onPeriod">
        <input v-model="filters.to" type="date" class="input" :aria-label="filters.date_basis === 'created' ? 'Créées jusqu\'au' : 'À livrer jusqu\'au'" :title="filters.date_basis === 'created' ? 'Créées jusqu\'au' : 'À livrer jusqu\'au'" @change="onPeriod">
      </div>

      <p v-if="filters.courier_id" class="text-sm">
        Filtré sur un livreur · <button class="text-blue-600" @click="filters.courier_id = ''; load(1)">retirer le filtre</button>
      </p>

      <!-- Actions groupées : validation, assignation -->
      <!-- Téléphone : barre fixée au-dessus des onglets, toujours à portée de pouce -->
      <div v-if="isStaff && selected.length" class="card p-3 flex flex-wrap items-center gap-2 bg-blue-50 border-blue-200 fixed inset-x-2 bottom-16 z-30 shadow-lg md:static md:shadow-none">
        <span class="text-sm font-medium">{{ selected.length }} sélectionnée(s)</span>
        <template v-if="auth.can('orders.dispatch') && pendingSelected.length">
          <button class="btn-success" :disabled="confirming" @click="bulkConfirm">
            {{ confirming ? 'Validation…' : `✅ Valider (${pendingSelected.length})` }}
          </button>
          <span class="mx-1 h-6 w-px bg-blue-200" aria-hidden="true" />
        </template>
        <select v-model="bulk.type" class="input w-auto">
          <option v-if="filters.queue === 'unassigned'" value="auto">Selon la course (ramassage ou livraison)</option>
          <option value="pickup">Ramassage</option>
          <option value="delivery">Livraison</option>
          <option value="return">Retour</option>
        </select>
        <select v-model="bulk.courier_id" class="input w-auto">
          <option :value="null" disabled>Livreur…</option>
          <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.active_assignments_count }} en cours){{ c.is_available ? '' : ' · indisponible' }}</option>
        </select>
        <button class="btn-primary" :disabled="!bulk.courier_id || assigning" @click="bulkAssign">Assigner</button>
        <button v-if="auth.can('orders.dispatch') && withCourierSelected.length" class="btn-secondary text-red-700" :disabled="assigning" @click="bulkUnassign">
          Retirer le livreur ({{ withCourierSelected.length }})
        </button>
        <button class="btn-secondary" @click="selected = []">Annuler</button>
      </div>

      <!-- Téléphone : une carte par course -->
      <div class="md:hidden space-y-2">
        <p v-if="loading" class="card p-6 text-center text-gray-500">Chargement…</p>
        <p v-else-if="!orders.length" class="card p-6 text-center text-gray-500">Aucune course.</p>
        <article
          v-for="o in orders"
          v-else
          :key="o.id"
          :class="['card p-3 space-y-1.5 cursor-pointer', highlighted === o.id ? 'bg-yellow-50' : o.awaiting_courier?.late ? 'bg-red-50/70' : '']"
          @click="open(o)"
        >
          <div class="flex items-center justify-between gap-2">
            <label v-if="isStaff" class="flex items-center gap-2 min-w-0" @click.stop>
              <input v-model="selected" type="checkbox" :value="o.id" :aria-label="`Sélectionner ${o.tracking_code}`">
              <span class="font-mono text-xs truncate">{{ o.tracking_code }}</span>
            </label>
            <span v-else class="font-mono text-xs">{{ o.tracking_code }}</span>
            <span class="font-semibold whitespace-nowrap">{{ money(o.amounts.cod_amount) }}</span>
          </div>
          <div class="flex items-center justify-between gap-2">
            <div class="min-w-0">
              <div class="font-medium truncate">{{ o.recipient.name || '—' }}</div>
              <div class="text-xs text-gray-500">{{ o.recipient.phone }}</div>
            </div>
            <a v-if="o.recipient.phone" :href="`tel:${o.recipient.phone}`" class="tap shrink-0 grid h-10 w-10 place-items-center rounded-full bg-slate-100 text-lg" :aria-label="`Appeler ${o.recipient.name || 'le destinataire'}`" @click.stop>📞</a>
          </div>
          <div class="text-xs text-gray-600">{{ o.pickup.zone_name }} → {{ o.delivery.zone_name }}<span v-if="isStaff && o.merchant"> · {{ o.merchant.business_name }}</span></div>
          <OrderStatusCell :order="o" :late="isLate(o)" :late-day="deliveryDay(o)" @decide="deciding = $event" />
          <div v-if="o.pickup_courier || o.delivery_courier || o.held_by" class="flex flex-wrap gap-x-3 text-xs text-gray-700">
            <span v-if="o.pickup_courier">↑ {{ o.pickup_courier.name }}</span>
            <span v-if="o.delivery_courier">↓ {{ o.delivery_courier.name }}</span>
            <span v-if="o.held_by" class="text-amber-700">🎒 {{ o.held_by.name }}</span>
          </div>
          <div class="text-xs text-gray-400">Livraison {{ date(o.delivery.scheduled_date || o.created_at) }} · créée {{ dateTime(o.created_at) }}</div>
        </article>
      </div>

      <div class="card overflow-x-auto hidden md:block">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr>
              <th v-if="isStaff" class="p-2 w-8"><input type="checkbox" :checked="allSelected" aria-label="Tout sélectionner" @change="toggleAll"></th>
              <th class="p-2">Code</th>
              <th v-if="isStaff" class="p-2">Marchand</th>
              <th class="p-2">Destinataire</th>
              <th class="p-2">Trajet</th>
              <th class="p-2">Statut</th>
              <th class="p-2">Livreur</th>
              <th class="p-2 text-right">À encaisser</th>
              <th class="p-2">Livraison · création</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-if="loading"><td colspan="9" class="p-6 text-center text-gray-500">Chargement…</td></tr>
            <tr v-else-if="!orders.length"><td colspan="9" class="p-6 text-center text-gray-500">Aucune course.</td></tr>
            <tr
              v-for="o in orders"
              v-else
              :key="o.id"
              :class="['hover:bg-slate-50 cursor-pointer', highlighted === o.id ? 'bg-yellow-50' : o.awaiting_courier?.late ? 'bg-red-50/70' : '']"
              @click="open(o)"
            >
              <td v-if="isStaff" class="p-2" @click.stop><input v-model="selected" type="checkbox" :value="o.id" :aria-label="`Sélectionner ${o.tracking_code}`"></td>
              <td class="p-2 font-mono text-xs">{{ o.tracking_code }}</td>
              <td v-if="isStaff" class="p-2">{{ o.merchant?.business_name }}</td>
              <td class="p-2">
                <div>{{ o.recipient.name || '—' }}</div>
                <div class="text-xs text-gray-500">{{ o.recipient.phone }}</div>
              </td>
              <td class="p-2 whitespace-nowrap">{{ o.pickup.zone_name }} → {{ o.delivery.zone_name }}</td>
              <td class="p-2">
                <OrderStatusCell :order="o" :late="isLate(o)" :late-day="deliveryDay(o)" @decide="deciding = $event" />
              </td>
              <td class="p-2 text-xs">
                <div v-if="o.pickup_courier">↑ {{ o.pickup_courier.name }}</div>
                <div v-if="o.delivery_courier">↓ {{ o.delivery_courier.name }}</div>
                <div v-if="o.held_by" class="text-amber-700" :title="`Colis chez ${o.held_by.name}`">🎒 {{ o.held_by.name }}</div>
              </td>
              <td class="p-2 text-right whitespace-nowrap">{{ money(o.amounts.cod_amount) }}</td>
              <td class="p-2 text-xs whitespace-nowrap">
                <!-- Jour de livraison : la date prévue, sinon le jour de création -->
                <div class="font-medium text-gray-800">{{ date(o.delivery.scheduled_date || o.created_at) }}</div>
                <div class="text-gray-500">créée {{ dateTime(o.created_at) }}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :meta="meta" @change="load" />
    </template>

    <OrderDecision :open="!!deciding" :order="deciding" :couriers="couriers" @close="deciding = null" @decided="decided" />
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import OrderStatusCell from '../../components/OrderStatusCell.vue'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import Pagination from '../../components/Pagination.vue'
import OrderDecision from '../../components/OrderDecision.vue'
import PickupGroupsPanel from '../../components/PickupGroupsPanel.vue'
import DeliveryDayBar from '../../components/DeliveryDayBar.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { useDispatchCountStore } from '../../stores/dispatchCounts'
import { orderChanges, lastOrderChange } from '../../composables/useRealtime'
import { date, dateTime, money, STATUS_LABELS } from '../../utils/format'

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()
const route = useRoute()

const isStaff = computed(() => !auth.user?.merchant_id)

const isoDay = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const todayIso = isoDay(new Date())
const tomorrowIso = isoDay(new Date(Date.now() + 86400000))
// Jour de livraison : la date prévue, sinon le jour de création
const deliveryDay = (o) => (o.delivery.scheduled_date || o.created_at || '').slice(0, 10)
const FINAL = ['delivered', 'cancelled', 'rejected', 'returned', 'lost']
// Jour affiché par l'agence : aujourd'hui par défaut, « all » dans l'adresse pour toutes les dates
const initialDay = () => {
  if (!auth.user || auth.user.merchant_id) return ''
  const q = route.query.day
  if (q === 'all') return ''
  return /^\d{4}-\d{2}-\d{2}$/.test(q || '') ? q : todayIso
}
// Files de travail (limitées au jour et aux retards) ; on peut y ajouter les courses prévues plus tard
const WORK_QUEUES = ['to_pickup', 'by_client', 'to_prepare', 'to_deliver', 'unassigned']
const LATER_QUEUES = { to_pickup: 'à ramasser', by_client: 'à ramasser', to_prepare: 'à préparer', unassigned: 'à ramasser' }

const queues = [
  { value: '', label: 'Toutes' },
  { value: 'to_confirm', label: 'À valider' },
  { value: 'to_pickup', label: 'À ramasser' },
  { value: 'by_client', label: '🏪 Ramassages par client' },
  { value: 'to_prepare', label: 'À préparer' },
  { value: 'to_deliver', label: 'À livrer' },
  { value: 'unassigned', label: '🛵 Sans livreur' },
  { value: 'to_decide', label: 'À décider' },
  { value: 'scheduled', label: 'Prévues plus tard' },
  { value: 'incidents', label: 'Incidents' },
  { value: 'to_return', label: 'À retourner' },
  // Livrées hors connexion avec un code client incorrect
  { value: 'code_to_verify', label: '⚠️ Codes à vérifier' },
]

// Écran à part, réservé au dispatch (pas une file de la liste)
const visibleQueues = computed(() => queues.filter((q) => q.value !== 'by_client' || auth.can('orders.dispatch')))

const filters = reactive({
  queue: route.query.queue || '',
  search: route.query.search || '',
  status: '',
  merchant_id: route.query.merchant_id || '',
  courier_id: route.query.courier_id || '',
  // File « Sans livreur » : pickup, delivery ou les deux
  awaiting: route.query.awaiting || '',
  from: '',
  to: '',
  // Jour de travail (AAAA-MM-JJ) ; vide : toutes les dates
  day: initialDay(),
  // Files de travail : ajouter les courses à livrer après ce jour
  include_later: false,
  // Période selon le jour de livraison prévu (sinon le jour de création) ou selon le jour de création
  date_basis: route.query.date_basis === 'created' ? 'created' : 'delivery',
})

const orders = ref([])
const meta = ref(null)
const loading = ref(false)
const merchants = ref([])
const couriers = ref([])
const selected = ref([])
const bulk = reactive({ type: route.query.queue === 'unassigned' ? 'auto' : 'pickup', courier_id: null })
const AWAITING = [
  { value: '', label: 'Les deux' },
  { value: 'pickup', label: 'Ramassage' },
  { value: 'delivery', label: 'Livraison' },
]
const assigning = ref(false)
// Téléphone : panneau des filtres et nombre de filtres actifs
const filtersOpen = ref(false)
const activeFilters = computed(() => [filters.status, filters.merchant_id, filters.from || filters.to].filter(Boolean).length)
function clearFilters() {
  filters.status = ''
  filters.merchant_id = ''
  filters.from = ''
  filters.to = ''
  load(1)
}
const confirming = ref(false)
// Courses sélectionnées encore en attente de validation
const pendingSelected = computed(() => selected.value.filter((id) => orders.value.find((o) => o.id === id)?.status === 'pending'))
const highlighted = ref(null)
// Courses sélectionnées qui ont un livreur (ramassage, livraison ou retour)
const withCourierSelected = computed(() => selected.value.filter((id) => {
  const o = orders.value.find((x) => x.id === id)
  return o && (o.pickup_courier || o.delivery_courier || o.return_courier)
}))

const allSelected = computed(() => orders.value.length > 0 && selected.value.length === orders.value.length)

// Compteurs des files et courses en attente d'un livreur depuis trop longtemps
const dispatchCounts = useDispatchCountStore()
const isDispatch = computed(() => auth.can('orders.dispatch'))
const COUNTED = { to_confirm: 'to_confirm', to_pickup: 'to_pickup', to_deliver: 'to_deliver', unassigned: 'unassigned' }
// Jour choisi : compteurs de ce jour (retards compris) ; sinon compteurs généraux
const DAY_COUNTED = { '': 'all', to_confirm: 'to_confirm', to_pickup: 'to_pickup', by_client: 'to_pickup', to_prepare: 'to_prepare', to_deliver: 'to_deliver', unassigned: 'unassigned' }
const dayCounts = computed(() => (filters.day && dispatchCounts.dayCounts?.date === filters.day ? dispatchCounts.dayCounts : null))
const tabCount = (queue) => {
  if (!isDispatch.value) return 0
  if (dayCounts.value) return dayCounts.value[DAY_COUNTED[queue]] || 0
  return COUNTED[queue] ? dispatchCounts.counts[COUNTED[queue]] : 0
}
// Courses prévues après le jour choisi, hors de la file tant qu'on ne les demande pas
const laterCount = computed(() => {
  if (!filters.day || !LATER_QUEUES[filters.queue]) return 0
  return dayCounts.value?.later?.[filters.queue === 'by_client' ? 'to_pickup' : filters.queue] || 0
})
const isWorkQueue = computed(() => WORK_QUEUES.includes(filters.queue))
// En retard : le jour de livraison est passé et la course est toujours en cours
const isLate = (o) => !!filters.day && !FINAL.includes(o.status) && deliveryDay(o) < (filters.day < todayIso ? filters.day : todayIso)
const DAY_CHOICES = computed(() => [
  { value: todayIso, label: 'Aujourd\'hui', count: dispatchCounts.dayCounts?.days?.today },
  { value: tomorrowIso, label: 'Demain', count: dispatchCounts.dayCounts?.days?.tomorrow },
  { value: '', label: 'Toutes les dates' },
])
const tabLate = (queue) => {
  if (!isDispatch.value) return 0
  const late = { to_pickup: dispatchCounts.counts.late.pickup, to_deliver: dispatchCounts.counts.late.delivery, unassigned: dispatchCounts.counts.late.total }[queue] || 0
  // Jour choisi : pas plus que la file n'en montre (les autres sont dans « prévues plus tard »)
  return dayCounts.value ? Math.min(late, tabCount(queue)) : late
}


// Alerte « sans livreur » reçue : la liste se met à jour
watch(() => dispatchCounts.version, () => load(meta.value?.current_page || 1))

const byClient = computed(() => filters.queue === 'by_client')

async function load(page = 1) {
  if (byClient.value) {
    if (isDispatch.value) dispatchCounts.fetchCounts().catch(() => {})
    return
  }
  loading.value = orders.value.length === 0
  const params = { page }
  for (const [k, v] of Object.entries(filters)) {
    if (v) params[k] = k === 'status' ? [v] : (k === 'include_later' ? 1 : v)
  }
  const { data } = await http.get('/orders', { params })
  orders.value = data.data
  meta.value = data.meta
  loading.value = false
  if (isDispatch.value) dispatchCounts.fetchCounts().catch(() => {})
  selected.value = selected.value.filter((id) => orders.value.some((o) => o.id === id))
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(() => load(1), 300)
}

// Adresse de la page : file, étape attendue et jour (gardé d'un onglet à l'autre)
function syncQuery() {
  const query = {}
  if (filters.queue) query.queue = filters.queue
  if (filters.queue === 'unassigned' && filters.awaiting) query.awaiting = filters.awaiting
  if (isStaff.value && filters.day !== todayIso) query.day = filters.day || 'all'
  router.replace({ query })
}

function setQueue(value) {
  filters.queue = value
  filters.awaiting = ''
  filters.include_later = false
  bulk.type = { to_deliver: 'delivery', incidents: 'delivery', to_return: 'return', unassigned: 'auto' }[value] || 'pickup'
  syncQuery()
  load(1)
}

function setAwaiting(value) {
  filters.awaiting = value
  bulk.type = value || 'auto'
  syncQuery()
  load(1)
}

// Jour choisi : remplace la période ; les courses prévues plus tard redeviennent masquées
function setDay(value) {
  filters.day = value
  filters.from = ''
  filters.to = ''
  filters.include_later = false
  dispatchCounts.day = value || null
  syncQuery()
  load(1)
}

// Période choisie : elle remplace le jour
function onPeriod() {
  if (filters.day) {
    filters.day = ''
    dispatchCounts.day = null
    syncQuery()
  }
  load(1)
}

function toggleLater() {
  filters.include_later = !filters.include_later
  load(1)
}

function toggleAll(e) {
  selected.value = e.target.checked ? orders.value.map((o) => o.id) : []
}

// Suite à donner à un colis non livré
const deciding = ref(null)
function decided(order) {
  deciding.value = null
  toasts.success(order.status === 'rescheduled' ? `Relivraison prévue le ${date(order.delivery.scheduled_date)}.` : (order.return_requested ? 'Retour au marchand décidé.' : 'Décision enregistrée.'))
  load(meta.value?.current_page || 1)
}

function open(order) {
  router.push(`${auth.homeRoute}/courses/${order.id}`)
}

async function bulkConfirm() {
  const count = pendingSelected.value.length
  if (!window.confirm(`Valider ${count} course(s) en attente ?`)) return
  confirming.value = true
  try {
    const { data } = await http.post('/orders/bulk-confirm', { order_ids: pendingSelected.value })
    if (data.data.confirmed.length) toasts.success(`${data.data.confirmed.length} course(s) validée(s).`)
    Object.entries(data.data.errors).forEach(([id, message]) => {
      const code = orders.value.find((o) => o.id === Number(id))?.tracking_code || id
      toasts.error(`${code} : ${message}`)
    })
    // Encore affichées (file « Toutes ») : elles restent sélectionnées, prêtes à être assignées
    await load(meta.value?.current_page || 1)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    confirming.value = false
  }
}

async function bulkAssign() {
  assigning.value = true
  try {
    // « Selon la course » : ramassage pour les courses à ramasser, livraison pour les colis à livrer
    const groups = bulk.type === 'auto'
      ? Object.entries(selected.value.reduce((acc, id) => {
        const stage = orders.value.find((o) => o.id === id)?.awaiting_courier?.stage || 'pickup'
        ;(acc[stage] ||= []).push(id)
        return acc
      }, {}))
      : [[bulk.type, selected.value]]
    const result = { assigned: [], errors: {} }
    for (const [type, ids] of groups) {
      const { data } = await http.post('/orders/bulk-assign', { order_ids: ids, type, courier_id: bulk.courier_id })
      result.assigned.push(...data.data.assigned)
      Object.assign(result.errors, data.data.errors)
    }
    const data = { data: result }
    const errors = Object.entries(data.data.errors)
    if (data.data.assigned.length) toasts.success(`${data.data.assigned.length} course(s) assignée(s).`)
    errors.forEach(([id, message]) => {
      const code = orders.value.find((o) => o.id === Number(id))?.tracking_code || id
      toasts.error(`${code} : ${message}`)
    })
    selected.value = []
    await load(meta.value?.current_page || 1)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    assigning.value = false
  }
}

async function bulkUnassign() {
  const ids = withCourierSelected.value
  // « Selon la course » : la mission de l'étape en cours de chaque course
  const type = bulk.type === 'auto' ? undefined : bulk.type
  const what = type ? `du ${{ pickup: 'ramassage', delivery: 'livraison', return: 'retour' }[type]}` : 'de l\'étape en cours'
  const reason = window.prompt(`Retirer le livreur ${what} sur ${ids.length} course(s) ? Les livreurs seront prévenus.\nMotif (facultatif) :`, '')
  if (reason === null) return
  assigning.value = true
  try {
    const { data } = await http.post('/orders/bulk-unassign', { order_ids: ids, type, reason: reason.trim() || undefined })
    if (data.data.unassigned.length) toasts.success(`Livreur retiré sur ${data.data.unassigned.length} course(s).`)
    Object.entries(data.data.errors).forEach(([id, message]) => {
      const code = orders.value.find((o) => o.id === Number(id))?.tracking_code || id
      toasts.error(`${code} : ${message}`)
    })
    selected.value = []
    await load(meta.value?.current_page || 1)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    assigning.value = false
  }
}

watch(() => route.query.search, (value) => {
  if (value === undefined) return
  filters.search = value
  load(1)
})

// Ailleurs (menu, tableau de bord) : compteurs généraux seulement
onUnmounted(() => { dispatchCounts.day = null })

// Rafraîchissement temps réel
watch(orderChanges, async () => {
  await load(meta.value?.current_page || 1)
  highlighted.value = lastOrderChange.value?.order?.id
  setTimeout(() => { highlighted.value = null }, 3000)
})

onMounted(async () => {
  dispatchCounts.day = filters.day || null
  await load()
  if (isStaff.value) {
    const empty = { data: { data: [] } }
    const [m, c] = await Promise.all([
      http.get('/merchants', { params: { per_page: 200 } }).catch(() => empty),
      auth.can('orders.dispatch') ? http.get('/couriers') : Promise.resolve(empty),
    ])
    merchants.value = m.data.data
    couriers.value = c.data.data
  }
})
</script>
