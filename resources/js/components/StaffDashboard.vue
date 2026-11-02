<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Tableau de bord</h1>
      <div class="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="Période du point">
        <button
          v-for="p in PERIODS" :key="p.value" type="button"
          :class="['rounded-full px-3 py-1 ring-1', choice === p.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
          :aria-pressed="choice === p.value" @click="choice = p.value"
        >
          {{ p.label }}
        </button>
        <template v-if="choice === 'other'">
          <input v-model="custom.from" type="date" class="input w-auto py-1" aria-label="Du">
          <input v-model="custom.to" type="date" class="input w-auto py-1" aria-label="Au">
        </template>
      </div>
    </div>

    <!-- Activité regardée (équipe qui en voit plusieurs) : à faire, point et courses la suivent -->
    <div v-if="activityChoices.length > 1" class="flex flex-wrap gap-2 text-sm" role="group" aria-label="Activité">
      <button
        v-for="a in [{ value: '', label: 'Toutes' }, ...activityChoices]" :key="a.value || 'all'" type="button"
        :class="['rounded-full px-3 py-1 ring-1', activity === a.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
        :aria-pressed="activity === a.value" @click="activity = a.value; refresh()"
      >
        {{ a.label }}
      </button>
    </div>

    <!-- À faire maintenant : un clic ouvre le bon onglet ou la bonne page -->
    <section class="card space-y-3 p-3 sm:p-4" aria-label="À faire maintenant">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold">⚡ À faire maintenant</h2>
        <span class="flex flex-wrap items-center gap-3 text-xs text-gray-500">
          <span>un clic ouvre l'onglet</span>
          <RouterLink v-if="auth.can('settings.manage')" to="/admin/reglages/courses#tableau-de-bord" class="hover:underline">⚙️ Cartes affichées</RouterLink>
        </span>
      </div>
      <RouterLink
        v-if="shown('cutoff') && cutoff?.passed && cutoff.count" :to="tab('unassigned')"
        class="block rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700"
      >
        🕒 Il est plus de {{ cutoff.time }} : {{ cutoff.count }} course{{ cutoff.count > 1 ? 's' : '' }} du jour
        {{ cutoff.count > 1 ? 'n\'ont' : 'n\'a' }} toujours pas de livreur.
      </RouterLink>

      <div v-if="mainCards.length" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <RouterLink v-for="card in mainCards" :key="card.key" :to="card.to" :class="['rounded-xl border p-3 hover:shadow', card.tone]">
          <p class="text-xs">{{ card.title }}</p>
          <p class="text-2xl font-bold">
            {{ card.value }}
            <span v-if="card.badge" class="rounded-full bg-red-600 px-2 py-0.5 align-middle text-xs font-semibold text-white">{{ card.badge }}</span>
          </p>
          <p class="text-xs">{{ card.text }}</p>
        </RouterLink>
      </div>

      <div v-if="chips.length" class="flex flex-wrap gap-2 text-sm">
        <RouterLink v-for="t in chips" :key="t.key" :to="t.to" :class="['rounded-full px-3 py-1 ring-1', t.class]">
          {{ t.icon }} {{ t.count }} {{ t.count > 1 ? t.plural : t.label }}
        </RouterLink>
      </div>
      <p v-if="!mainCards.some((c) => c.urgent) && !chips.length" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">✓ Rien d'urgent : toutes les courses du jour suivent leur cours.</p>
    </section>

    <!-- Point du jour ou de la période -->
    <DayPoint :from="range.from" :to="range.to" :activity="activity" :refresh="version" detailed @delivered="router.push({ path: '/admin/courses', query: activity ? { activity } : {} })" @loaded="report = $event" />

    <div class="grid gap-4 lg:grid-cols-2">
      <!-- Livreurs de la période -->
      <section class="card space-y-2 p-3 sm:p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-semibold">🛵 Livreurs {{ range.from === range.to ? 'du jour' : 'de la période' }}</h2>
          <span class="flex gap-3 text-sm">
            <RouterLink to="/admin/activite/mural" class="text-gray-500 hover:underline">📺 Écran mural</RouterLink>
            <RouterLink to="/admin/activite" class="text-blue-600">Activité des livreurs</RouterLink>
          </span>
        </div>
        <p v-if="!report?.couriers?.length" class="text-sm text-gray-500">Aucune livraison sur la période.</p>
        <ul v-else class="divide-y text-sm">
          <li v-for="c in report.couriers" :key="c.id" class="flex flex-wrap items-center justify-between gap-x-3 py-1.5">
            <span class="font-medium">
              {{ c.name || 'Livreur' }}
              <span v-if="c.mad_client" class="ml-1 rounded-full bg-violet-100 px-1.5 text-xs font-semibold text-violet-900">MAD · {{ c.mad_client }}</span>
            </span>
            <span class="text-gray-600">
              {{ c.delivered }} livrée{{ c.delivered > 1 ? 's' : '' }}<template v-if="c.units > c.delivered"> ({{ c.units }} plis)</template><template v-if="c.in_hand"> · 🎒 {{ c.in_hand }} en main</template><template v-if="c.collected !== null && c.collected"> · {{ money(c.collected) }}</template>
            </span>
          </li>
        </ul>
      </section>

      <!-- Dernières courses -->
      <section class="card space-y-2 p-3 sm:p-4">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold">🕘 Dernières courses</h2>
          <RouterLink to="/admin/courses" class="text-sm text-blue-600">Tout voir</RouterLink>
        </div>
        <p v-if="!recent.length" class="text-sm text-gray-500">Aucune course pour le moment.</p>
        <ul v-else class="divide-y text-sm">
          <li v-for="o in recent" :key="o.id">
            <RouterLink :to="`/admin/courses/${o.id}`" class="flex items-center gap-3 py-1.5 hover:bg-slate-50">
              <span class="font-mono text-xs">{{ o.tracking_code }}</span>
              <span class="min-w-0 flex-1 truncate">{{ o.recipient.name || o.recipient.phone }} · {{ o.delivery.zone_name }}</span>
              <StatusBadge :status="o.status" :label="o.status_label" />
            </RouterLink>
          </li>
        </ul>
      </section>
    </div>

    <!-- Guide vidéo « Bien démarrer » (administrateurs ; réglable dans Réglages › Entreprise), après l'essentiel -->
    <GuideVideosCard v-if="auth.can('settings.manage')" />

    <!-- Distribution et MAD : les contrats qui demandent une action -->
    <section v-if="contracts && (contracts.forfaits.length || contracts.lateLots.length || contracts.invoices?.overdue)" class="card space-y-2 p-3 sm:p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold">📑 Contrats à suivre</h2>
        <RouterLink to="/admin/contrats" class="text-sm text-blue-600">Tous les contrats</RouterLink>
      </div>
      <ul class="divide-y text-sm">
        <li v-for="p in contracts.forfaits" :key="`f${p.id}`" class="flex flex-wrap justify-between gap-2 py-1.5">
          <span>{{ p.merchant?.business_name }} · {{ p.formula_label }}</span>
          <span :class="p.usage.percent >= 100 ? 'font-semibold text-red-700' : 'text-amber-700'">forfait {{ p.usage.used }} / {{ p.usage.included }} ({{ p.usage.percent }} %)</span>
        </li>
        <li v-for="p in contracts.lateLots" :key="`l${p.id}`" class="flex flex-wrap justify-between gap-2 py-1.5">
          <span>{{ p.merchant?.business_name }} · {{ p.lot_name }}</span>
          <span class="font-semibold text-red-700">{{ p.usage.late }} pli(s) en retard</span>
        </li>
        <li v-if="contracts.invoices?.overdue" class="flex flex-wrap justify-between gap-2 py-1.5">
          <RouterLink to="/admin/factures" class="hover:underline">Factures en retard de paiement</RouterLink>
          <span class="font-semibold text-red-700">{{ money(contracts.invoices.overdue) }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import http from '../bootstrap/axios'
import DayPoint from './DayPoint.vue'
import StatusBadge from './StatusBadge.vue'
import GuideVideosCard from './guide/GuideVideosCard.vue'
import { useAuthStore } from '../stores/auth'
import { orderChanges } from '../composables/useRealtime'
import { money, waitDuration } from '../utils/format'
import { myActivities } from '../utils/activities'

/**
 * Tableau de bord de l'équipe (dispatch), repris de Jibiat et complété pour Logistica : ce qu'il faut faire maintenant
 * (courses du jour, non traitées, délai interne, lots, MAD sans livreur, factures à émettre), le point du jour ou de
 * la période, les livreurs (MAD compris), les dernières courses et les contrats à suivre. Cartes réglables
 * (Réglages › Courses, « Tableau de bord »).
 */
const auth = useAuthStore()
const router = useRouter()
const iso = (d) => d.toLocaleDateString('sv-SE')
const todayIso = iso(new Date())
const shift = (days) => { const d = new Date(); d.setDate(d.getDate() + days); return iso(d) }

const PERIODS = [
  { value: 'today', label: 'Aujourd\'hui' },
  { value: 'yesterday', label: 'Hier' },
  { value: 'week', label: '7 jours' },
  { value: 'month', label: 'Ce mois' },
  { value: 'other', label: 'Autre…' },
]
const choice = ref('today')
const custom = reactive({ from: todayIso, to: todayIso })
const range = computed(() => ({
  today: { from: todayIso, to: todayIso },
  yesterday: { from: shift(-1), to: shift(-1) },
  week: { from: shift(-6), to: todayIso },
  month: { from: `${todayIso.slice(0, 8)}01`, to: todayIso },
  other: { from: custom.from, to: custom.to < custom.from ? custom.from : custom.to },
}[choice.value]))

const activityChoices = computed(() => myActivities(auth.user))
const activity = ref('')
const report = ref(null)
const recent = ref([])
const counts = ref(null)
const todo = ref(null)
const contracts = ref(null)
const oldestUntreated = ref(null)
const version = ref(0)

const day = computed(() => counts.value?.day || null)
const ex = computed(() => counts.value?.exceptions || {})
const cutoff = computed(() => counts.value?.cutoff)
const waiting = computed(() => counts.value?.waiting || {})
const hidden = computed(() => todo.value?.hidden_cards || [])
const shown = (key) => !hidden.value.includes(key)
const tab = (queue) => ({ path: '/admin/courses', query: { queue, ...(activity.value ? { activity: activity.value } : {}) } })
const dayName = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })
const plural = (n, one, many) => (n > 1 ? many : one)

