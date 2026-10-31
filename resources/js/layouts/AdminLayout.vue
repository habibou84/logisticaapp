<template>
  <div class="flex h-screen bg-gray-100">
    <aside
      :class="[
        'fixed md:relative z-40 w-64 h-full text-white transition-all duration-300 flex flex-col print:hidden',
        sidebarOpen ? 'left-0' : '-left-64 md:left-0'
      ]"
      :style="{ backgroundColor: spaceColor('admin') }"
    >
      <div class="p-4 text-lg font-bold border-b border-white/15 flex items-center gap-2 min-w-0">
        <img :src="branding.logo_url || '/brand/anneau.svg'" alt="" class="h-8 w-8 object-contain shrink-0 rounded bg-white p-0.5">
        <span class="truncate">{{ auth.user?.company?.name || 'Administration' }}</span>
      </div>
      <nav class="p-3 space-y-1 flex-1 overflow-y-auto" @click="sidebarOpen = false">
        <RouterLink
          v-if="auth.can('orders.create')"
          to="/admin/courses/nouvelle"
          class="mb-3 flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 font-semibold hover:bg-emerald-500"
        >
          ➕ Nouvelle course
        </RouterLink>
        <SidebarItem v-for="item in menu.top" :key="item.to" :icon="item.icon" :label="item.label" :to="item.to" :badge="item.badge || 0" />
        <SidebarGroup
          v-for="group in menu.groups"
          :id="group.id"
          :key="group.id"
          :label="group.label"
          :items="group.items"
          :default-open="!group.closed"
        />
      </nav>
      <!-- Téléphone : qui est connecté et déconnexion (l'en-tête n'a plus la place) -->
      <div class="md:hidden border-t border-white/15 p-3 flex items-center justify-between gap-2">
        <span class="min-w-0 text-sm">
          <span class="block truncate font-medium">{{ auth.user?.name }}</span>
          <span class="block truncate text-white/60">{{ auth.user?.role_label }}</span>
        </span>
        <button class="shrink-0 rounded-md bg-white/10 px-3 py-2 text-sm font-medium hover:bg-white/20" @click="logout">Déconnexion</button>
      </div>
    </aside>

    <div v-if="sidebarOpen" class="fixed inset-0 bg-black/40 z-30 md:hidden" @click="sidebarOpen = false" />

    <div class="flex-1 flex flex-col min-w-0">
      <header class="flex items-center justify-between bg-white shadow px-4 py-2 gap-2 print:hidden">
        <div class="flex items-center gap-2">
          <button class="md:hidden p-2 rounded bg-[var(--admin-color,#0f172a)] text-white" aria-label="Menu" @click="sidebarOpen = true">☰</button>
          <button
            v-if="auth.can('orders.view')"
            class="md:hidden p-2 rounded hover:bg-black/5 text-lg"
            aria-label="Rechercher une course"
            :aria-expanded="searchOpen"
            @click="searchOpen = !searchOpen"
          >🔍</button>
          <form class="hidden md:block" @submit.prevent="quickSearch">
            <input v-model="search" type="search" placeholder="Code, téléphone…" class="input w-64" aria-label="Rechercher une course">
          </form>
        </div>
        <div class="flex items-center gap-3">
          <AlertSoundToggle v-if="isDispatch" />
          <NotificationBell />
          <span class="hidden md:inline text-sm text-gray-600">
            {{ auth.user?.name }} <span class="text-gray-400">· {{ auth.user?.role_label }}</span>
          </span>
          <button class="btn-danger hidden md:inline-flex" @click="logout">Déconnexion</button>
        </div>
      </header>
      <!-- Téléphone : recherche de course sous l'en-tête -->
      <form v-if="searchOpen" class="md:hidden bg-white border-b px-4 py-2 flex gap-2 print:hidden" @submit.prevent="quickSearch">
        <input ref="searchInput" v-model="search" type="search" placeholder="Code, téléphone, nom…" class="input" aria-label="Rechercher une course">
        <button class="btn-primary shrink-0">Chercher</button>
      </form>

      <!-- Mise en route : étapes essentielles qui restent (tableau de bord) -->
      <RouterLink
        v-if="setupBanner"
        to="/admin/mise-en-route"
        class="flex flex-wrap items-center justify-between gap-2 bg-amber-50 px-4 py-2 text-sm text-amber-900 print:hidden"
      >
        <span>
          🧭 Mise en route : {{ setup.essentials.done }} étape(s) essentielle(s) sur {{ setup.essentials.total }}.
          <template v-if="setupNext">Prochaine : {{ setupNext.title }}.</template>
        </span>
        <span class="font-medium underline">Continuer la mise en route</span>
      </RouterLink>

      <!-- Réglage ouvert depuis la mise en route : y revenir en un geste -->
      <div v-if="setupReturn" class="flex items-center justify-between gap-2 bg-slate-800 px-4 py-2 text-sm text-white print:hidden">
        <RouterLink to="/admin/mise-en-route" class="font-medium underline">← Revenir à la mise en route</RouterLink>
        <button type="button" class="rounded px-2 hover:bg-white/20" aria-label="Fermer" @click="closeSetupReturn">✕</button>
      </div>

      <main :class="['flex-1 overflow-y-auto p-4 md:p-6 print:overflow-visible', tabs.length ? 'pb-20 md:pb-6' : '']">
        <RouterView />
      </main>
    </div>

    <!-- Téléphone : nouvelle course depuis n'importe quel écran -->
    <RouterLink
      v-if="auth.can('orders.create') && route.path !== '/admin/courses/nouvelle' && !route.path.startsWith('/admin/reglages')"
      to="/admin/courses/nouvelle"
      class="md:hidden fixed right-4 bottom-20 z-20 grid h-14 w-14 place-items-center rounded-full bg-emerald-600 text-3xl leading-none text-white shadow-lg print:hidden"
      aria-label="Nouvelle course"
    >+</RouterLink>

    <!-- Téléphone : barre d'onglets ; les écrans les plus utilisés à un toucher -->
    <nav v-if="tabs.length" class="md:hidden fixed inset-x-0 bottom-0 z-30 border-t bg-white pb-safe print:hidden" aria-label="Navigation principale">
      <div class="grid" :style="{ gridTemplateColumns: `repeat(${tabs.length + 1}, minmax(0, 1fr))` }">
        <RouterLink
          v-for="t in tabs"
          :key="t.to"
          :to="t.to"
          :class="['tap relative flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium', isActive(t) ? 'text-slate-900' : 'text-slate-500']"
          :aria-current="isActive(t) ? 'page' : undefined"
        >
          <span class="text-xl leading-none" aria-hidden="true">{{ t.icon }}</span>
          <span class="truncate max-w-full">{{ t.label }}</span>
          <span v-if="t.badge" class="absolute top-1 left-1/2 ml-2 min-w-5 h-5 px-1 rounded-full bg-red-600 text-white text-[10px] grid place-items-center">{{ t.badge > 99 ? '99+' : t.badge }}</span>
          <span v-if="isActive(t)" class="absolute inset-x-4 top-0 h-0.5 rounded-full bg-[var(--admin-color,#0f172a)]" aria-hidden="true" />
        </RouterLink>
        <button class="tap flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium text-slate-500" aria-label="Menu complet" @click="sidebarOpen = true">
          <span class="text-xl leading-none" aria-hidden="true">☰</span>
          <span>Menu</span>
        </button>
      </div>
    </nav>
    <Toasts />
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, watchEffect } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { useRealtime } from '../composables/useRealtime'
import SidebarGroup from '../components/SidebarGroup.vue'
import SidebarItem from '../components/SidebarItem.vue'
import NotificationBell from '../components/NotificationBell.vue'
import Toasts from '../components/Toasts.vue'
import AlertSoundToggle from '../components/AlertSoundToggle.vue'
import { useFieldReportStore } from '../stores/fieldReports'
import { useHeldParcelStore } from '../stores/heldParcels'
import { useDispatchCountStore } from '../stores/dispatchCounts'
import { branding, loadBranding, spaceColor } from '../composables/useBranding'
import { myActivities } from '../utils/activities'
import { filterMenu } from '../utils/adminMenu'

