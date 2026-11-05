<template>
  <div class="space-y-4">
    <RouterLink to="/admin/livreurs" class="text-sm text-blue-600">← Livreurs</RouterLink>
    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-if="!p && !error" class="card p-6 text-center text-gray-500">Chargement…</p>

    <template v-if="p">
      <!-- En-tête : identité, note, état, actions -->
      <div class="card flex flex-wrap items-center gap-4 p-4">
        <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-indigo-100 text-lg font-bold text-indigo-800" aria-hidden="true">{{ initials }}</span>
        <div class="min-w-0 flex-1">
          <h1 class="flex flex-wrap items-center gap-2 text-xl font-bold">
            {{ c.name }}
            <button type="button" :class="['rounded-full px-2.5 py-0.5 text-sm font-bold', scoreBadge]" title="Voir sa note" @click="tab = 'notes'">★ {{ scoreText(score) }}</button>
            <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', c.is_available ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600']">{{ c.is_available ? 'Disponible' : 'Indisponible' }}</span>
            <span v-if="!p.active" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">Compte suspendu</span>
            <MadBadge :mad="p.mad" />
            <FieldEventBadge :event="p.field_event" />
          </h1>
          <p class="text-sm text-gray-500">
            <a :href="telLink(c.phone)" class="text-blue-600">{{ c.phone }}</a>
            · {{ vehicleText }}
            · {{ c.zones.map((z) => z.name).join(', ') || 'aucune zone' }}
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <a :href="telLink(c.phone)" class="btn-secondary">📞 Appeler</a>
          <a :href="whatsappLink(c.phone)" target="_blank" rel="noopener" class="btn-secondary">💬 WhatsApp</a>
          <RouterLink :to="{ path: '/admin/courses', query: { courier_id: c.id } }" class="btn-secondary">📦 Ses courses</RouterLink>
          <RouterLink v-if="auth.can('orders.dispatch')" :to="{ path: '/admin/activite/carte', query: { livreur: c.id } }" class="btn-secondary">🗺️ Carte</RouterLink>
          <button v-if="auth.can('couriers.manage')" type="button" class="btn-primary" @click="editing = true">✏️ Modifier</button>
        </div>
      </div>

      <!-- Onglets -->
      <div class="flex gap-1 overflow-x-auto border-b" role="tablist">
        <button
          v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
          :class="['whitespace-nowrap border-b-2 px-4 py-2 text-sm font-semibold', tab === t.key ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-800']"
          @click="tab = t.key"
        >
          {{ t.label }}<span v-if="t.badge" class="ml-1 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ t.badge }}</span>
        </button>
      </div>

      <!-- Activité -->
      <section v-show="tab === 'activite'" class="space-y-4">
        <div v-if="p.today" class="card p-4">
          <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-xs font-bold uppercase tracking-wide text-gray-500">Aujourd'hui</h2>
            <span class="text-xs text-gray-500">{{ STATES[p.today.state] || p.today.state }}<template v-if="p.today.estimated_end"> · fin estimée {{ time(p.today.estimated_end) }}</template></span>
          </div>
          <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
            <div><b class="block text-xl">{{ p.today.deliveries.done }}/{{ p.today.deliveries.total }}</b>livraisons</div>
            <div><b class="block text-xl">{{ p.today.pickups.done }}/{{ p.today.pickups.total }}</b>ramassages</div>
            <div><b class="block text-xl">{{ p.today.remaining }}</b>restantes</div>
            <div><b :class="['block text-xl', p.today.failed ? 'text-red-700' : '']">{{ p.today.failed }}</b>échecs</div>
            <div><b class="block text-xl">{{ p.today.parcels_in_hand }}</b>colis en main</div>
            <div v-if="p.with_amounts"><b class="block text-xl">{{ money(p.today.cash_in_hand) }}</b>argent en main</div>
          </div>
          <p v-if="p.today.flags?.length" class="mt-3 rounded-lg bg-orange-50 px-3 py-2 text-sm text-orange-900">⚠ {{ p.today.flags.map(flagText).join(' · ') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <span class="text-sm text-gray-500">Période :</span>
          <button
            v-for="d in [7, 30, 90]" :key="d" type="button"
            :class="['rounded-full border px-3 py-1 text-sm font-semibold', days === d ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white text-slate-700']"
            @click="setDays(d)"
          >
            {{ d }} jours
          </button>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
          <div v-for="k in kpis" :key="k.label" class="card px-4 py-3">
            <p class="text-sm text-gray-500">{{ k.label }}</p>
            <p :class="['text-xl font-bold', k.cls || '']">{{ k.value }}</p>
            <p v-if="k.hint" class="text-xs text-gray-400">{{ k.hint }}</p>
          </div>
        </div>

        <div class="card p-4">
          <h2 class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500">Livraisons par jour</h2>
          <ColumnChart :labels="p.series.map((d) => d.day)" :values="p.series.map((d) => d.delivered)" label="Livraisons" aria-label="Livraisons par jour" :height="180" />
          <p v-if="failedDays" class="mt-1 text-xs text-gray-500">Échecs sur la période : {{ failedDays }} jour{{ failedDays > 1 ? 's' : '' }} avec au moins un échec.</p>
        </div>
      </section>

      <!-- Notes -->
      <section v-if="tab === 'notes'">
        <CourierRatingsPanel :courier-id="c.id" @changed="load" />
      </section>

      <!-- Terrain : pannes, accidents, empêchements de la période -->
      <section v-if="tab === 'terrain'" class="space-y-3">
        <p v-if="!field" class="card p-6 text-center text-gray-500">Chargement…</p>
        <template v-else>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="card px-4 py-3"><p class="text-sm text-gray-500">Événements</p><p class="text-xl font-bold">{{ field.totals.events }}</p><p class="text-xs text-gray-400">sur {{ days }} jours</p></div>
            <div class="card px-4 py-3"><p class="text-sm text-gray-500">Bloquants</p><p :class="['text-xl font-bold', field.totals.blocking ? 'text-red-700' : '']">{{ field.totals.blocking }}</p></div>
            <div class="card px-4 py-3"><p class="text-sm text-gray-500">Immobilisé</p><p class="text-xl font-bold">{{ field.totals.immobilised_minutes ? waitDuration(field.totals.immobilised_minutes) : '—' }}</p></div>
            <div v-if="p.with_amounts" class="card px-4 py-3"><p class="text-sm text-gray-500">Dépannages</p><p class="text-xl font-bold">{{ money(field.totals.cost) }}</p></div>
          </div>
          <div class="card divide-y">
            <RouterLink v-for="e in field.recent" :key="e.id" :to="{ path: '/admin/activite/remontees', query: { evenement: e.id } }" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm hover:bg-slate-50">
              <span>{{ e.icon }} <b>{{ e.type_label }}</b><span v-if="e.note" class="text-gray-500"> · {{ e.note }}</span></span>
              <span class="text-xs text-gray-500">{{ dateTime(e.created_at) }}<template v-if="e.immobilised_minutes"> · immobilisé {{ waitDuration(e.immobilised_minutes) }}</template><template v-if="e.status !== 'resolved'"> · <b class="text-orange-700">en cours</b></template></span>
            </RouterLink>
            <p v-if="!field.recent.length" class="p-6 text-center text-gray-500">Aucun événement terrain sur la période.</p>
          </div>
        </template>
      </section>

      <!-- Informations -->
      <section v-show="tab === 'infos'" class="grid gap-4 lg:grid-cols-2">
        <div class="card divide-y text-sm">
          <div v-for="r in infos" :key="r.label" class="flex justify-between gap-4 px-4 py-2.5">
            <span class="text-gray-500">{{ r.label }}</span>
            <span class="text-right font-medium">
              <RouterLink v-if="r.to" :to="r.to" class="text-blue-600">{{ r.value }}</RouterLink>
              <a v-else-if="r.href" :href="r.href" target="_blank" rel="noopener" class="text-blue-600">{{ r.value }}</a>
              <template v-else>{{ r.value }}</template>
            </span>
          </div>
        </div>
        <div class="space-y-4">
          <div v-if="p.vehicle || p.vehicle_warnings.length" class="card space-y-1 p-4">
            <h2 class="text-xs font-bold uppercase tracking-wide text-gray-500">Véhicule</h2>
            <p v-if="p.vehicle" class="text-sm">
              <RouterLink v-if="auth.can('fleet.manage')" :to="`/admin/vehicules/${p.vehicle.id}`" class="font-semibold text-blue-600">{{ p.vehicle.label }}</RouterLink>
              <b v-else>{{ p.vehicle.label }}</b>
            </p>
            <p v-for="w in p.vehicle_warnings" :key="w.text" :class="['text-sm font-semibold', w.level === 'alert' ? 'text-red-700' : 'text-amber-700']">{{ w.level === 'alert' ? '🚨' : '⚠️' }} {{ w.text }}</p>
          </div>
          <div class="card p-4">
            <h2 class="mb-1 text-xs font-bold uppercase tracking-wide text-gray-500">Ce qu'il fait</h2>
            <ul class="space-y-0.5 text-sm">
              <li v-for="(label, key) in CAPABILITIES" :key="key">{{ c.can?.[key] ? '✅' : '—' }} {{ label }}</li>
            </ul>
          </div>
          <div class="card p-4">
            <h2 class="mb-1 text-xs font-bold uppercase tracking-wide text-gray-500">Notes internes</h2>
            <p class="whitespace-pre-line text-sm">{{ c.notes || 'Aucune note.' }}</p>
          </div>
          <div class="card flex flex-wrap gap-3 p-4 text-sm">
            <RouterLink v-if="auth.can('finance.view')" to="/admin/caisse" class="text-blue-600">Caisse</RouterLink>
            <RouterLink v-if="auth.can('pay.manage')" to="/admin/paie-livreurs" class="text-blue-600">Paie des livreurs</RouterLink>
            <RouterLink v-if="auth.can('planning.manage')" to="/admin/activite/planning" class="text-blue-600">Planning</RouterLink>
            <RouterLink v-if="auth.can('users.view')" :to="{ path: '/admin/utilisateurs', query: { onglet: 'couriers', search: c.phone } }" class="text-blue-600">Compte et accès</RouterLink>
          </div>
        </div>
      </section>
    </template>

    <CourierForm :open="editing" :courier="c" :vehicle="p?.vehicle" @close="editing = false" @saved="load" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import CourierForm from '../../components/CourierForm.vue'
import FieldEventBadge from '../../components/FieldEventBadge.vue'
import MadBadge from '../../components/MadBadge.vue'
import CourierRatingsPanel from '../../components/ratings/CourierRatingsPanel.vue'
import ColumnChart from '../../components/stats/ColumnChart.vue'
import { useAuthStore } from '../../stores/auth'
import { date, dateTime, mapsLink, money, telLink, waitDuration, whatsappLink } from '../../utils/format'
import { ACCEPT_MODES } from './settings/options'

/** Fiche d'un livreur : aujourd'hui, chiffres de la période, note, événements terrain, informations */
const VEHICLES = { moto: 'Moto', velo: 'Vélo', voiture: 'Voiture', tricycle: 'Tricycle', pieton: 'À pied' }
const STATES = { mission: 'En mission', available: 'Disponible, sans mission', offline: 'Hors service', done: 'Journée finie', planned: 'Prévu', idle: 'Rien de prévu', mad: 'En mise à disposition' }
const CAPABILITIES = {
  collect_cash: 'Encaisse l\'argent des colis',
  mad: 'Fait la mise à disposition (MAD)',
  interior: 'Fait l\'intérieur (expéditions)',
  offline: 'Travaille hors connexion',
  see_gain: 'Voit son gain prévu',
}

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const p = ref(null)
const field = ref(null)
const error = ref('')
const editing = ref(false)
const days = ref([7, 30, 90].includes(Number(route.query.jours)) ? Number(route.query.jours) : 30)

const tabs = computed(() => [
  { key: 'activite', label: '📊 Activité' },
  { key: 'notes', label: '⭐ Notes' },
  ...(auth.can('field_events.manage') ? [{ key: 'terrain', label: '🚨 Terrain', badge: p.value?.field_event ? 1 : 0 }] : []),
  { key: 'infos', label: '🪪 Informations', badge: p.value?.vehicle_warnings?.length || 0 },
])
const tab = ref(['activite', 'notes', 'terrain', 'infos'].includes(route.query.onglet) ? route.query.onglet : 'activite')

const c = computed(() => p.value?.courier)
const score = computed(() => p.value?.score ?? null)
const initials = computed(() => (c.value?.name || '?').split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase())
const scoreText = (s) => (s === null || s === undefined ? '—' : Number(s).toFixed(1).replace('.', ','))
const scoreBadge = computed(() => {
  const s = score.value
  if (s === null || s === undefined) return 'bg-slate-100 text-slate-500'
  return s >= 4 ? 'bg-emerald-100 text-emerald-800' : s >= 3 ? 'bg-amber-100 text-amber-800' : 'bg-orange-100 text-orange-800'
})
const vehicleText = computed(() => p.value?.vehicle?.label || [VEHICLES[c.value?.vehicle_type], c.value?.vehicle_plate].filter(Boolean).join(' · ') || 'Véhicule non précisé')
const duration = (m) => (m === null || m === undefined ? '—' : m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}`)
const time = (iso) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const flagText = (f) => (typeof f === 'string' ? f : f?.label || f?.text || '')
const failedDays = computed(() => (p.value?.series || []).filter((d) => d.failed > 0).length)

const kpis = computed(() => {
  const f = p.value.figures
  const list = [
    { label: 'Livrées', value: f.delivered, hint: f.per_day ? `${String(f.per_day).replace('.', ',')} par jour travaillé` : '' },
    { label: 'Réussite', value: f.success_rate === null ? '—' : `${f.success_rate} %`, cls: f.success_rate === null ? '' : f.success_rate >= 90 ? 'text-emerald-700' : f.success_rate >= 75 ? 'text-amber-700' : 'text-red-700', hint: 'livrées sur tentées' },
    { label: 'Temps de livraison', value: duration(f.delivery_minutes), hint: 'habituel, du ramassage à la livraison' },
    { label: 'Échecs', value: f.failed, cls: f.failed ? 'text-red-700' : '' },
    { label: 'Ramassages', value: f.pickups },
    { label: 'Retours rapportés', value: f.returned },
    { label: 'Jours travaillés', value: f.days_worked, hint: `sur ${p.value.days} jours` },
  ]
  // Montants : seulement pour ceux qui voient la caisse
  if (p.value.with_amounts) {
    list.push(
      { label: 'Encaissé', value: money(f.collected || 0) },
      { label: 'Écart de caisse', value: money(f.cash_gap || 0), cls: f.cash_gap < 0 ? 'text-red-700' : '' },
      { label: 'Gains du livreur', value: money(f.earnings || 0) },
    )
  }
  return list
})

const infos = computed(() => {
  const x = c.value
  return [
    { label: 'Téléphone', value: x.phone, href: telLink(x.phone) },
    { label: 'Compte', value: p.value.active ? 'Actif' : 'Suspendu' },
    { label: 'Inscrit le', value: p.value.created_at ? date(p.value.created_at) : '—' },
    { label: 'Dernière connexion', value: p.value.last_login_at ? dateTime(p.value.last_login_at) : 'jamais' },
    { label: 'Zones principales', value: x.zones.map((z) => z.name).join(', ') || '—' },
    { label: 'Zones de secours', value: (x.backup_zones || []).map((z) => z.name).join(', ') || '—' },
    { label: 'Véhicule', value: vehicleText.value },
    ...(p.value.mad ? [{ label: 'Mise à disposition', value: `chez ${p.value.mad.merchant}` }] : []),
    ...(auth.can('pay.manage') ? [{ label: 'Plan de paie', value: x.pay_plan ? `${x.pay_plan.name}${x.pay_plan.personal ? ' (personnel)' : ''}` : 'Plan par défaut' }] : []),
    { label: 'Acceptation des missions', value: x.mission_acceptance ? (ACCEPT_MODES.find((m) => m.value === x.mission_acceptance)?.label || x.mission_acceptance) : 'Comme l\'entreprise' },
    { label: 'Missions en cours', value: x.active_assignments_count ?? 0, to: { path: '/admin/courses', query: { courier_id: x.id } } },
    { label: 'Colis au plus', value: x.max_parcels ?? 'Sans limite' },
    { label: 'Argent en main au plus', value: x.max_cash === null || x.max_cash === undefined ? 'Sans limite' : money(x.max_cash) },
    { label: 'Fin de service', value: x.shift_ends_at ? String(x.shift_ends_at).slice(0, 5) : '—' },
    { label: 'Dernière position', value: x.last_location_at ? dateTime(x.last_location_at) : '—', href: x.last_location_at ? mapsLink(x.current_lat, x.current_lng) : null },
  ]
})

async function load() {
  error.value = ''
  try {
    p.value = (await http.get(`/couriers/${route.params.id}/profile`, { params: { days: days.value } })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function loadField() {
  const to = new Date().toLocaleDateString('sv-SE')
  const from = new Date(Date.now() - (days.value - 1) * 86400000).toLocaleDateString('sv-SE')
  try {
    field.value = (await http.get('/field-events/stats', { params: { from, to, courier_id: route.params.id } })).data.data
  } catch {
    field.value = { totals: { events: 0, blocking: 0, immobilised_minutes: 0, cost: 0 }, recent: [] }
  }
}

function setDays(d) {
  days.value = d
  load()
  if (field.value) loadField()
}

watch(tab, (t) => { if (t === 'terrain' && !field.value) loadField() }, { immediate: true })
watch([tab, days], () => router.replace({ query: { ...(tab.value !== 'activite' ? { onglet: tab.value } : {}), ...(days.value !== 30 ? { jours: days.value } : {}) } }))
onMounted(load)
</script>
