<template>
  <!-- Poste de pilotage (équipe du dispatch) : état du jour, ce qu'il faut régler, livreurs à surveiller, parcours du jour -->
  <div class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <div>
        <h1 class="text-2xl font-bold">Poste de pilotage <span class="font-medium text-slate-600">· {{ todayLong }}</span></h1>
        <p v-if="updatedAt" class="text-sm text-slate-600">mis à jour à {{ updatedAt }}</p>
      </div>
      <!-- Activité regardée (équipe qui en voit plusieurs) : toute la page la suit -->
      <div v-if="activityChoices.length > 1" class="flex flex-wrap gap-2 text-sm" role="group" aria-label="Activité">
        <button
          v-for="a in [{ value: '', label: 'Toutes les activités' }, ...activityChoices]" :key="a.value || 'all'" type="button"
          :class="['min-h-9 rounded-full px-3 font-semibold ring-1', activity === a.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
          :aria-pressed="activity === a.value" @click="activity = a.value; refresh()"
        >
          {{ a.label }}
        </button>
      </div>
    </div>

    <!-- Conseil : rouvrir ou fermer la journée selon les livreurs libres -->
    <DayAdvice :advice="advice" @changed="refresh" />

    <!-- 1. État du jour : une seule ligne -->
    <section class="card flex flex-wrap items-center gap-x-7 gap-y-4 px-5 py-4" aria-label="État du jour">
      <div v-if="deliveryDay?.rules" class="min-w-[11rem]">
        <p class="flex items-center gap-1.5 font-semibold"><Icone :nom="deliveryDay.closed ? 'jour-ferme' : 'jour-ouvert'" :class="deliveryDay.closed ? 'text-attention' : 'text-succes'" />{{ deliveryDay.closed ? 'Journée fermée' : 'Journée ouverte' }}</p>
        <p class="text-sm text-slate-600">
          <template v-if="deliveryDay.closed">nouvelles courses pour le prochain jour</template>
          <template v-else-if="deliveryDay.cutoff_time">après {{ deliveryDay.cutoff_time }}, les courses partent le lendemain</template>
          <template v-else>validation automatique</template>
        </p>
      </div>

      <div class="min-w-[10rem] flex-1">
        <p class="text-sm text-slate-600">Livrées</p>
        <p class="text-2xl font-bold tabular-nums">
          {{ report?.counts.delivered ?? '—' }}
          <span v-if="report?.counts.planned" class="text-base font-medium text-slate-600">/ {{ report.counts.planned }} · {{ rate }} %</span>
        </p>
        <div v-if="report?.counts.planned" class="mt-1.5 h-1.5 rounded-full bg-slate-200" role="progressbar" :aria-valuenow="rate" aria-valuemin="0" aria-valuemax="100" aria-label="Courses livrées">
          <div class="h-1.5 rounded-full bg-emerald-700" :style="{ width: `${Math.min(rate, 100)}%` }" />
        </div>
      </div>

      <div v-if="couriers?.totals.estimated_end" class="min-w-[8rem]">
        <p class="text-sm text-slate-600">Fin estimée</p>
        <p class="text-2xl font-bold tabular-nums">{{ hour(couriers.totals.estimated_end) }}</p>
        <p v-if="lateEnd && couriers.depot_closes_at" class="text-sm font-medium text-amber-800">{{ lateEnd }} livreur{{ lateEnd > 1 ? 's' : '' }} après {{ couriers.depot_closes_at }}</p>
      </div>

      <!-- Argent : e-commerce seulement -->
      <div v-if="report?.money" class="min-w-[11rem]">
        <p class="text-sm text-slate-600">Encaissé chez les destinataires</p>
        <p class="text-2xl font-bold tabular-nums">{{ money(report.money.collected) }}</p>
        <p v-if="report.money.with_couriers" class="text-sm font-medium text-amber-800">
          dont {{ money(report.money.with_couriers) }} chez {{ report.money.couriers_holding }} livreur{{ report.money.couriers_holding > 1 ? 's' : '' }}
        </p>
      </div>

      <!-- Distribution : plis, colis et chèques remis -->
      <div v-if="report?.distribution?.units_planned" class="min-w-[7rem]">
        <p class="text-sm text-slate-600">Plis remis</p>
        <p class="text-2xl font-bold tabular-nums">{{ report.distribution.units_delivered }} <span class="text-base font-medium text-slate-600">/ {{ report.distribution.units_planned }}</span></p>
        <p v-if="report.distribution.missing_receipts" class="text-sm font-medium text-amber-800">{{ report.distribution.missing_receipts }} sans accusé</p>
      </div>

      <div v-if="couriers" class="min-w-[7rem]">
        <p class="text-sm text-slate-600">Livreurs actifs</p>
        <p class="text-2xl font-bold tabular-nums">{{ couriers.totals.active }} <span class="text-base font-medium text-slate-600">/ {{ couriers.totals.couriers }}</span></p>
      </div>

      <button v-if="report" type="button" class="ml-auto text-sm font-medium text-blue-700 hover:underline" @click="share">Envoyer le point sur WhatsApp</button>
    </section>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
      <!-- 2. À régler : une seule file, de la plus urgente à la moins urgente -->
      <section class="card overflow-hidden" aria-labelledby="a-regler">
        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-200 px-5 py-3.5">
          <h2 id="a-regler" class="text-lg font-semibold">À régler <span v-if="todos.length" class="font-medium text-slate-600">· {{ todos.length }}</span></h2>
          <span class="flex flex-wrap items-center gap-3 text-sm text-slate-600">
            <span>du plus urgent au moins urgent</span>
            <RouterLink v-if="auth.can('settings.manage')" to="/admin/reglages/courses#tableau-de-bord" class="inline-flex items-center gap-1 hover:underline"><Icone nom="reglages" taille="petite" />Lignes affichées</RouterLink>
          </span>
        </div>
        <p v-if="!counts" class="px-5 py-6 text-slate-600">Chargement…</p>
        <p v-else-if="!todos.length" class="px-5 py-6 font-medium text-emerald-700"><Icone nom="valider" taille="petite" class="inline align-text-bottom" /> Tout est réglé pour le moment.</p>
        <ul v-else class="divide-y divide-slate-100">
          <li v-for="t in todos" :key="t.key" :class="['flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3', t.level === 'urgent' ? 'bg-red-50/50' : '']">
            <span :class="['inline-flex w-[6.5rem] shrink-0 items-center justify-center rounded-full py-0.5 text-xs font-semibold ring-1', LEVELS[t.level].class, 'gap-1']"><Icone v-if="LEVELS[t.level].icon" :nom="LEVELS[t.level].icon" taille="petite" />{{ LEVELS[t.level].label }}</span>
            <span class="w-9 text-right text-xl font-bold tabular-nums">{{ t.count }}</span>
            <div class="min-w-[12rem] flex-1">
              <p class="font-semibold">{{ t.title }}</p>
              <p v-if="t.sub" class="text-sm text-slate-600">{{ t.sub }}</p>
            </div>
            <RouterLink :to="t.to" class="btn-secondary">{{ t.action }}</RouterLink>
          </li>
        </ul>
      </section>

      <!-- Livreurs à surveiller -->
      <section class="card overflow-hidden" aria-labelledby="a-surveiller">
        <div class="flex items-baseline justify-between gap-2 border-b border-slate-200 px-5 py-3.5">
          <h2 id="a-surveiller" class="text-lg font-semibold">Livreurs à surveiller</h2>
          <span class="flex gap-3 text-sm">
            <RouterLink to="/admin/activite/mural" class="inline-flex items-center gap-1 text-slate-600 hover:underline"><Icone nom="ecran" taille="petite" />Écran mural</RouterLink>
            <RouterLink to="/admin/activite" class="font-medium text-blue-700 hover:underline">Activité des livreurs</RouterLink>
          </span>
        </div>
        <p v-if="!couriers" class="px-5 py-6 text-slate-600">Chargement…</p>
        <p v-else-if="!watched.length" class="px-5 py-6 text-slate-600">Rien à signaler sur les livreurs.</p>
        <ul v-else class="divide-y divide-slate-100">
          <li v-for="c in watched.slice(0, 6)" :key="c.id">
            <RouterLink :to="{ path: '/admin/activite', query: { livreur: c.id } }" class="block space-y-1.5 px-5 py-3 hover:bg-slate-50">
              <div class="flex items-baseline justify-between gap-2">
                <span class="font-semibold">
                  {{ c.name }}
                  <span v-if="c.mad" class="ml-1 rounded-full bg-violet-100 px-1.5 text-xs font-semibold text-violet-900">MAD · {{ c.mad.merchant }}</span>
                </span>
                <span v-if="c.deliveries.total" class="text-sm tabular-nums text-slate-600">{{ c.deliveries.done }} / {{ c.deliveries.total }} livrées</span>
              </div>
              <div v-if="c.deliveries.total" class="h-1.5 rounded-full bg-slate-200">
                <div class="h-1.5 rounded-full bg-amber-600" :style="{ width: `${Math.round(100 * c.deliveries.done / c.deliveries.total)}%` }" />
              </div>
              <div class="flex flex-wrap gap-1.5">
                <span v-for="f in c.flags" :key="f" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200">{{ f }}</span>
              </div>
            </RouterLink>
          </li>
        </ul>
        <p v-if="couriers && couriers.data.length > watched.length" class="border-t border-slate-100 px-5 py-3 text-sm text-slate-600">
          {{ couriers.data.length - watched.length }} autre{{ couriers.data.length - watched.length > 1 ? 's' : '' }} livreur{{ couriers.data.length - watched.length > 1 ? 's' : '' }} : rien à signaler.
        </p>
      </section>
    </div>

    <!-- 3. Parcours du jour : un clic ouvre la file dans Courses -->
    <section class="card space-y-3 px-5 py-4" aria-labelledby="parcours">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="parcours" class="text-lg font-semibold">Parcours du jour</h2>
        <span class="text-sm text-slate-600">un clic ouvre les courses de l'étape</span>
      </div>
      <div class="grid grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-2">
        <RouterLink
          v-for="s in steps" :key="s.label" :to="s.to"
          :class="['flex min-h-[4.5rem] flex-col justify-center rounded-xl border border-slate-300 px-3.5 py-2 hover:border-slate-500 hover:bg-slate-50', s.outcome ? 'bg-slate-50' : '']"
        >
          <span class="text-sm font-medium text-slate-600">{{ s.label }}</span>
          <span :class="['text-2xl font-bold tabular-nums', s.tone]">{{ s.count }}</span>
          <span v-if="s.late" class="mt-0.5 self-start rounded-full bg-red-600 px-2 text-xs font-semibold text-white">{{ s.late }} attente longue</span>
        </RouterLink>
      </div>
    </section>

    <!-- Point détaillé du jour, ou d'une autre période (hier, 7 jours, ce mois…) -->
    <details class="card px-5 py-4" :open="choice !== 'today'" @toggle="pointOpen = $event.target.open">
      <summary class="cursor-pointer font-semibold">Point détaillé, et autres périodes</summary>
      <div class="mt-3 space-y-3">
        <div class="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="Période du point">
          <button
            v-for="p in PERIODS" :key="p.value" type="button"
            :class="['min-h-9 rounded-full px-3 ring-1', choice === p.value ? 'bg-slate-900 font-semibold text-white ring-slate-900' : 'bg-white ring-slate-300 hover:bg-slate-50']"
            :aria-pressed="choice === p.value" @click="choice = p.value"
          >
            {{ p.label }}
          </button>
          <template v-if="choice === 'other'">
            <input v-model="custom.from" type="date" class="input w-auto py-1" aria-label="Du">
            <input v-model="custom.to" type="date" class="input w-auto py-1" aria-label="Au">
          </template>
        </div>
        <DayPoint v-if="pointOpen || choice !== 'today'" :from="range.from" :to="range.to" :activity="activity" :refresh="version" detailed @delivered="router.push({ path: '/admin/courses', query: scope() })" />
      </div>
    </details>

    <!-- Dernières courses -->
    <section class="card space-y-2 px-5 py-4">
      <div class="flex items-center justify-between">
        <h2 class="flex items-center gap-1.5 font-semibold"><Icone nom="historique" />Dernières courses</h2>
        <RouterLink to="/admin/courses" class="text-sm text-blue-600">Tout voir</RouterLink>
      </div>
      <p v-if="!recent.length" class="text-sm text-slate-600">Aucune course pour le moment.</p>
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

    <!-- Guide vidéo « Bien démarrer » (administrateurs ; réglable dans Réglages › Entreprise), après l'essentiel -->
    <GuideVideosCard v-if="auth.can('settings.manage')" />
  </div>