// Grandes cartes : les trois de Jibiat (toujours là, en couleur s'il y a quelque chose), puis celles de Logistica s'il y en a
const mainCards = computed(() => {
  if (!counts.value) return []
  const unassigned = day.value?.unassigned || 0
  const late = Math.min(counts.value.late?.total || 0, unassigned)
  const oldest = Math.max(waiting.value.pickup?.oldest_minutes || 0, waiting.value.delivery?.oldest_minutes || 0)
  const toConfirm = day.value?.to_confirm || 0
  const untreated = ex.value.untreated || 0
  const lots = todo.value?.lots_due || []
  const mad = todo.value?.mad_uncovered || []
  const invoices = todo.value?.invoices_to_issue
  const toIssue = invoices ? invoices.drafts + invoices.missing : 0
  const cards = [
    {
      key: 'unassigned', title: '🛵 Sans livreur', value: unassigned, to: tab('unassigned'), urgent: unassigned > 0, badge: late ? `⏰ ${late} en retard` : '',
      tone: unassigned ? (late ? 'border-red-300 bg-red-50 text-red-900' : 'border-amber-300 bg-amber-50 text-amber-900') : 'border-slate-200',
      text: unassigned ? `${waiting.value.pickup?.count || 0} ramassage(s) · ${waiting.value.delivery?.count || 0} livraison(s)${oldest ? ` · la plus ancienne : ${waitDuration(oldest)}` : ''}` : '✓ Toutes les courses du jour ont un livreur',
    },
    {
      key: 'to_confirm', title: '✅ À valider', value: toConfirm, to: tab('to_confirm'), urgent: toConfirm > 0,
      tone: toConfirm ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-slate-200', text: toConfirm ? 'nouvelles courses en attente' : 'rien à valider',
    },
    {
      key: 'untreated', title: '⏳ Courses non traitées', value: untreated, to: { path: '/admin/courses/non-traitees', query: activity.value ? { activity: activity.value } : {} }, urgent: untreated > 0,
      tone: untreated ? 'border-red-300 bg-red-50 text-red-900' : 'border-slate-200',
      text: untreated ? `date dépassée${oldestUntreated.value ? ` · la plus vieille : ${dayName(oldestUntreated.value)}` : ''}` : 'tout est à jour',
    },
    ex.value.to_approve && {
      key: 'to_approve', title: '🏢 À approuver', value: ex.value.to_approve, to: tab('to_approve'), urgent: true,
      tone: 'border-amber-300 bg-amber-50 text-amber-900', text: 'demandes d\'agents en attente de leur chef de service',
    },
    ex.value.internal_late && {
      key: 'internal_late', title: '⏰ Délai interne dépassé', value: ex.value.internal_late, to: tab('internal_late'), urgent: true,
      tone: 'border-red-300 bg-red-50 text-red-900', text: 'courses de distribution ou MAD à livrer en priorité',
    },
    lots.length && {
      key: 'lots_due', title: '📦 Lots à échéance proche', value: lots.length, to: { path: '/admin/contrats', query: { activity: 'distribution' } }, urgent: true,
      tone: 'border-orange-300 bg-orange-50 text-orange-900',
      text: lots.slice(0, 2).map((l) => `${l.name} : ${l.delivered}/${l.included}, ${dayName(l.due_on)}`).join(' · ') + (lots.length > 2 ? '…' : ''),
    },
    mad.length && {
      key: 'mad_uncovered', title: '🤝 Contrats MAD sans livreur', value: mad.length, to: { path: '/admin/contrats', query: { activity: 'mad' } }, urgent: true,
      tone: 'border-red-300 bg-red-50 text-red-900', text: mad.map((m) => m.merchant).filter(Boolean).slice(0, 3).join(', ') || 'désignez les livreurs mis à disposition',
    },
    toIssue && {
      key: 'invoices_to_issue', title: '📄 Factures à émettre', value: toIssue, to: '/admin/factures', urgent: true,
      tone: 'border-indigo-300 bg-indigo-50 text-indigo-900',
      text: [invoices.drafts && `${invoices.drafts} ${plural(invoices.drafts, 'brouillon', 'brouillons')} à envoyer`, invoices.missing && `${invoices.missing} client(s) de ${monthName(invoices.period)} pas encore calculé(s)`].filter(Boolean).join(' · '),
    },
  ]
  return cards.filter((c) => c && shown(c.key))
})
const monthName = (ym) => new Date(`${ym}-01T12:00:00`).toLocaleDateString('fr-FR', { month: 'long' })

