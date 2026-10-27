<template>
  <div v-if="v" class="space-y-4">
    <RouterLink to="/admin/vehicules" class="text-sm text-blue-600">← Véhicules</RouterLink>

    <!-- En-tête -->
    <div class="flex flex-wrap items-start gap-4">
      <label class="card grid h-24 w-32 shrink-0 cursor-pointer place-items-center overflow-hidden text-5xl" title="Changer la photo">
        <img v-if="photo" :src="photo" alt="" class="h-full w-full object-cover"><span v-else>{{ VEHICLE_TYPES[v.type]?.icon }}</span>
        <input type="file" accept="image/*" class="hidden" @change="uploadPhoto">
      </label>
      <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
          <h1 class="font-mono text-2xl font-bold">{{ v.plate }}</h1>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS_STYLES[v.status]]">{{ v.status_label }}</span>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', OWNERSHIP_STYLES[v.ownership]]">{{ v.ownership_label }}{{ v.owner ? ` · ${v.owner.name}` : '' }}</span>
        </div>
        <p class="text-gray-600">{{ [v.brand, v.model, v.year].filter(Boolean).join(' ') || VEHICLE_TYPES[v.type]?.label }}{{ v.color ? ` · ${v.color}` : '' }}{{ v.vin ? ` · châssis ${v.vin}` : '' }}{{ capacity ? ` · ${capacity}` : '' }}</p>
        <p class="mt-1 text-sm">
          🛵 <template v-if="v.courier">Confié à <b>{{ v.courier.name }}</b></template><template v-else>Confié à personne</template>
          · compteur <b>{{ km(v.odometer) }}</b><span v-if="v.odometer_at" class="text-gray-500"> (relevé le {{ dateTime(v.odometer_at) }})</span>
        </p>
        <p v-if="v.problems.length" class="mt-1 text-sm font-medium text-red-700">⛔ Pas utilisable : {{ v.problems.join(', ') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" @click="assigning = true">🔁 Confier à…</button>
        <button type="button" class="btn-secondary" @click="openLog('maintenance')">🔧 Entretien, dépense</button>
        <button type="button" class="btn-primary" @click="formOpen = true">Modifier</button>
      </div>
    </div>

    <nav class="flex gap-1 overflow-x-auto border-b border-slate-200">
      <button v-for="t in TABS" :key="t.value" type="button" :class="['-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', tab === t.value ? 'border-slate-900 text-slate-900' : 'border-transparent text-gray-500']" @click="setTab(t.value)">{{ t.label }}</button>
    </nav>

    <!-- Résumé -->
    <div v-if="tab === 'resume'" class="grid gap-4 lg:grid-cols-3">
      <section class="card p-4 lg:col-span-2">
        <div class="flex items-center justify-between"><h2 class="font-semibold">Papiers</h2><button type="button" class="text-sm text-blue-600" @click="setTab('papiers')">Tout voir →</button></div>
        <table class="mt-1 w-full text-sm">
          <tr v-for="d in currentDocs" :key="d.id" class="border-t border-slate-100">
            <td class="py-2">{{ d.icon }} {{ d.kind_label }}</td><td class="py-2 text-gray-600">{{ [d.issuer, d.reference].filter(Boolean).join(' · ') }}</td>
            <td class="py-2"><span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATE_STYLES[d.state]]">{{ d.ends_on ? `jusqu'au ${date(d.ends_on)}` : 'permanent' }}</span></td>
          </tr>
          <tr v-if="!currentDocs.length"><td class="py-3 text-gray-500">Aucun papier : <button type="button" class="text-blue-600" @click="openDoc(null)">ajouter l'assurance, la visite…</button></td></tr>
        </table>
      </section>
      <section class="card p-4">
        <h2 class="font-semibold">Coûts depuis le début</h2>
        <p class="mt-1 text-3xl font-bold">{{ money(v.costs.total) }}</p>
        <p class="text-sm text-gray-500">
          {{ [v.cost_per_km ? `${money(v.cost_per_km)} / km` : null, v.cost_per_course ? `${money(v.cost_per_course)} / course (${v.courses} courses)` : null].filter(Boolean).join(' et ') }}
        </p>
        <ul class="mt-3 space-y-1 text-sm">
          <li v-for="c in COST_LINES" v-show="v.costs[c.key]" :key="c.key" class="flex justify-between"><span>{{ c.label }}</span><b>{{ money(v.costs[c.key]) }}</b></li>
        </ul>
        <p v-if="v.purchase_price" class="mt-2 text-xs text-gray-400">Prix d'achat ({{ money(v.purchase_price) }}) à part.</p>
      </section>
      <section class="card p-4">
        <h2 class="font-semibold">Plan d'entretien</h2>
        <MaintenanceBars :items="v.maintenance" class="mt-1" />
      </section>
      <section class="card p-4 lg:col-span-2">
        <h2 class="font-semibold">Derniers faits</h2>
        <ul class="mt-1 divide-y divide-slate-100 text-sm">
          <li v-for="f in recent" :key="f.key" class="flex gap-3 py-2"><span class="w-14 shrink-0 text-gray-500">{{ shortDate(f.at) }}</span><span class="flex-1">{{ f.text }}</span><span class="font-medium">{{ f.amount ? money(f.amount) : '' }}</span></li>
          <li v-if="!recent.length" class="py-3 text-gray-500">Rien pour l'instant.</li>
        </ul>
      </section>
    </div>

    <!-- Papiers -->
    <section v-if="tab === 'papiers'" class="card p-4 space-y-2">
      <div class="flex items-center justify-between"><h2 class="font-semibold">Papiers du véhicule</h2><button type="button" class="btn-primary text-sm" @click="openDoc(null)">+ Ajouter un papier</button></div>
      <table class="w-full text-sm">
        <thead class="text-left text-xs uppercase text-gray-500"><tr><th class="py-2">Papier</th><th>Numéro, délivré par</th><th>Période</th><th>État</th><th>Coût</th><th /></tr></thead>
        <tr v-for="d in v.documents" :key="d.id" :class="['border-t border-slate-100', d.current ? '' : 'opacity-50']">
          <td class="py-2">{{ d.icon }} {{ d.kind === 'other' ? d.label : d.kind_label }}</td>
          <td class="text-gray-600">{{ [d.reference, d.issuer].filter(Boolean).join(' · ') || '—' }}</td>
          <td>{{ d.starts_on ? date(d.starts_on) : '' }}{{ d.ends_on ? ` → ${date(d.ends_on)}` : ' · sans fin' }}</td>
          <td><span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATE_STYLES[d.state]]">{{ d.current ? (d.state === 'permanent' ? 'permanent' : daysText(d.days)) : 'remplacé' }}</span></td>
          <td>{{ d.cost ? money(d.cost) : '' }}</td>
          <td class="whitespace-nowrap text-right">
            <button v-if="d.has_file" type="button" class="text-blue-600" @click="openFile(`/vehicles/documents/${d.id}/file`)">Voir</button>
            <button v-if="d.has_original" type="button" class="text-gray-600" @click="openFile(`/vehicles/documents/${d.id}/file?original=1`)">Original</button>
            <button type="button" class="ml-3 text-blue-600" @click="openDoc(d)">Modifier</button>
            <button type="button" class="ml-3 text-red-600" @click="removeDoc(d)">✕</button>
          </td>
        </tr>
        <tr v-if="!v.documents.length"><td colspan="6" class="py-4 text-gray-500">Aucun papier enregistré.</td></tr>
      </table>
      <p class="text-xs text-gray-500">Rappels avant la date de fin (Réglages › Véhicules). Pour renouveler, ajoutez le nouveau papier : l'ancien est gardé dans l'historique.</p>
    </section>

    <!-- Entretien -->
    <div v-if="tab === 'entretien'" class="grid gap-4 lg:grid-cols-2">
      <section class="card p-4 space-y-2">
        <div class="flex items-center justify-between"><h2 class="font-semibold">Où en est l'entretien</h2><button type="button" class="btn-primary text-sm" @click="openLog('maintenance')">+ Entretien fait</button></div>
        <MaintenanceBars :items="v.maintenance" />
      </section>
      <section class="card p-4 space-y-2">
        <h2 class="font-semibold">Plan de ce véhicule</h2>
        <div v-for="(p, i) in plan" :key="i" class="grid grid-cols-[3rem_1fr_6rem_5rem_2rem] items-center gap-2">
          <input v-model="p.icon" class="input px-1 text-center" :aria-label="`Icône ${i + 1}`">
          <input v-model="p.label" class="input" :aria-label="`Entretien ${i + 1}`">
          <input v-model.number="p.every_km" type="number" min="1" class="input" placeholder="km" :aria-label="`Tous les … km (${p.label})`">
          <input v-model.number="p.every_months" type="number" min="1" class="input" placeholder="mois" :aria-label="`Tous les … mois (${p.label})`">
          <button type="button" class="text-red-600" :aria-label="`Retirer ${p.label}`" @click="plan.splice(i, 1)">✕</button>
        </div>
        <p class="text-xs text-gray-500">Tous les … km et/ou tous les … mois (le premier atteint).</p>
        <div class="flex gap-2">
          <button type="button" class="btn-secondary text-sm" @click="plan.push({ icon: '🔧', label: '', every_km: null, every_months: null })">+ Ligne</button>
          <button type="button" class="btn-primary text-sm" :disabled="busy" @click="savePlan">Enregistrer le plan</button>
        </div>
      </section>
    </div>

    <!-- Carnet -->
    <section v-if="tab === 'carnet'" class="card p-4 space-y-2">
      <div class="flex flex-wrap items-center gap-2">
        <h2 class="mr-auto font-semibold">Carnet</h2>
        <button v-for="k in [{ value: '', label: 'Tout', icon: '' }, ...catalog.log_kinds]" :key="k.value" type="button" :class="['rounded-full px-3 py-1 text-sm', logFilter === k.value ? 'bg-slate-900 text-white' : 'bg-slate-100']" @click="logFilter = k.value">{{ k.icon }} {{ k.label }}</button>
        <button type="button" class="btn-primary text-sm" @click="openLog(logFilter || 'maintenance')">+ Ajouter</button>
      </div>
      <table class="w-full text-sm">
        <tr v-for="l in logs" :key="l.id" class="border-t border-slate-100 align-top">
          <td class="w-20 py-2 text-gray-500">{{ date(l.done_on) }}</td>
          <td class="py-2">
            <p>{{ l.icon }} <b>{{ l.kind_label }}</b><span v-if="l.label"> · {{ l.label }}</span><span v-if="l.garage" class="text-gray-500"> · {{ l.garage }}</span></p>
            <p class="text-xs text-gray-500">
              {{ [l.odometer ? km(l.odometer) : null, l.liters ? `${l.liters} L` : null, l.courier, l.paid_by === 'courier' ? 'payé par le livreur' : null,
                  l.immobilized_days ? `${l.immobilized_days} j d'immobilisation` : null, l.charged ? (l.kind === 'fuel' ? `remboursé ${money(l.charged)}` : `retenu ${money(l.charged)}`) : null].filter(Boolean).join(' · ') }}
              <RouterLink v-if="l.field_event_id" :to="`/admin/terrain?evenement=${l.field_event_id}`" class="text-blue-600"> · événement terrain</RouterLink>
              <button v-for="n in l.photos" :key="n" type="button" class="ml-1 text-blue-600" @click="openFile(`/vehicles/logs/${l.id}/photos/${n - 1}`)">📎{{ n }}</button>
            </p>
          </td>
          <td class="py-2 text-right font-medium">{{ l.amount ? money(l.amount) : '' }}</td>
          <td class="w-8 py-2 text-right"><button type="button" class="text-red-600" :aria-label="`Supprimer la ligne du ${date(l.done_on)}`" @click="removeLog(l)">✕</button></td>
        </tr>
        <tr v-if="!logs.length"><td class="py-4 text-gray-500">Rien dans le carnet.</td></tr>
      </table>
    </section>

    <!-- Historique -->
    <div v-if="tab === 'historique'" class="grid gap-4 lg:grid-cols-2">
      <section class="card p-4">
        <h2 class="font-semibold">Remises</h2>
        <ul class="mt-1 divide-y divide-slate-100 text-sm">
          <li v-for="a in v.assignments" :key="a.id" class="py-2">
            <b>{{ a.courier }}</b> <span class="text-gray-500">{{ a.kind === 'day' ? '(pour la journée)' : '(attitré)' }}</span>
            <p class="text-xs text-gray-500">{{ dateTime(a.starts_at) }} → {{ a.ends_at ? dateTime(a.ends_at) : 'aujourd\'hui' }} · {{ km(a.odometer_start) }}{{ a.odometer_end ? ` → ${km(a.odometer_end)}` : '' }}</p>
          </li>
          <li v-if="!v.assignments.length" class="py-3 text-gray-500">Jamais confié.</li>
        </ul>
      </section>
      <section class="card p-4">
        <h2 class="font-semibold">États des lieux</h2>
        <ul class="mt-1 divide-y divide-slate-100 text-sm">
          <li v-for="c in v.checks" :key="c.id" class="py-2">
            <p><b>{{ c.moment === 'end' ? 'Retour' : 'Départ' }}</b> · {{ c.courier }} · {{ dateTime(c.created_at) }}</p>
            <p class="text-xs text-gray-500">
              {{ [c.odometer ? km(c.odometer) : null, c.fuel_label ? `essence ${c.fuel_label}` : null, c.gps_km !== null ? `GPS ${c.gps_km} km` : null].filter(Boolean).join(' · ') }}
              <span v-if="c.problems" class="font-medium text-red-700"> · ⚠️ {{ problemList(c) }}</span><span v-else-if="c.items && Object.keys(c.items).length" class="text-emerald-700"> · tout est OK</span>
              <span v-if="c.note"> · « {{ c.note }} »</span>
              <button v-for="n in c.photos" :key="n" type="button" class="ml-1 text-blue-600" @click="openFile(`/vehicles/checks/${c.id}/photos/${n - 1}`)">📷{{ n }}</button>
            </p>
          </li>
          <li v-if="!v.checks.length" class="py-3 text-gray-500">Aucun état des lieux.</li>
        </ul>
      </section>
    </div>

    <!-- Location -->
    <div v-if="tab === 'location'" class="space-y-4">
      <template v-for="l in v.leases" :key="l.id">
        <div class="flex flex-wrap items-center gap-2">
          <h2 class="text-lg font-semibold">{{ l.kind === 'purchase' ? 'Location-vente' : 'Location' }} · {{ l.courier.name }}</h2>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', { active: 'bg-violet-100 text-violet-800', completed: 'bg-emerald-100 text-emerald-800', cancelled: 'bg-slate-100 text-slate-600' }[l.status]]">{{ { active: 'En cours', completed: 'Terminée', cancelled: 'Arrêtée' }[l.status] }}</span>
        </div>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <VKpi :title="l.total_amount ? 'Prix total' : 'Montant'" :value="money(l.total_amount || l.amount)" :hint="`${money(l.amount)} par ${PERIODS[l.period]}${l.period === 'day' && l.days_per_week < 7 ? ` · ${l.days_per_week} j/7` : ''}`" />
          <VKpi title="Déjà payé" :value="money(l.paid)" :hint="l.periods_total ? `${l.periods_paid} versements sur ${l.periods_total}` : `${l.periods_paid} versements`" tone="text-emerald-700" />
          <VKpi v-if="l.remaining !== null" title="Reste à payer" :value="money(l.remaining)" :hint="l.ends_on ? `fin prévue le ${date(l.ends_on)}` : ''" />
          <VKpi title="En retard" :value="money(l.late)" :hint="l.late ? `${l.late_periods} versement(s)` : 'à jour'" :tone="l.late ? 'text-red-700' : 'text-slate-900'" />
        </div>
        <section v-if="l.progress !== null" class="card p-4">
          <div class="flex justify-between text-sm"><span>🛵 {{ l.courier.name }} devient propriétaire à la fin</span><b>{{ l.progress }} %</b></div>
          <div class="mt-2 h-3 rounded-full bg-slate-100"><div class="h-3 rounded-full bg-violet-500" :style="{ width: `${l.progress}%` }" /></div>
        </section>
        <div class="grid gap-4 lg:grid-cols-3">
          <section class="card p-4 lg:col-span-2">
            <h3 class="font-semibold">Versements</h3>
            <ul class="mt-1 divide-y divide-slate-100 text-sm">
              <li v-for="p in l.payments" :key="p.id" class="flex gap-3 py-2"><span class="w-24 text-gray-500">{{ date(p.paid_on) }}</span><span class="flex-1">{{ p.method === 'payroll' ? 'Retenue sur la paie' : p.method === 'cash' ? 'Payé en caisse' : 'Autre' }}{{ p.note && p.method !== 'payroll' ? ` · ${p.note}` : '' }}</span><b>{{ money(p.amount) }}</b></li>
              <li v-if="!l.payments.length" class="py-3 text-gray-500">Aucun versement.</li>
            </ul>
          </section>
          <section class="card p-4 space-y-2 text-sm">
            <h3 class="font-semibold">Contrat</h3>
            <p>Montant : <b>{{ money(l.amount) }} / {{ PERIODS[l.period] }}</b></p>
            <p>Paiement : <b>{{ l.payment === 'payroll' ? 'retenue sur la paie' : 'en caisse' }}</b></p>
            <p>Début : {{ date(l.starts_on) }}</p>
            <p v-if="l.notes" class="text-gray-600">{{ l.notes }}</p>
            <template v-if="l.status === 'active' && auth.can('finance.manage')">
              <form class="flex gap-2 pt-2" @submit.prevent="pay(l)">
                <input v-model.number="payments[l.id]" type="number" min="1" class="input" placeholder="Montant" :aria-label="`Versement ${l.courier.name}`">
                <button class="btn-primary whitespace-nowrap">Encaisser</button>
              </form>
              <button type="button" class="text-xs text-red-600" @click="cancelLease(l)">Arrêter le contrat</button>
            </template>
          </section>
        </div>
      </template>
      <div v-if="!v.leases.some((l) => l.status === 'active')" class="card p-6 text-center">
        <p class="text-gray-600">{{ v.leases.length ? 'Aucun contrat en cours.' : 'Pas de location pour ce véhicule.' }}</p>
        <button v-if="auth.can('finance.manage') && catalog.settings.leases" type="button" class="btn-primary mt-3" @click="leasing = true">+ Location-vente ou location</button>
        <p v-else-if="!catalog.settings.leases" class="mt-1 text-xs text-gray-500">La location-vente est désactivée (Réglages › Véhicules).</p>
      </div>
    </div>

    <!-- Fenêtres -->
    <VehicleFormModal :open="formOpen" :vehicle="v" :couriers="couriers" :catalog="catalog" @close="formOpen = false" @saved="(d) => (v = { ...v, ...d }, load())" />
    <DocumentModal :open="docOpen" :doc="docEditing" :vehicle-id="v.id" :catalog="catalog" @close="docOpen = false" @saved="load" />
    <LogModal :open="logOpen" :kind="logKind" :vehicle="v" :couriers="couriers" :catalog="catalog" :settings="catalog.settings" @close="logOpen = false" @saved="(d) => (v = d)" />
    <LeaseModal :open="leasing" :vehicle="v" :couriers="couriers" @close="leasing = false" @saved="(d) => (v = d)" />
    <Modal :open="assigning" :title="`Confier ${v.plate}`" @close="assigning = false">
      <div class="space-y-3">
        <select v-model="assignTo" class="input" aria-label="Livreur">
          <option :value="null">Personne (reprendre le véhicule)</option>
          <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <p class="text-xs text-gray-500">Le livreur rend le véhicule qu'il avait. Le type et la plaque de sa fiche suivent ce véhicule.</p>
        <button type="button" class="btn-primary w-full" :disabled="busy" @click="assign">Confier</button>
      </div>
    </Modal>
  </div>
  <p v-else class="text-gray-500">Chargement…</p>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import DocumentModal from '../../components/vehicles/DocumentModal.vue'
