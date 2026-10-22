<template>
  <div class="space-y-3">
    <div class="relative">
      <input v-model="search" type="search" class="m-input pl-11" placeholder="Nom, téléphone ou n° de course" aria-label="Rechercher une course" @input="debouncedLoad">
      <svg viewBox="0 0 24 24" class="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" stroke-linecap="round" /></svg>
    </div>

    <!-- Période : aujourd'hui par défaut, comme sur l'accueil ; seules les courses de ces dates s'affichent -->
    <PeriodPicker :model-value="period" @update:model-value="setPeriod" />

    <!-- Catégories, avec le nombre de courses de chacune (sur la période) -->
    <div class="flex gap-2 overflow-x-auto no-scrollbar -mx-4 px-4 pb-1" role="radiogroup" aria-label="Catégorie">
      <button
        v-for="f in filters"
        :key="f.value"
        type="button"
        role="radio"
        :aria-checked="filter === f.value"
        :class="['tap shrink-0 rounded-full px-4 py-2 text-sm font-medium ring-1', filter === f.value ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']"
        :style="filter === f.value ? { backgroundColor: 'var(--app-color)' } : {}"
        @click="setFilter(f.value)"
      >
        {{ f.label }}
        <span
          v-if="counts[f.value] !== undefined"
          :class="['ml-1 rounded-full px-1.5 text-xs', filter === f.value ? 'bg-white/25' : f.warn && counts[f.value] ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600']"
        >{{ counts[f.value] }}</span>
      </button>
    </div>

    <!-- Courses de la période choisie -->
    <section class="space-y-2">
      <OrderCard v-for="o in orders" :key="o.id" :order="o" :to="`/marchand/courses/${o.id}`">
        <template v-if="needsDecision(o)" #actions>
          <a :href="telLink(o.recipient.phone)" class="tap flex-1 rounded-xl py-2.5 text-center text-sm font-semibold ring-1 ring-slate-200 active:bg-slate-50">📞 Appeler</a>
          <RouterLink v-if="o.status === 'delivery_failed'" :to="`/marchand/courses/${o.id}?action=reporter`" class="tap flex-1 rounded-xl py-2.5 text-center text-sm font-semibold ring-1 ring-slate-200 active:bg-slate-50">📅 Reporter</RouterLink>
          <RouterLink :to="`/marchand/courses/${o.id}`" class="tap flex-1 rounded-xl py-2.5 text-center text-sm font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }">Décider</RouterLink>
        </template>
      </OrderCard>
    </section>

    <EmptyState v-if="!loading && !orders.length" icon="🔎" :title="search ? 'Aucune course trouvée' : emptyTitle" :text="search ? 'Essayez un autre nom, numéro ou n° de course.' : emptyText">
      <RouterLink v-if="!search && filter === 'all' && auth.can('orders.create')" to="/marchand/courses/nouvelle" class="m-btn-primary">+ Nouvelle course</RouterLink>
    </EmptyState>
    <p v-if="loading" class="text-center text-slate-400 py-6">Chargement…</p>
    <button v-if="hasMore && !loading" class="m-btn-secondary" @click="load(page + 1)">Voir plus</button>

  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cachedGet } from '../../composables/useCachedApi'
import OrderCard from '../../components/mobile/OrderCard.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { orderChanges } from '../../composables/useRealtime'
import { useAuthStore } from '../../stores/auth'
import { telLink } from '../../utils/format'
import { needsDecision } from '../../utils/merchantStatus'
import PeriodPicker from '../../components/mobile/PeriodPicker.vue'
import { ORDER_FILTERS, filterParams } from '../../utils/orderFilters'
import { periodFromQuery, periodQuery } from '../../utils/periods'

const auth = useAuthStore()

// En cours, reports compris (décision prise, nouvelle livraison prévue)
const filters = ORDER_FILTERS

const EMPTY = {
  all: ['Aucune course sur ces dates', 'Choisissez d\'autres dates.'],
  active: ['Aucune course en cours', 'Toutes vos courses sont terminées.'],
  problems: ['Rien à régler', 'Aucun colis n\'attend votre décision. 👍'],
  delivered: ['Aucune course livrée', 'Les courses livrées apparaîtront ici.'],
  other: ['Aucune course retournée ou annulée', ''],
}

const route = useRoute()
const router = useRouter()
// Anciennes adresses (?filtre=active…) toujours valables
const filter = ref(filters.some((f) => f.value === route.query.filtre) ? route.query.filtre : 'all')
const period = ref(periodFromQuery(route.query, 'today'))
const search = ref('')
const orders = ref([])
const counts = reactive({})
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)

const emptyTitle = computed(() => EMPTY[filter.value][0])
const emptyText = computed(() => EMPTY[filter.value][1])

async function load(p = 1) {
  loading.value = true
  const current = filters.find((f) => f.value === filter.value)
  try {
    const { data } = await cachedGet('/orders', {
      // Une recherche porte sur toutes les dates : on retrouve une course sans connaître son jour
      params: { page: p, per_page: 20, ...filterParams(current, search.value ? null : period.value), search: search.value || undefined },
    })
    orders.value = p === 1 ? data.data : [...orders.value, ...data.data]
    page.value = p
    hasMore.value = data.meta.current_page < data.meta.last_page
  } catch {
    // Hors ligne sans cache pour ce filtre : liste inchangée
  } finally {
    loading.value = false
  }
}

// Nombre de courses de chaque catégorie (une page d'une ligne suffit : le total est dans « meta »)
async function loadCounts() {
  await Promise.all(filters.map(async (f) => {
    try {
      const { data } = await cachedGet('/orders', { params: { per_page: 1, ...filterParams(f, period.value) } })
      counts[f.value] = data.meta?.total ?? 0
    } catch {
      // Compteur facultatif
    }
  }))
}

// Catégorie et période gardées dans l'adresse (retour depuis une fiche)
function syncQuery() {
  router.replace({ query: { ...(filter.value === 'all' ? {} : { filtre: filter.value }), ...periodQuery(period.value, 'today') } })
}

function setFilter(value) {
  filter.value = value
  syncQuery()
  load()
}

function setPeriod(value) {
  period.value = value
  syncQuery()
  load()
  loadCounts()
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(() => load(), 300)
}

watch(orderChanges, () => {
  load()
  loadCounts()
})
onMounted(() => {
  load()
  loadCounts()
})
</script>
