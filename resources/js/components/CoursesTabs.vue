<template>
  <!-- Courses : quatre cartes (Courses, Activité des livreurs, Retours clients, Courses en retard), celle ouverte allumée -->
  <nav v-if="cards.length > 1" class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="Courses">
    <RouterLink
      v-for="c in cards" :key="c.to" :to="c.to"
      :class="['card flex flex-col gap-1.5 p-3 sm:p-4 transition', c.active ? 'border-[var(--admin-color,#0f172a)] ring-2 ring-[var(--admin-color,#0f172a)]' : 'hover:border-slate-400']"
      :aria-current="c.active ? 'page' : undefined"
    >
      <span class="flex items-center gap-2">
        <Icone :nom="c.icon" />
        <span class="flex-1 font-bold leading-tight">{{ c.label }}</span>
        <span v-if="c.badge" class="grid h-6 min-w-6 place-items-center rounded-full bg-red-600 px-1.5 text-xs font-bold text-white">{{ c.badge > 99 ? '99+' : c.badge }}</span>
      </span>
      <!-- Chiffre mis en avant (Courses en retard : à décider) -->
      <span v-if="c.highlight" :class="['rounded-xl px-3 py-2', c.highlight.number ? 'bg-red-600 text-white' : 'bg-slate-100 text-slate-700']">
        <span class="block text-2xl font-extrabold tabular-nums">{{ c.highlight.number }} <span class="text-sm font-semibold">{{ c.highlight.unit }}</span></span>
        <span class="block text-xs font-semibold sm:text-sm">{{ c.highlight.label }}</span>
      </span>
      <span v-else :class="['text-2xl font-extrabold tabular-nums sm:text-3xl', c.numberClass]">{{ c.number ?? '—' }} <span class="text-sm font-semibold text-slate-600">{{ c.unit }}</span></span>
      <span v-if="c.progress !== undefined" class="h-2 rounded-full bg-slate-200"><span class="block h-2 rounded-full bg-emerald-600" :style="{ width: `${c.progress}%` }" /></span>
      <span v-for="(l, i) in c.lines" :key="i" class="hidden text-sm text-slate-700 sm:block">{{ l }}</span>
      <span v-if="c.alert" :class="['mt-auto rounded-lg px-2 py-1 text-xs font-semibold sm:text-sm', c.alertClass]">{{ c.alert }}</span>
    </RouterLink>
  </nav>
</template>

<script setup>
import Icone from './ui/Icone.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import http from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { useDispatchCountStore } from '../stores/dispatchCounts'
import { useFieldReportStore } from '../stores/fieldReports'
import { useHeldParcelStore } from '../stores/heldParcels'
import { myActivities } from '../utils/activities'
import { ACTIVITY_TABS } from '../utils/courierActivity'
import { collectsOnly, money } from '../utils/format'

/**
 * Cartes de Courses (reprises de Jibiat) : chacune résume son écran (chiffres du jour pour Courses et Activité,
 * toutes dates pour les retours et les retards) ; la pastille rouge signale ce qui demande une action. Chaque
 * carte suit les droits du compte ; les chiffres demandent le droit de dispatch.
 */
const route = useRoute()
const auth = useAuthStore()
const counts = useDispatchCountStore()
const fieldReports = useFieldReportStore()
const heldParcels = useHeldParcelStore()
const returns = ref(null)
const activity = ref(null)
// Hors de la liste des courses (ou « Toutes les dates ») : compteurs d'aujourd'hui, chargés ici ;
// liste filtrée sur une activité : compteurs du jour affiché, toutes activités
const own = ref(null)

const todayIso = new Date().toLocaleDateString('sv-SE')
const plural = (n, one, many) => `${n} ${n > 1 ? many : one}`
const hour = (v) => new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', ' h ')
const isDispatch = computed(() => auth.can('orders.dispatch'))
const mine = computed(() => myActivities(auth.user).map((a) => a.value))
// Distribution et MAD seulement : on collecte, on ne ramasse pas
const picked = computed(() => (collectsOnly(mine.value) ? 'collecté' : 'ramassé'))