import LeaseModal from '../../components/vehicles/LeaseModal.vue'
import LogModal from '../../components/vehicles/LogModal.vue'
import MaintenanceBars from '../../components/vehicles/MaintenanceBars.vue'
import VKpi from '../../components/vehicles/VKpi.vue'
import VehicleFormModal from '../../components/vehicles/VehicleFormModal.vue'
import { authImage } from '../../composables/useAuthImage'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime, money } from '../../utils/format'
import { OWNERSHIP_STYLES, PERIODS, STATE_STYLES, STATUS_STYLES, VEHICLE_TYPES, daysText, km } from '../../utils/vehicles'

const TABS = [
  { value: 'resume', label: 'Résumé' }, { value: 'papiers', label: 'Papiers' }, { value: 'entretien', label: 'Entretien' },
  { value: 'carnet', label: 'Carnet et carburant' }, { value: 'historique', label: 'Historique' }, { value: 'location', label: 'Location-vente' },
]
const COST_LINES = [
  { key: 'fuel', label: '⛽ Carburant' }, { key: 'maintenance', label: '🔧 Entretien' }, { key: 'repair', label: '🛠️ Réparations' }, { key: 'claim', label: '💥 Sinistres' },
  { key: 'fine', label: '👮 Amendes' }, { key: 'documents', label: '🛡️ Assurance, papiers' }, { key: 'other', label: '📝 Autres' },
]
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const v = ref(null)
const catalog = ref(null)
const couriers = ref([])
const photo = ref(null)
const tab = ref(TABS.some((t) => t.value === route.query.onglet) ? route.query.onglet : 'resume')
const plan = ref([])
const busy = ref(false)
const formOpen = ref(false)
const docOpen = ref(false)
const docEditing = ref(null)
const logOpen = ref(false)
const logKind = ref('maintenance')
const logFilter = ref('')
const leasing = ref(false)
const assigning = ref(false)
const assignTo = ref(null)
const payments = reactive({})

