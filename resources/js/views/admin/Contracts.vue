<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Contrats</h1>
      <button v-if="canManage" class="btn-primary" @click="picking = true">+ Nouveau contrat</button>
    </div>

    <p class="text-sm text-gray-600">
      Chaque client sous contrat paie un forfait par mois pour un nombre de courses. Au-delà, chaque course est facturée au
      prix de sa destination : prix du contrat à Abidjan, tarif de la ville à l'intérieur (Distribution › Tarifs).
      La facture du mois se calcule le 1er (Distribution › Factures).
    </p>

    <div class="flex flex-wrap items-center gap-2">
      <div class="flex rounded-lg bg-slate-100 p-1 text-sm" role="group" aria-label="État des contrats">
        <button
          v-for="s in STATES" :key="s.value" type="button"
          :class="['rounded-md px-3 py-1.5', state === s.value ? 'bg-white shadow-sm font-medium' : 'text-slate-600']"
          :aria-pressed="state === s.value" @click="state = s.value; load()"
        >
          {{ s.label }}
        </button>
      </div>
      <select v-if="activities.length > 1" v-model="activity" class="input w-auto" aria-label="Activité" @change="load">
        <option value="">Distribution et MAD</option>
        <option v-for="a in activities" :key="a.value" :value="a.value">{{ a.label }}</option>
      </select>
      <input v-model="search" class="input w-full sm:w-56" placeholder="Rechercher un client…" @input="debouncedLoad">
    </div>

    <div v-if="state === 'current' && plans.length" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <StatCard title="Contrats en cours" :value="String(plans.length)" icon="📑" />
      <StatCard title="Forfaits du mois" :value="money(plans.reduce((s, p) => s + (p.monthly_fee || 0), 0))" icon="💼" />
      <StatCard title="Courses en plus" :value="String(plans.reduce((s, p) => s + (p.usage?.over || 0), 0))" icon="➕" />
      <StatCard v-if="plans.some((p) => p.usage?.estimate !== null && p.usage?.estimate !== undefined)" title="Factures estimées" :value="money(plans.reduce((s, p) => s + (p.usage?.estimate || 0), 0))" icon="📄" />
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm stack-table">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Client</th><th class="p-2">Contrat</th><th class="p-2">Période</th><th class="p-2">Ce mois-ci</th><th class="p-2 text-right">Facture estimée</th><th /></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="p in plans" :key="p.id">
            <td class="p-2">
              <span class="font-medium">{{ p.merchant?.business_name }}</span>
              <span :class="['ml-1 rounded-full px-2 py-0.5 text-xs font-medium', activityBadge(p.activity)]">{{ activityShort(p.activity) }}</span>
            </td>
            <td data-label="Contrat" class="p-2 max-w-sm">
              <div class="font-medium">{{ p.formula_label }}</div>
              <div class="text-xs text-gray-500">{{ p.summary }}</div>
              <div v-if="p.has_forfait" class="text-xs text-gray-500">Zones : {{ p.zones?.length ? (p.zones.length > 5 ? `${p.zones.slice(0, 5).map((z) => z.name).join(', ')}… (${p.zones.length})` : p.zones.map((z) => z.name).join(', ')) : 'communes « Forfait »' }}</div>
              <div v-if="p.couriers?.length" class="text-xs text-gray-500">Livreurs : {{ p.couriers.map((c) => c.name).join(', ') }}</div>
            </td>
            <td data-label="Période" class="p-2 whitespace-nowrap">
              du {{ date(p.starts_on) }}<template v-if="p.ends_on"><br>au {{ date(p.ends_on) }}</template>
              <div v-if="p.state !== 'current'" class="text-xs text-gray-500">{{ p.state === 'upcoming' ? 'À venir' : 'Terminé' }}</div>
            </td>
            <td data-label="Ce mois-ci" class="p-2 min-w-40">
              <template v-if="p.usage && p.usage.included !== null">
                <div class="flex justify-between text-xs">
                  <span><strong>{{ p.usage.used }}</strong> / {{ p.usage.included }} courses</span>
                  <span :class="p.usage.percent >= 100 ? 'text-red-600 font-semibold' : p.usage.percent >= 80 ? 'text-amber-700' : 'text-gray-500'">{{ p.usage.percent }} %</span>
                </div>
                <div class="mt-1 h-2 rounded bg-slate-100 overflow-hidden">
                  <div class="h-2" :class="p.usage.percent >= 100 ? 'bg-red-500' : p.usage.percent >= 80 ? 'bg-amber-500' : 'bg-emerald-500'" :style="{ width: `${Math.min(100, p.usage.percent)}%` }" />
                </div>
                <div v-if="p.usage.over" class="text-xs text-gray-600 mt-0.5">{{ p.usage.over }} en plus du forfait</div>
                <div v-if="p.usage.supplements" class="text-xs text-gray-600">Suppléments : {{ money(p.usage.supplements) }}</div>
              </template>
              <span v-else-if="p.usage" class="text-xs">{{ p.usage.orders }} course(s)</span>
              <span v-else class="text-gray-400">—</span>
            </td>
            <td data-label="Facture estimée" class="p-2 text-right font-semibold">{{ p.usage?.estimate !== null && p.usage?.estimate !== undefined ? money(p.usage.estimate) : '—' }}</td>
            <td class="p-2 text-right whitespace-nowrap">
              <button class="text-blue-600 mr-3" @click="open = p.merchant">{{ canManage ? 'Modifier' : 'Voir' }}</button>
              <RouterLink :to="{ path: '/admin/courses', query: { merchant_id: p.merchant?.id, activity: p.activity, day: 'all' } }" class="text-blue-600 mr-3">Courses</RouterLink>
              <button v-if="p.state === 'current'" type="button" class="text-blue-600 mr-3" @click="supplementFor = p">Suppléments</button>
              <RouterLink v-if="canManage" :to="{ path: '/admin/formules', query: { client: p.merchant?.id } }" class="text-blue-600 mr-3">Prix négociés</RouterLink>
              <RouterLink v-if="auth.can('invoices.view')" to="/admin/factures" class="text-blue-600">Factures</RouterLink>
            </td>
          </tr>
          <tr v-if="!plans.length">
            <td colspan="6" class="p-6 text-center text-gray-500">
              {{ loading ? 'Chargement…' : state === 'current' ? 'Aucun contrat en cours.' : 'Aucun contrat.' }}
              <button v-if="!loading && canManage && state === 'current'" class="text-blue-600 ml-1" @click="picking = true">Créer le premier contrat</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Nouveau contrat : d'abord le client -->
    <Modal :open="picking" title="Nouveau contrat · choisir le client" @close="picking = false">
      <div class="space-y-3">
        <input v-model="clientSearch" class="input" placeholder="Nom du client…" autofocus @input="searchClients">
        <ul class="divide-y rounded border max-h-80 overflow-y-auto">
          <li v-for="m in clients" :key="m.id">
            <button type="button" class="w-full text-left p-2 hover:bg-slate-50" @click="choose(m)">
              <span class="font-medium">{{ m.business_name }}</span>
              <span class="text-xs text-gray-500 ml-1">{{ m.phone }}</span>
              <span v-for="b in m.billing_plans || []" :key="b.id" class="ml-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700">{{ b.formula_label }}</span>
            </button>
          </li>
          <li v-if="!clients.length" class="p-3 text-sm text-gray-500">Aucun client trouvé. Créez-le d'abord dans Clients.</li>
        </ul>
      </div>
    </Modal>

    <BillingPlanModal :open="!!open" :merchant="open" @close="open = null" @saved="load" />
    <SupplementsModal :open="!!supplementFor" :merchant="supplementFor?.merchant" :activity="supplementFor?.activity" @close="supplementFor = null" @saved="load" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '../../bootstrap/axios'