const cards = computed(() => {
  // Cartes de navigation : toutes activités (liste filtrée sur une activité : leurs propres compteurs)
  const shared = counts.day && !counts.activity
  const general = shared ? counts.counts : (own.value || counts.counts)
  const day = shared ? counts.dayCounts : own.value?.day
  const isToday = !day || day.date === todayIso
  const all = day?.all ?? null
  const delivered = day?.delivered ?? 0
  const toAssign = (day?.pickup_unassigned ?? 0) + (day?.delivery_unassigned ?? 0)
  const toConfirm = day?.to_confirm ?? 0
  const rows = activity.value?.data || []
  const mission = rows.filter((c) => c.state === 'mission').length
  const free = rows.filter((c) => c.state === 'available').length
  const cash = rows.reduce((s, c) => s + (c.cash_in_hand || 0), 0)
  const end = activity.value?.totals?.estimated_end
  const r = returns.value
  const late = general.to_reschedule || 0
  const toDecide = general.exceptions?.picked_late || 0
  const watch = fieldReports.badge + heldParcels.counts.overdue
  const s = (n) => (n > 1 ? 's' : '')

  return [
    {
      to: '/admin/courses', label: 'Courses', icon: 'course', permission: 'orders.view', active: route.path === '/admin/courses',
      number: all, unit: isToday ? 'du jour' : `le ${new Date(`${day.date}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })}`,
      progress: all ? Math.round((100 * delivered) / all) : 0,
      lines: [`${delivered} livrée${s(delivered)} · ${day?.on_the_way ?? 0} en chemin`],
      alert: [toAssign ? `${toAssign} à assigner` : '', toConfirm ? `${toConfirm} à valider` : ''].filter(Boolean).join(' · '),
      alertClass: 'bg-red-50 text-red-800', badge: toAssign + toConfirm,
    },
    {
      to: '/admin/activite', label: 'Activité des livreurs', icon: 'livreur', permission: ACTIVITY_TABS.map((t) => t.permission), active: route.path.startsWith('/admin/activite'),
      number: activity.value ? mission : null, unit: 'en mission', numberClass: 'text-blue-700',
      lines: activity.value
        ? [`${plural(free, 'libre', 'libres')} maintenant${end ? ` · fin estimée ${hour(end)}` : ''}`, ...(mine.value.includes('ecommerce') ? [`${money(cash)} chez les livreurs`] : [])]
        : [],
      alert: [
        fieldReports.badge ? plural(fieldReports.badge, 'remontée à traiter', 'remontées à traiter') : '',
        heldParcels.counts.overdue ? plural(heldParcels.counts.overdue, 'colis gardé trop longtemps', 'colis gardés trop longtemps') : '',
      ].filter(Boolean).join(' · '),
      alertClass: 'bg-amber-50 text-amber-900', badge: watch,
    },
    {
      to: '/admin/retours', label: 'Retours clients', icon: 'retour', permission: 'orders.dispatch', active: route.path === '/admin/retours',
      number: r?.to_return ?? null, unit: 'colis à rendre',
      lines: r ? [`${r.to_assign || 0} à mettre sur un bon · ${r.assigned || 0} confié${s(r.assigned || 0)} à un livreur`, ...(r.merchants ? [`chez ${plural(r.merchants, 'client', 'clients')}`] : [])] : [],
      alert: r?.oldest_days ? `Le plus ancien attend depuis ${plural(r.oldest_days, 'jour', 'jours')}` : '',
      alertClass: 'bg-amber-50 text-amber-900', badge: r?.to_assign || 0,
    },
    {
      to: '/admin/courses/en-retard', label: 'Courses en retard', icon: 'retard', permission: 'orders.dispatch', active: route.path === '/admin/courses/en-retard',
      highlight: { number: toDecide, unit: 'à décider', label: `${picked.value[0].toUpperCase()}${picked.value.slice(1)}s, date de livraison dépassée` },
      lines: [`${late} jamais ${picked.value}${s(late)} · à reporter`],
      badge: late + toDecide,
    },
  ].filter((c) => auth.can(c.permission))
})

async function load() {
  if (!isDispatch.value) return
  const [r, a, o] = await Promise.allSettled([
    http.get('/return-slips/count'),
    http.get('/couriers/activity'),
    // Liste des courses sur un jour : ses compteurs (déjà chargés par la liste) ; sinon ceux d'aujourd'hui
    counts.day && !counts.activity ? Promise.resolve(null) : http.get('/orders/counts', { params: { day: counts.day || todayIso } }),
    fieldReports.fetchCounts(),
    heldParcels.fetchCounts(),
  ])
  if (r.status === 'fulfilled') returns.value = r.value.data.data
  if (a.status === 'fulfilled') activity.value = a.value.data
  if (o.status === 'fulfilled' && o.value) own.value = o.value.data.data
}

let timer
onMounted(() => {
  load()
  timer = setInterval(() => !document.hidden && load(), 60000)
})
onBeforeUnmount(() => clearInterval(timer))
// Liste filtrée sur une activité, ou autre jour choisi : les cartes rechargent leurs compteurs toutes activités
watch(() => [counts.activity, counts.day], async () => {
  if (!isDispatch.value || !counts.activity) return
  const { data } = await http.get('/orders/counts', { params: { day: counts.day || todayIso } }).catch(() => ({ data: null }))
  if (data) own.value = data.data
})
</script>
