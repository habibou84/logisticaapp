<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <h1 class="text-xl font-bold">{{ isMerchant ? 'Mon point de livraisons' : 'Tableau de bord' }}</h1>
      <div class="flex items-end gap-2">
        <div><label class="label" for="from">Du</label><input id="from" v-model="period.from" type="date" class="input" @change="loadSummary"></div>
        <div><label class="label" for="to">Au</label><input id="to" v-model="period.to" type="date" class="input" @change="loadSummary"></div>
      </div>
    </div>

    <!-- Files d'attente du dispatch (personnel) -->
    <div v-if="!isMerchant && auth.can('orders.dispatch')" class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <RouterLink
        v-for="q in queues"
        :key="q.value"
        :to="{ path: '/admin/courses', query: { queue: q.value } }"
        :class="['card p-4 hover:shadow', q.count ? q.accent : '']"
      >
        <p class="text-sm text-gray-600">{{ q.label }}</p>
        <p class="text-2xl font-bold">{{ q.count ?? '—' }}</p>
      </RouterLink>
    </div>

    <!-- En attente d'un livreur (dispatch) : ramassage et livraison, retards, attente la plus longue -->
    <section v-if="!isMerchant && auth.can('orders.dispatch') && dispatchCounts.loaded" class="card p-4 space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold">🛵 En attente d'un livreur</h2>
        <span class="flex flex-wrap gap-3 text-sm">
          <RouterLink v-if="auth.can('settings.manage')" to="/admin/reglages/alertes" class="text-gray-500">⚙️ Délais d'alerte</RouterLink>
          <RouterLink :to="{ path: '/admin/courses', query: { queue: 'unassigned' } }" class="text-blue-600">Tout voir et assigner</RouterLink>
        </span>
      </div>
      <p v-if="cutoff?.passed && cutoff.count" class="rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white">
        🕒 Il est plus de {{ cutoff.time }} : {{ cutoff.count }} course{{ cutoff.count > 1 ? 's' : '' }} du jour
        {{ cutoff.count > 1 ? 'n\'ont' : 'n\'a' }} toujours pas de livreur et risque{{ cutoff.count > 1 ? 'nt' : '' }} de ne pas être livrée{{ cutoff.count > 1 ? 's' : '' }} aujourd'hui.
      </p>
      <!-- Ramassages de courses à livrer un autre jour : à faire, sans retard de livraison -->
      <p v-if="cutoff?.passed && cutoff.later" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
        📦 Et {{ cutoff.later }} course{{ cutoff.later > 1 ? 's' : '' }} à ramasser pour un autre jour : pas de retard de livraison.
      </p>
      <p v-else-if="cutoff && !cutoff.passed" class="text-xs text-gray-500">Heure limite du jour : {{ cutoff.time }}.</p>
      <p v-if="!waitingTotal" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">✓ Toutes les courses à ramasser et à livrer ont un livreur.</p>
      <div v-else class="grid sm:grid-cols-2 gap-3">
        <RouterLink
          v-for="w in waiting" :key="w.stage"
          :to="{ path: '/admin/courses', query: { queue: 'unassigned', awaiting: w.stage } }"
          :class="['rounded-xl border p-4 hover:shadow transition', w.late ? 'border-red-300 bg-red-50' : w.count ? 'border-amber-300 bg-amber-50' : 'border-slate-200']"
        >
          <p class="text-sm text-gray-600">{{ w.label }}</p>
          <p class="mt-1 flex items-baseline gap-2">
            <span class="text-3xl font-bold">{{ w.count }}</span>
            <span v-if="w.late" class="rounded-full bg-red-600 px-2 py-0.5 text-xs font-semibold text-white">⏰ {{ w.late }} en retard</span>
          </p>
          <p v-if="w.count" :class="['text-sm mt-1', w.late ? 'text-red-700 font-medium' : 'text-gray-600']">
            La plus ancienne : {{ duration(w.oldest_minutes) }}<span v-if="w.threshold_minutes" class="text-gray-500 font-normal"> · alerte après {{ duration(w.threshold_minutes) }}</span>
          </p>
          <p v-else class="text-sm mt-1 text-emerald-700">Aucune en attente</p>
        </RouterLink>
      </div>
    </section>

    <!-- Livraisons de la période : courses à livrer ces jours-là (date prévue, sinon jour de création) -->
    <div v-if="summary" class="flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="font-semibold">🚚 {{ isOneDay ? (period.from === today() ? 'Livraisons du jour' : `Livraisons du ${date(period.from)}`) : 'Livraisons de la période' }}</h2>
      <p class="text-xs text-gray-500">Selon la date de livraison prévue (une course reportée compte le jour de sa nouvelle date)</p>
    </div>
    <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
      <StatCard title="Total" :value="summary.counts.total" icon="📦" />
      <StatCard title="Livrés" :value="summary.counts.delivered" icon="✅" />
      <StatCard title="En cours" :value="summary.counts.in_progress" icon="🚚" />
      <StatCard title="Non livrés" :value="summary.counts.failed" icon="⚠️" />
      <StatCard title="Reportés" :value="summary.counts.rescheduled" icon="📅" />
      <StatCard title="Retournés" :value="summary.counts.returned + summary.counts.returning" icon="↩️" />
      <StatCard title="Annulés" :value="summary.counts.cancelled" icon="✖️" />
    </div>

    <div v-if="summary" class="grid md:grid-cols-4 xl:grid-cols-5 gap-3">
      <StatCard title="Montant encaissé" :value="money(summary.amounts.collected)" icon="💰" />
      <StatCard title="Frais de livraison retenus" :value="money(summary.amounts.fees)" icon="🧾" />
      <StatCard v-if="summary.amounts.shipping_fees" title="Frais d'expédition" :value="money(summary.amounts.shipping_fees)" icon="🚌" />
      <StatCard v-if="summary.amounts.other_fees" title="Autres frais" :value="money(summary.amounts.other_fees)" icon="💸" />
      <StatCard :title="isMerchant ? 'Net à recevoir' : 'Net à reverser'" :value="money(summary.amounts.net_to_merchant)" icon="🏦" />
      <StatCard title="Taux de livraison" :value="summary.delivery_rate === null ? '—' : `${summary.delivery_rate} %`" icon="📈" />
    </div>

    <!-- Nouvelles courses : l'activité des e-commerçants, selon le jour de création -->
    <section v-if="!isMerchant && created" class="card p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold">🆕 Nouvelles courses {{ isOneDay ? (period.from === today() ? 'du jour' : `du ${date(period.from)}`) : 'de la période' }}</h2>
        <RouterLink :to="{ path: '/admin/courses', query: { date_basis: 'created' } }" class="text-sm text-blue-600">Voir par date de création</RouterLink>
      </div>
      <p class="mt-2 text-sm text-gray-700">
        <strong class="text-2xl text-gray-900 align-middle">{{ created.counts.total }}</strong>
        course{{ created.counts.total > 1 ? 's' : '' }} créée{{ created.counts.total > 1 ? 's' : '' }}<template v-if="createdLater">,
          dont <strong>{{ createdLater }}</strong> à livrer plus tard</template>.
      </p>
    </section>

    <div class="card">
      <div class="flex items-center justify-between p-4 border-b">
        <h2 class="font-semibold">Dernières courses</h2>
        <RouterLink :to="`${auth.homeRoute}/courses`" class="text-sm text-blue-600">Tout voir</RouterLink>
      </div>
      <ul class="divide-y">
        <li v-for="o in recent" :key="o.id">
          <RouterLink :to="`${auth.homeRoute}/courses/${o.id}`" class="flex flex-wrap items-center gap-3 p-3 hover:bg-slate-50 text-sm">
            <span class="font-mono text-xs">{{ o.tracking_code }}</span>
            <span class="flex-1">{{ o.recipient.name || o.recipient.phone }} · {{ o.delivery.zone_name }}</span>
            <StatusBadge :status="o.status" :label="o.status_label" />
          </RouterLink>
        </li>
        <li v-if="!recent.length" class="p-4 text-sm text-gray-500">Aucune course pour le moment.</li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http from '../../bootstrap/axios'