const shortDate = (d) => new Date(d).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
const capacity = computed(() => [v.value.has_box ? '1 coffre' : null, v.value.capacity_kg ? `${v.value.capacity_kg} kg` : null, v.value.capacity_parcels ? `${v.value.capacity_parcels} colis` : null].filter(Boolean).join(', '))
const currentDocs = computed(() => v.value.documents.filter((d) => d.current))
const logs = computed(() => v.value.logs.filter((l) => !logFilter.value || l.kind === logFilter.value))
const recent = computed(() => [
  ...v.value.logs.slice(0, 8).map((l) => ({ key: `l${l.id}`, at: l.done_on, text: `${l.icon} ${l.kind_label}${l.label ? ` : ${l.label}` : ''}${l.garage ? ` · ${l.garage}` : ''}${l.courier ? ` · ${l.courier}` : ''}`, amount: l.amount })),
  ...v.value.assignments.slice(0, 3).map((a) => ({ key: `a${a.id}`, at: a.starts_at, text: `🔑 Confié à ${a.courier}${a.kind === 'day' ? ' pour la journée' : ''} · compteur ${km(a.odometer_start)}` })),
  ...v.value.checks.filter((c) => c.problems).slice(0, 3).map((c) => ({ key: `c${c.id}`, at: c.created_at, text: `⚠️ État des lieux de ${c.courier} : ${problemList(c)}` })),
].sort((a, b) => new Date(b.at) - new Date(a.at)).slice(0, 8))