const sidebarOpen = ref(false)
const search = ref('')
const searchOpen = ref(false)
const searchInput = ref(null)
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

watch(searchOpen, async (open) => {
  if (open) { await nextTick(); searchInput.value?.focus() }
})

useRealtime()
loadBranding()

// Boutons principaux aux couleurs de l'espace équipe (Paramètres › Apparence)
watchEffect(() => document.documentElement.style.setProperty('--admin-color', spaceColor('admin')))
onBeforeUnmount(() => document.documentElement.style.removeProperty('--admin-color'))

// Remontées terrain à traiter : badge du menu, rafraîchi chaque minute
// Colis restés trop longtemps chez un livreur : badge du menu
const fieldReports = useFieldReportStore()
// Véhicules : échéances dépassées et véhicules immobilisés (badge du menu)
const vehicleUrgent = ref(0)
async function loadVehicleCounts() {
  if (!auth.can('fleet.manage')) return
  vehicleUrgent.value = (await http.get('/vehicles/counts').catch(() => null))?.data.data.urgent || 0
}
const heldParcels = useHeldParcelStore()
// Courses qui attendent un livreur au-delà du délai : badge du menu « Courses »
const dispatchCounts = useDispatchCountStore()
const isDispatch = computed(() => auth.can('orders.dispatch'))