</template>

<script setup>
import Icone from './ui/Icone.vue'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import http from '../bootstrap/axios'
import DayAdvice from './DayAdvice.vue'
import DayPoint from './DayPoint.vue'
import StatusBadge from './StatusBadge.vue'
import GuideVideosCard from './guide/GuideVideosCard.vue'
import { useAuthStore } from '../stores/auth'
import { useFieldReportStore } from '../stores/fieldReports'
import { useHeldParcelStore } from '../stores/heldParcels'
import { orderListChanges } from '../composables/useRealtime'
import { money, waitDuration } from '../utils/format'
import { myActivities } from '../utils/activities'

/**
 * Poste de pilotage de l'équipe (repris de Jibiat, complété pour Logistica) : la page se lit de haut en bas.
 * 1. État du jour en une ligne ; 2. une seule file « À régler », de la plus urgente à la moins urgente (courses,
 * retours, messages, puis contrats de distribution et de MAD), à côté des livreurs à surveiller ; 3. le parcours du
 * jour, chaque étape ouvrant sa file dans Courses. Les lignes de la file se masquent dans Réglages › Courses.
 */
const auth = useAuthStore()
const router = useRouter()
const fieldReports = useFieldReportStore()
const heldParcels = useHeldParcelStore()
const iso = (d) => d.toLocaleDateString('sv-SE')
const todayIso = iso(new Date())
const shift = (days) => { const d = new Date(); d.setDate(d.getDate() + days); return iso(d) }
const todayLong = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
const hour = (value) => new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', ' h ')
const dayName = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })
const monthName = (ym) => new Date(`${ym}-01T12:00:00`).toLocaleDateString('fr-FR', { month: 'long' })
const s = (n) => (n > 1 ? 's' : '')