import StatCard from '../../components/StatCard.vue'
import StatusBadge from '../../components/StatusBadge.vue'
import { useAuthStore } from '../../stores/auth'
import { useDispatchCountStore } from '../../stores/dispatchCounts'
import { orderChanges } from '../../composables/useRealtime'
import { date, money, today, waitDuration } from '../../utils/format'

const auth = useAuthStore()
const isMerchant = computed(() => !!auth.user?.merchant_id)

// En attente d'un livreur : mêmes compteurs que le menu (rafraîchis chaque minute et à chaque alerte)
const dispatchCounts = useDispatchCountStore()
const waiting = computed(() => [
  { stage: 'pickup', label: 'À assigner au ramassage', ...dispatchCounts.counts.waiting?.pickup },
  { stage: 'delivery', label: 'À assigner à la livraison', ...dispatchCounts.counts.waiting?.delivery },
])
const cutoff = computed(() => dispatchCounts.counts.cutoff)
const waitingTotal = computed(() => waiting.value.reduce((n, w) => n + (w.count || 0), 0))

const duration = waitDuration

const period = reactive({ from: isMerchantStart(), to: today() })
const summary = ref(null)
// Personnel : les courses créées sur la période (activité), dont celles à livrer après la période
const created = ref(null)
const createdLater = ref(0)
const isOneDay = computed(() => period.from && period.from === period.to)
const recent = ref([])
const queues = ref([
  { value: 'to_confirm', label: 'À valider', count: null, accent: 'border-amber-300 bg-amber-50' },
  { value: 'to_pickup', label: 'À ramasser', count: null, accent: 'border-indigo-300 bg-indigo-50' },
  { value: 'to_deliver', label: 'À livrer', count: null, accent: 'border-blue-300 bg-blue-50' },
  { value: 'incidents', label: 'Incidents', count: null, accent: 'border-red-300 bg-red-50' },
  { value: 'to_return', label: 'À retourner', count: null, accent: 'border-rose-300 bg-rose-50' },
])