function problemList(c) {
  const labels = Object.fromEntries((catalog.value?.check_items || []).map((i) => [i.value, i.label]))
  return Object.entries(c.items || {}).filter(([, s]) => s === 'problem').map(([k]) => labels[k] || k).join(', ')
}

function setTab(value) {
  tab.value = value
  router.replace({ query: { ...route.query, onglet: value } })
}

async function load() {
  const { data } = await http.get(`/vehicles/${route.params.id}`)
  v.value = data.data
  plan.value = (v.value.maintenance_plan || []).map((p) => ({ ...p }))
  if (v.value.has_photo && !photo.value) photo.value = await authImage(`/vehicles/${v.value.id}/photo`).catch(() => null)
}

async function uploadPhoto(e) {
  const file = e.target.files[0]
  if (!file) return
  const body = new FormData()
  body.append('photo', file)
  try {
    await http.post(`/vehicles/${v.value.id}/photo`, body)
    photo.value = URL.createObjectURL(file)
  } catch (err) {
    toasts.error(apiErrorMessage(err))
  }
}

async function openFile(url) {
  const src = await authImage(url).catch(() => null)
  if (src) window.open(src, '_blank')
}

function openDoc(d) {
  docEditing.value = d
  docOpen.value = true
}
async function removeDoc(d) {
  if (!confirm(`Supprimer ce papier (${d.kind_label}) ?`)) return
  await http.delete(`/vehicles/documents/${d.id}`)
  load()
}