const activityChoices = computed(() => myActivities(auth.user))
const activity = ref('')
const scope = () => (activity.value ? { activity: activity.value } : {})

const counts = ref(null)
const todo = ref(null)
const report = ref(null)
const couriers = ref(null)
const deliveryDay = ref(null)
const advice = ref(null)
const returns = ref(null)
const inbox = ref(0)
const contracts = ref(null)
const recent = ref([])
const updatedAt = ref('')
const version = ref(0)

const day = computed(() => counts.value?.day || {})
const ex = computed(() => counts.value?.exceptions || {})
const waiting = computed(() => counts.value?.waiting || {})
const hidden = computed(() => todo.value?.hidden_cards || [])
const rate = computed(() => (report.value?.counts.planned ? Math.round((report.value.counts.delivered / report.value.counts.planned) * 100) : 0))
const watched = computed(() => (couriers.value?.data || []).filter((c) => c.flags?.length))
const lateEnd = computed(() => (couriers.value?.data || []).filter((c) => c.late_end).length)
const queue = (q) => ({ path: '/admin/courses', query: { queue: q, ...scope() } })
const late = (onglet) => ({ path: '/admin/courses/en-retard', query: { onglet, ...scope() } })

const LEVELS = {
  urgent: { label: 'Urgent', icon: 'urgent', class: 'bg-red-50 text-red-700 ring-red-200' },
  faire: { label: 'Aujourd\'hui', class: 'bg-amber-50 text-amber-800 ring-amber-200' },
  voir: { label: 'À suivre', class: 'bg-slate-100 text-slate-700 ring-slate-300' },
}