// Petites pastilles : visibles seulement s'il y a quelque chose
const chips = computed(() => [
  { key: 'to_return', icon: '↩️', label: 'à retourner', plural: 'à retourner', count: day.value?.to_return || 0, class: 'bg-rose-50 text-rose-900 ring-rose-300' },
  { key: 'failed', icon: '❌', label: 'échec', plural: 'échecs', count: day.value?.failed || 0, class: 'bg-red-50 text-red-900 ring-red-300' },
  { key: 'on_hold', icon: '⏸', label: 'en attente', plural: 'en attente', count: ex.value.on_hold || 0, class: 'bg-amber-50 text-amber-900 ring-amber-300' },
  { key: 'follow_up', icon: '📌', label: 'en suivi', plural: 'en suivi', count: ex.value.follow_up || 0, class: 'bg-sky-50 text-sky-900 ring-sky-300' },
  { key: 'to_prepare', icon: '📦', label: 'à préparer', plural: 'à préparer', count: day.value?.to_prepare || 0, class: 'bg-indigo-50 text-indigo-900 ring-indigo-300' },
  { key: 'code_to_verify', icon: '⚠️', label: 'code à vérifier', plural: 'codes à vérifier', count: ex.value.code_to_verify || 0, class: 'bg-red-50 text-red-900 ring-red-300' },
].filter((t) => t.count && shown(t.key)).map((t) => ({ ...t, to: tab(t.key) })))