let countsTimer
function fetchCounts() {
  if (auth.can('field_events.manage')) fieldReports.fetchCounts().catch(() => {})
  loadVehicleCounts()
  if (!isDispatch.value) return
  heldParcels.fetchCounts().catch(() => {})
  dispatchCounts.fetchCounts().catch(() => {})
}
// Mise en route (administrateurs) : bandeau du tableau de bord, ouverture à la première connexion, retour au parcours
const setup = ref(null)
const setupNext = computed(() => setup.value?.steps.find((s) => s.essential && s.status !== 'done'))
const setupBanner = computed(() => setup.value && !setup.value.ready && route.path === '/admin')
const RETURN_KEY = 'setup-return'
const setupReturnFlag = ref(sessionFlag())
const setupReturn = computed(() => setupReturnFlag.value && route.path !== '/admin/mise-en-route')

function sessionFlag(value) {
  try {
    if (value === undefined) return sessionStorage.getItem(RETURN_KEY) === '1'
    if (value) sessionStorage.setItem(RETURN_KEY, '1')
    else sessionStorage.removeItem(RETURN_KEY)
  } catch {
    // Stockage indisponible : la barre ne suit que cette page
  }
  return value
}
function closeSetupReturn() {
  setupReturnFlag.value = false
  sessionFlag(false)
}

async function loadSetup() {
  if (!auth.can('settings.manage') || !auth.user?.company_id) return
  setup.value = (await http.get('/setup').catch(() => null))?.data?.data ?? null
  // Première connexion d'un administrateur, mise en route pas faite : on l'ouvre
  if (setup.value && !setup.value.seen && !setup.value.ready && route.path !== '/admin/mise-en-route') router.replace('/admin/mise-en-route')
}

watch(() => route.fullPath, (to, from) => {
  if (route.query.depuis === 'mise-en-route') {
    setupReturnFlag.value = true
    sessionFlag(true)
  } else if (route.path === '/admin/mise-en-route') {
    closeSetupReturn()
  }
  // Retour au tableau de bord : progression à jour
  if (route.path === '/admin' && from) loadSetup()
}, { immediate: true })

onMounted(() => {
  loadSetup()
  if (!auth.can(['orders.dispatch', 'fleet.manage', 'field_events.manage'])) return
  fetchCounts()
  countsTimer = setInterval(fetchCounts, 60000)
})
onBeforeUnmount(() => clearInterval(countsTimer))

// Menu en rubriques (utils/adminMenu) ; entrée propre à certaines activités : cachée aux comptes limités à d'autres
const menu = computed(() => filterMenu((p) => auth.can(p), myActivities(auth.user).map((a) => a.value), {
  late: isDispatch.value ? dispatchCounts.counts.late.total : 0,
  fieldReports: fieldReports.badge,
  heldParcels: heldParcels.counts.overdue,
  vehicles: vehicleUrgent.value,
  health: (setup.value?.health || []).filter((h) => h.level === 'alert').length,
}))

// Barre d'onglets du téléphone : dispatch (courses, carte, alertes) ou, sans dispatch, les écrans de ses droits
const tabs = computed(() => {
  const home = { to: '/admin', icon: '🏠', label: 'Accueil', exact: true }
  const candidates = isDispatch.value
    ? [
        { to: '/admin/courses', icon: '📦', label: 'Courses', badge: dispatchCounts.counts.late.total },
        { to: '/admin/activite', icon: '🛵', label: 'Livreurs', badge: fieldReports.badge },
      ].filter((t) => !t.permission || auth.can(t.permission))
    : [
        { to: '/admin/courses', icon: '📦', label: 'Courses', permission: 'orders.view' },
        { to: '/admin/caisse', icon: '💰', label: 'Caisse', permission: 'finance.view' },
        { to: '/admin/marchands', icon: '🏪', label: 'Clients', permission: 'merchants.view' },
        { to: '/admin/statistiques', icon: '📊', label: 'Stats', permission: 'orders.view' },
      ].filter((t) => auth.can(t.permission))
  return [home, ...candidates.slice(0, 3)]
})
const isActive = (t) => (t.exact ? route.path === t.to : route.path === t.to || route.path.startsWith(`${t.to}/`))

function quickSearch() {
  router.push({ path: '/admin/courses', query: { search: search.value } })
  search.value = ''
  searchOpen.value = false
}

const logout = async () => {
  await auth.logout()
  router.push('/admin/connexion')
}
</script>