// La file « À régler » : seulement ce qui a un nombre, dans l'ordre de gravité. « card » : ligne masquable (Réglages).
const todos = computed(() => {
  const c = counts.value
  if (!c) return []
  const oldest = Math.max(waiting.value.pickup?.oldest_minutes || 0, waiting.value.delivery?.oldest_minutes || 0)
  const cutoffCount = c.cutoff?.passed ? c.cutoff.count : 0
  const r = returns.value
  const lots = todo.value?.lots_due || []
  const mad = todo.value?.mad_uncovered || []
  const inv = todo.value?.invoices_to_issue
  const toIssue = inv ? inv.drafts + inv.missing : 0
  const k = contracts.value
  return [
    { key: 'late', card: 'unassigned', level: 'urgent', count: c.late?.total || 0, title: 'Sans livreur depuis trop longtemps', sub: oldest ? `la plus ancienne attend depuis ${waitDuration(oldest)}` : '', action: 'Assigner', to: queue('unassigned') },
    { key: 'cutoff', card: 'cutoff', level: 'urgent', count: cutoffCount, title: `Courses du jour toujours sans livreur après ${c.cutoff?.time || ''}`, sub: 'risque de ne pas être livrées aujourd\'hui', action: 'Assigner', to: queue('unassigned') },
    { key: 'reports', level: 'urgent', count: fieldReports.badge || 0, title: 'Remontées terrain à traiter', sub: 'signalées par les livreurs', action: 'Traiter', to: '/admin/activite/remontees' },
    { key: 'internal_late', card: 'internal_late', level: 'urgent', count: ex.value.internal_late || 0, title: 'Délai interne dépassé', sub: 'courses de distribution ou de MAD à livrer en priorité', action: 'Voir', to: queue('internal_late') },
    { key: 'mad_uncovered', card: 'mad_uncovered', level: 'urgent', count: mad.length, title: 'Contrats MAD sans livreur', sub: mad.map((m) => m.merchant).filter(Boolean).slice(0, 3).join(', '), action: 'Désigner', to: { path: '/admin/contrats', query: { activity: 'mad' } } },
    { key: 'code_to_verify', card: 'code_to_verify', level: 'urgent', count: ex.value.code_to_verify || 0, title: 'Codes de livraison à vérifier', sub: 'livrées hors connexion avec un code faux', action: 'Vérifier', to: queue('code_to_verify') },
    { key: 'to_confirm', card: 'to_confirm', level: 'faire', count: day.value.to_confirm || 0, title: 'Courses à valider', sub: 'reçues des clients', action: 'Valider', to: queue('to_confirm') },
    { key: 'to_approve', card: 'to_approve', level: 'faire', count: ex.value.to_approve || 0, title: 'Demandes à approuver', sub: 'en attente du chef de service du client', action: 'Voir', to: queue('to_approve') },
    { key: 'picked_late', card: 'untreated', level: 'faire', count: ex.value.picked_late || 0, title: 'Ramassées, date de livraison dépassée', sub: 'à renvoyer au client ou à reporter', action: 'Décider', to: late('a-decider') },
    { key: 'to_reschedule', card: 'untreated', level: 'faire', count: c.to_reschedule || 0, title: 'Jamais ramassées, date dépassée', sub: 'à reporter ou à annuler', action: 'Reporter', to: late('pas-ramassees') },
    { key: 'failed', card: 'failed', level: 'faire', count: day.value.failed || 0, title: 'Échecs de livraison', sub: 'relivrer, retourner ou remettre en stock', action: 'Décider', to: queue('failed') },
    { key: 'returns', card: 'to_return', level: 'faire', count: r?.to_assign || 0, title: 'Colis à rendre, pas encore sur un bon', sub: [r?.merchants ? `chez ${r.merchants} client${s(r.merchants)}` : '', r?.oldest_days ? `le plus ancien attend depuis ${r.oldest_days} jour${s(r.oldest_days)}` : ''].filter(Boolean).join(' · '), action: 'Faire un bon', to: '/admin/retours' },
    { key: 'inbox', level: 'faire', count: inbox.value, title: 'Messages non lus', sub: 'écrits au WhatsApp de l\'entreprise', action: 'Répondre', to: { path: '/admin/messages', query: { filtre: 'unread' } } },
    { key: 'to_prepare', card: 'to_prepare', level: 'faire', count: day.value.to_prepare || 0, title: 'Commandes à préparer à l\'entrepôt', sub: '', action: 'Préparer', to: queue('to_prepare') },
    { key: 'on_hold', card: 'on_hold', level: 'voir', count: ex.value.on_hold || 0, title: 'Courses en attente', sub: 'mises de côté par l\'équipe', action: 'Voir', to: queue('on_hold') },
    { key: 'follow_up', card: 'follow_up', level: 'voir', count: ex.value.follow_up || 0, title: 'Courses en suivi', sub: 'avec une note de suivi', action: 'Voir', to: queue('follow_up') },
    { key: 'held', level: 'voir', count: heldParcels.counts.overdue || 0, title: 'Colis gardés trop longtemps par un livreur', sub: 'à faire rapporter ou livrer', action: 'Voir', to: '/admin/activite/colis' },
    { key: 'lots_due', card: 'lots_due', level: 'voir', count: lots.length, title: 'Lots à échéance proche', sub: lots.slice(0, 2).map((l) => `${l.name} : ${l.delivered}/${l.included}, ${dayName(l.due_on)}`).join(' · '), action: 'Voir les contrats', to: { path: '/admin/contrats', query: { activity: 'distribution' } } },
    { key: 'late_lots', level: 'voir', count: k?.lateLots.length || 0, title: 'Lots en retard', sub: (k?.lateLots || []).slice(0, 2).map((p) => `${p.merchant?.business_name} : ${p.usage.late} pli(s)`).join(' · '), action: 'Voir les contrats', to: { path: '/admin/contrats', query: { activity: 'distribution' } } },
    { key: 'forfaits', level: 'voir', count: k?.forfaits.length || 0, title: 'Forfaits bientôt épuisés', sub: (k?.forfaits || []).slice(0, 3).map((p) => `${p.merchant?.business_name} à ${p.usage.percent} %`).join(', '), action: 'Voir les contrats', to: '/admin/contrats' },
    { key: 'invoices_to_issue', card: 'invoices_to_issue', level: 'voir', count: toIssue, title: 'Factures à émettre', sub: inv ? [inv.drafts && `${inv.drafts} brouillon${s(inv.drafts)} à envoyer`, inv.missing && `${inv.missing} client(s) de ${monthName(inv.period)} pas encore calculé(s)`].filter(Boolean).join(' · ') : '', action: 'Émettre', to: '/admin/factures' },
    { key: 'invoices_overdue', level: 'voir', count: k?.invoices?.overdue ? 1 : 0, title: 'Factures en retard de paiement', sub: k?.invoices?.overdue ? money(k.invoices.overdue) : '', action: 'Voir', to: '/admin/factures' },
  ].filter((t) => t.count > 0 && !(t.card && hidden.value.includes(t.card)))
})