function openLog(kind) {
  logKind.value = kind
  logOpen.value = true
}
async function removeLog(l) {
  if (!confirm(`Supprimer cette ligne du carnet ?${l.charged ? ' Ce qui a été retenu ou remboursé au livreur est annulé.' : ''}`)) return
  v.value = (await http.delete(`/vehicles/logs/${l.id}`)).data.data
}

async function savePlan() {
  busy.value = true
  try {
    v.value = (await http.put(`/vehicles/${v.value.id}/plan`, { maintenance_plan: plan.value.filter((p) => p.label) })).data.data
    toasts.success('Plan d\'entretien enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function assign() {
  busy.value = true
  try {
    v.value = (await http.post(`/vehicles/${v.value.id}/assign`, { courier_id: assignTo.value })).data.data
    assigning.value = false
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function pay(l) {
  try {
    v.value = (await http.post(`/vehicles/leases/${l.id}/payments`, { amount: payments[l.id] })).data.data
    payments[l.id] = null
    toasts.success('Versement enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function cancelLease(l) {
  if (!confirm('Arrêter ce contrat ? Le véhicule redevient à l\'entreprise.')) return
  v.value = (await http.post(`/vehicles/leases/${l.id}/cancel`)).data.data
}

onMounted(async () => {
  const [cat, list] = await Promise.all([http.get('/vehicles/catalog'), http.get('/couriers')])
  catalog.value = cat.data.data
  couriers.value = list.data.data.map((c) => ({ id: c.id, name: c.name })).sort((a, b) => a.name.localeCompare(b.name))
  await load()
  assignTo.value = v.value.courier?.id ?? null
})
onBeforeUnmount(() => photo.value && URL.revokeObjectURL(photo.value))
</script>