const scope = () => (activity.value ? { activity: activity.value } : {})

// Contrats (distribution, MAD) : forfaits presque consommés, lots en retard, factures en retard de paiement
async function loadContracts() {
  const billed = activityChoices.value.filter((a) => a.value !== 'ecommerce').map((a) => a.value)
  if (!billed.length || !auth.can('merchants.view') || activity.value === 'ecommerce') {
    contracts.value = null
    return
  }
  const only = billed.includes(activity.value) ? activity.value : undefined
  const [plans, invoices] = await Promise.all([
    http.get('/billing-plans', { params: { state: 'current', activity: only } }).then((r) => r.data.data).catch(() => []),
    auth.can('invoices.view') ? http.get('/client-invoices', { params: { status: 'issued', activity: only } }).then((r) => r.data.totals).catch(() => null) : null,
  ])
  contracts.value = {
    forfaits: plans.filter((p) => p.has_forfait && p.usage?.included && p.usage.percent >= 80),
    lateLots: plans.filter((p) => p.formula === 'distribution_lot' && p.usage?.late),
    invoices,
  }
}

async function refresh() {
  version.value++
  const params = scope()
  const [c, t, groups, last] = await Promise.all([
    http.get('/orders/counts', { params: { day: todayIso, ...params } }).then((r) => r.data.data).catch(() => null),
    http.get('/reports/todo', { params }).then((r) => r.data.data).catch(() => null),
    http.get('/orders/untreated-groups', { params: { by: 'date', ...params } }).then((r) => r.data.data).catch(() => null),
    http.get('/orders', { params: { per_page: 5, ...params } }).then((r) => r.data.data).catch(() => []),
  ])
  counts.value = c
  todo.value = t
  oldestUntreated.value = groups?.groups?.[0]?.key || null
  recent.value = last
  loadContracts()
}

watch(orderChanges, refresh)
onMounted(refresh)
</script>