// Marchand : depuis le début du mois ; personnel : aujourd'hui
function isMerchantStart() {
  return useAuthStore().user?.merchant_id ? `${today().slice(0, 8)}01` : today()
}

async function loadSummary() {
  const [delivery, byCreation, later] = await Promise.all([
    http.get('/reports/summary', { params: { ...period, date_basis: 'delivery' } }),
    isMerchant.value ? null : http.get('/reports/summary', { params: { ...period, date_basis: 'created' } }),
    isMerchant.value ? null : http.get('/orders', { params: { ...period, date_basis: 'created', queue: 'scheduled', per_page: 1 } }),
  ])
  summary.value = delivery.data.data
  created.value = byCreation?.data.data ?? null
  createdLater.value = later?.data.meta?.total ?? 0
}

async function loadQueues() {
  if (isMerchant.value || !auth.can('orders.dispatch')) return
  await Promise.all(queues.value.map(async (q) => {
    const { data } = await http.get('/orders', { params: { queue: q.value, per_page: 1 } })
    q.count = data.meta.total
  }))
}

async function loadRecent() {
  recent.value = (await http.get('/orders', { params: { per_page: 8 } })).data.data
}

function refresh() {
  if (!isMerchant.value && auth.can('orders.dispatch')) dispatchCounts.fetchCounts().catch(() => {})
  loadSummary()
  loadQueues()
  loadRecent()
}

watch(orderChanges, refresh)
onMounted(refresh)
</script>