// Le parcours du colis, mêmes étapes que la page Courses : validation, ramassage, livraison, puis issues du jour
const steps = computed(() => {
  const d = day.value
  const l = counts.value?.late || {}
  const list = [
    { label: 'À valider', count: d.to_confirm || 0, to: queue('to_confirm') },
    { label: 'Sans ramasseur', count: d.pickup_unassigned || 0, late: Math.min(l.pickup || 0, d.pickup_unassigned || 0), to: queue('pickup_unassigned') },
    { label: 'À ramasser', count: d.to_pickup || 0, to: queue('to_pickup') },
  ]
  if (d.to_prepare) list.push({ label: 'À préparer', count: d.to_prepare, to: queue('to_prepare') })
  list.push(
    { label: 'Sans livreur', count: d.delivery_unassigned || 0, late: Math.min(l.delivery || 0, d.delivery_unassigned || 0), to: queue('delivery_unassigned') },
    { label: 'À livrer', count: d.delivery_assigned || 0, to: queue('delivery_assigned') },
    { label: 'En chemin', count: d.on_the_way || 0, tone: d.on_the_way ? 'text-blue-700' : '', to: queue('on_the_way') },
    { label: 'Livrées', count: d.delivered || 0, tone: 'text-emerald-700', to: queue('delivered'), outcome: true },
    { label: 'Échecs', count: d.failed || 0, tone: d.failed ? 'text-red-700' : '', to: queue('failed'), outcome: true },
    { label: 'À retourner', count: d.to_return || 0, to: '/admin/retours', outcome: true },
  )
  return list
})