import BillingPlanModal from '../../components/BillingPlanModal.vue'
import SupplementsModal from '../../components/SupplementsModal.vue'
import Modal from '../../components/Modal.vue'
import StatCard from '../../components/StatCard.vue'
import { useAuthStore } from '../../stores/auth'
import { activityBadge, activityShort, myActivities } from '../../utils/activities'
import { date, money } from '../../utils/format'

const auth = useAuthStore()
const route = useRoute()
const canManage = computed(() => auth.can('rates.manage'))
const STATES = [
  { value: 'current', label: 'En cours' },
  { value: 'upcoming', label: 'À venir' },
  { value: 'ended', label: 'Terminés' },
  { value: 'all', label: 'Tous' },
]
// Activités facturées au mois de son périmètre
const activities = computed(() => myActivities(auth.user).filter((a) => a.value !== 'ecommerce'))
const activity = ref(['distribution', 'mad'].includes(route.query.activity) ? route.query.activity : '')
const state = ref('current')
const search = ref('')
const plans = ref([])
const loading = ref(false)
// Client dont on ouvre le contrat
const open = ref(null)
// Contrat dont on gère les suppléments du mois
const supplementFor = ref(null)
const picking = ref(false)
const clientSearch = ref('')
const clients = ref([])

async function load() {
  loading.value = true
  try {
    plans.value = (await http.get('/billing-plans', { params: { state: state.value, activity: activity.value || undefined, search: search.value || undefined } })).data.data
  } finally {
    loading.value = false
  }
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
}

let clientTimer
function searchClients() {
  clearTimeout(clientTimer)
  clientTimer = setTimeout(async () => {
    clients.value = (await http.get('/merchants', { params: { search: clientSearch.value || undefined, status: 'active', per_page: 30 } })).data.data
  }, 250)
}

function choose(merchant) {
  picking.value = false
  open.value = merchant
}

onMounted(() => {
  load()
  searchClients()
})
</script>