// Point détaillé : aujourd'hui (replié) ou une autre période
const PERIODS = [
  { value: 'today', label: 'Aujourd\'hui' },
  { value: 'yesterday', label: 'Hier' },
  { value: 'week', label: '7 jours' },
  { value: 'month', label: 'Ce mois' },
  { value: 'other', label: 'Autre…' },
]
const choice = ref('today')
const pointOpen = ref(false)
const custom = reactive({ from: todayIso, to: todayIso })
const range = computed(() => ({
  today: { from: todayIso, to: todayIso },
  yesterday: { from: shift(-1), to: shift(-1) },
  week: { from: shift(-6), to: todayIso },
  month: { from: `${todayIso.slice(0, 8)}01`, to: todayIso },
  other: { from: custom.from, to: custom.to < custom.from ? custom.from : custom.to },
}[choice.value]))

function share() {
  const c = report.value.counts
  const m = report.value.share_amounts ? report.value.money : null
  const d = report.value.distribution
  const lines = [`Point du ${new Date().toLocaleDateString('fr-FR')}`, `Livrées : ${c.delivered}${c.planned ? ` / ${c.planned} courses` : ''}`]
  if (d?.units_planned) lines.push(`Plis remis : ${d.units_delivered} / ${d.units_planned}`)
  if (m) lines.push(`Recette livraison : ${money(m.fees)}`, `Encaissé chez les destinataires : ${money(m.collected)} (caisse ${money(m.at_desk)}, livreurs ${money(m.with_couriers)})`, `À reverser : ${money(m.net_to_merchants)}`)
  window.open(`https://wa.me/?text=${encodeURIComponent(lines.join('\n'))}`, '_blank', 'noopener')
}

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
  const get = (url, p = {}) => http.get(url, { params: { ...params, ...p } }).then((r) => r.data).catch(() => null)
  const [c, t, r, a, d, ret, box, last, adv] = await Promise.all([
    get('/orders/counts', { day: todayIso }),
    get('/reports/todo'),
    get('/reports/day', { day: todayIso }),
    get('/couriers/activity'),
    http.get('/delivery-day').then((x) => x.data).catch(() => null),
    http.get('/return-slips/count').then((x) => x.data).catch(() => null),
    http.get('/inbox/summary').then((x) => x.data).catch(() => null),
    get('/orders', { per_page: 5 }),
    http.get('/delivery-day/advice').then((x) => x.data).catch(() => null),
  ])
  counts.value = c?.data ?? counts.value
  todo.value = t?.data ?? todo.value
  report.value = r?.data ?? report.value
  couriers.value = a ?? couriers.value
  deliveryDay.value = d?.data ?? deliveryDay.value
  returns.value = ret?.data ?? returns.value
  inbox.value = box?.data?.unread ?? inbox.value
  recent.value = last?.data ?? recent.value
  advice.value = adv?.data ?? null
  updatedAt.value = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', ' h ')
  loadContracts()
}

// Rafraîchi quand une course change, et chaque minute quand l'onglet est visible
let timer
watch(orderListChanges, refresh)
onMounted(() => {
  refresh()
  timer = setInterval(() => document.visibilityState === 'visible' && refresh(), 60000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
