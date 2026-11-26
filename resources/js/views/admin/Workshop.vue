<template>
  <div class="space-y-4">
    <PageTabs :tabs="FLEET_TABS" />
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">Atelier</h1>
        <p class="text-sm text-slate-600">Pannes des livreurs confiées automatiquement au mécanicien en service le plus proche. Attribuez à la main celles qui restent sans mécanicien.</p>
      </div>
      <button v-if="auth.can('repairs.manage')" type="button" class="btn-primary" @click="createOpen = true">+ Nouvelle intervention</button>
    </div>

    <!-- Indicateurs du jour -->
    <div v-if="auth.can('repairs.manage') && counts" class="grid grid-cols-2 gap-3 md:grid-cols-5">
      <div class="card p-3"><p class="text-xs text-slate-600">Pannes du jour</p><p class="text-2xl font-bold tabular-nums">{{ counts.today }}</p></div>
      <div :class="['card p-3', counts.searching ? 'ring-2 ring-red-300' : '']"><p class="text-xs text-slate-600">Sans mécanicien</p><p :class="['text-2xl font-bold tabular-nums', counts.searching ? 'text-red-700' : '']">{{ counts.searching }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-600">Temps de réponse moyen</p><p class="text-2xl font-bold tabular-nums">{{ minutes(averages.response) }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-600">Temps d'arrivée moyen</p><p class="text-2xl font-bold tabular-nums">{{ minutes(averages.arrival) }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-600">Immobilisation moyenne</p><p class="text-2xl font-bold tabular-nums">{{ minutes(averages.downtime) }}</p></div>
    </div>

    <!-- Achats sur place à accorder (administrateur) -->
    <section v-if="auth.can('repairs.purchases') && purchases.length" class="card space-y-3 p-4 ring-2 ring-orange-200">
      <h2 class="flex items-center gap-1.5 font-semibold"><Icone nom="achat" taille="petite" /> Achats sur place à accorder · {{ purchases.length }}</h2>
      <div v-for="p in purchases" :key="p.id" class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-3 text-sm">
        <div class="min-w-0 flex-1">
          <p class="font-medium">{{ p.quantity }} × {{ p.label }} · environ {{ money(p.estimated_amount) }}</p>
          <p class="text-slate-600">{{ p.mechanic }} · {{ p.repair_title }}{{ p.vehicle ? ` · ${p.vehicle}` : '' }}{{ p.courier ? ` · ${p.courier}` : '' }}{{ p.reason ? ` · « ${p.reason} »` : '' }}</p>
        </div>
        <label class="flex items-center gap-1 text-slate-600" :for="`max-${p.id}`">Jusqu'à
          <input :id="`max-${p.id}`" v-model.number="maxAmounts[p.id]" type="number" min="1" class="input w-28" :placeholder="String(p.estimated_amount)"> F
        </label>
        <button type="button" class="btn bg-emerald-600 text-white hover:bg-emerald-500" :disabled="busy" @click="decide(p, true)">Accorder</button>
        <button type="button" class="btn-secondary" :disabled="busy" @click="decide(p, false)">Refuser</button>
      </div>
    </section>

    <template v-if="auth.can('repairs.manage')">
      <div class="flex flex-wrap items-center gap-2">
        <button v-for="t in TABS" :key="t.value" type="button" :class="['btn', tab === t.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']" @click="tab = t.value">
          {{ t.label }}<span v-if="t.value === 'waiting' && counts?.waiting"> ({{ counts.waiting }})</span>
        </button>
        <input v-model="q" type="search" class="input ml-auto w-64" placeholder="Plaque, livreur, mécanicien…" aria-label="Chercher une intervention" @input="searchLater">
      </div>

      <div class="card overflow-x-auto">
        <table class="w-full min-w-[56rem] text-left text-sm">
          <thead class="text-xs uppercase text-slate-600">
            <tr><th class="p-3">N°</th><th class="p-3">Panne</th><th class="p-3">Véhicule · livreur</th><th class="p-3">Mécanicien</th><th class="p-3">Étape</th><th class="p-3">Reçue</th><th class="p-3">Arrivée</th><th class="p-3">Coût</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in repairs" :key="r.id" class="cursor-pointer border-t border-slate-100 hover:bg-slate-50" @click="open(r.id)">
              <td class="p-3 font-mono">{{ r.number }}</td>
              <td class="p-3"><Icone v-if="r.icon" :nom="r.icon" taille="petite" class="mr-1 inline" />{{ r.category_label ? `${r.category_label}${r.part ? ' · ' + r.part : ''}` : r.title }}</td>
              <td class="p-3">{{ [r.vehicle?.plate, r.courier?.name].filter(Boolean).join(' · ') || '—' }}</td>
              <td class="p-3">
                <span v-if="r.mechanic">{{ r.mechanic.name }}</span>
                <span v-else class="font-medium text-red-700">Aucun{{ r.declined_count ? ` · ${r.declined_count} refus` : '' }}</span>
              </td>
              <td class="p-3"><span :class="['whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', STATUS_STYLES[r.status]]">{{ r.status_label }}</span></td>
              <td class="p-3 font-mono text-xs">{{ dateTime(r.created_at) }}</td>
              <td class="p-3 font-mono text-xs">{{ r.arrived_at ? clock(r.arrived_at) : '—' }}</td>
              <td class="p-3 tabular-nums">{{ r.status === 'done' ? money(r.total) : '—' }}</td>
            </tr>
            <tr v-if="!loading && !repairs.length"><td colspan="8" class="p-8 text-center text-slate-600"><template v-if="tab === 'waiting'">Aucune panne en attente de mécanicien. <Icone nom="pouce" taille="petite" class="inline" /></template><template v-else>Aucune intervention ici.</template></td></tr>
          </tbody>
        </table>
      </div>
      <div v-if="meta.last_page > 1" class="flex justify-center gap-2">
        <button type="button" class="btn-secondary" :disabled="page <= 1" @click="page--">Précédentes</button>
        <span class="self-center text-sm text-slate-600">Page {{ page }} sur {{ meta.last_page }}</span>
        <button type="button" class="btn-secondary" :disabled="page >= meta.last_page" @click="page++">Suivantes</button>
      </div>
    </template>

    <!-- Fiche d'une intervention -->
    <Modal :open="!!current" :title="current ? `Panne n° ${current.number} · ${current.title}` : ''" @close="close">
      <div v-if="current" class="space-y-4 text-sm">
        <p class="flex flex-wrap gap-2">
          <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATUS_STYLES[current.status]]">{{ current.status_label }}</span>
          <span>{{ current.severity_label }}</span>
          <span v-if="current.vehicle">· {{ current.vehicle.plate }} ({{ current.vehicle.name }})</span>
          <span v-if="current.courier">· {{ current.courier.name }} {{ localPhone(current.courier.phone) }}</span>
        </p>
        <p v-if="current.note" class="rounded bg-slate-50 p-2">« {{ current.note }} »</p>

        <table class="w-full">
          <tr v-for="s in timeline" :key="s.label" class="border-t border-slate-100"><td class="py-1.5 text-slate-600">{{ s.label }}</td><td class="py-1.5 text-right font-mono">{{ s.at ? dateTime(s.at) : '—' }}</td></tr>
        </table>
        <p v-if="current.arrival_lat !== null"><a :href="mapsLink(current.arrival_lat, current.arrival_lng)" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-blue-700"><Icone nom="adresse" taille="petite" /> Position à l'arrivée</a></p>

        <div v-if="current.category" class="space-y-1">
          <p class="font-semibold">Diagnostic</p>
          <p>{{ current.category_label }}{{ current.part ? ` · ${current.part}` : '' }} · {{ current.decision_label }}</p>
          <p v-if="current.diagnosis" class="text-slate-600">{{ current.diagnosis }}</p>
        </div>
        <div v-if="current.status === 'done'" class="space-y-1">
          <p class="font-semibold">Clôture · cause : {{ current.cause_label }}</p>
          <p v-for="(l, i) in current.parts" :key="i">{{ l.quantity }} × {{ l.label }} · {{ money(l.amount) }}{{ l.kind === 'purchase' ? ' (achat sur place)' : '' }}</p>
          <p v-if="current.garage">Garage {{ current.garage }} · {{ money(current.garage_amount) }}</p>
          <p class="font-semibold">Total {{ money(current.total) }}{{ current.odometer ? ` · compteur ${current.odometer} km` : '' }}</p>
          <p v-if="current.remark">À prévoir : {{ current.remark }}</p>
          <p :class="current.courier_can_resume ? 'text-emerald-700' : 'text-amber-700'">{{ current.courier_can_resume ? 'Le livreur a pu repartir.' : 'Le livreur n\'est pas reparti : à organiser.' }}</p>
        </div>
        <div v-if="photos.length" class="flex flex-wrap gap-2">
          <figure v-for="p in photos" :key="p.id" class="text-center text-xs text-slate-600">
            <img :src="p.src" :alt="`Photo ${p.kind === 'before' ? 'avant' : 'après'}`" class="h-24 w-24 rounded object-cover">
            <figcaption>{{ p.kind === 'before' ? 'avant' : 'après' }}</figcaption>
          </figure>
        </div>
        <div v-if="current.purchases.length" class="space-y-1">
          <p class="font-semibold">Achats sur place</p>
          <p v-for="p in current.purchases" :key="p.id">{{ p.quantity }} × {{ p.label }} · {{ p.status_label }}{{ p.paid_amount !== null ? ` · payé ${money(p.paid_amount)}` : '' }}</p>
        </div>

        <!-- Attribuer à la main, annuler -->
        <div v-if="['searching', 'offered', 'accepted'].includes(current.status)" class="space-y-2 border-t pt-3">
          <label class="label" for="assign-mechanic">Confier à</label>
          <div class="flex gap-2">
            <select id="assign-mechanic" v-model="assignTo" class="input">
              <option v-for="m in mechanics" :key="m.id" :value="m.id">{{ m.name }} · {{ m.is_available ? 'en service' : 'hors service' }}{{ m.distance_km !== null ? ` · ${m.distance_km} km` : '' }}{{ m.active_repairs ? ` · ${m.active_repairs} en cours` : '' }}</option>
            </select>
            <button type="button" class="btn-primary shrink-0" :disabled="busy || !assignTo" @click="assign">Confier</button>
          </div>
          <p v-if="!mechanics.length" class="text-slate-600">Aucun mécanicien : créez un compte « Mécanicien » dans Utilisateurs.</p>
        </div>
        <button v-if="['searching', 'offered', 'accepted', 'en_route'].includes(current.status)" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="cancel">Annuler l'intervention</button>
      </div>
    </Modal>

    <!-- Nouvelle intervention -->
    <Modal :open="createOpen" title="Nouvelle intervention" @close="createOpen = false">
      <form class="space-y-3" @submit.prevent="create">
        <p class="text-sm text-slate-600">Problème vu au dépôt ou au contrôle de départ : elle est proposée au mécanicien en service le plus proche, ou à celui que vous choisissez.</p>
        <div><label class="label" for="new-title">Panne</label><input id="new-title" v-model="draft.title" class="input" maxlength="160" required placeholder="Ex. : frein avant mou"></div>
        <div v-if="vehicles.length">
          <label class="label" for="new-vehicle">Véhicule</label>
          <select id="new-vehicle" v-model="draft.vehicle_id" class="input"><option :value="null">—</option><option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.plate }} · {{ [v.brand, v.model].filter(Boolean).join(' ') || v.type }}</option></select>
        </div>
        <div>
          <label class="label" for="new-severity">Gravité</label>
          <select id="new-severity" v-model="draft.severity" class="input"><option value="hindrance">Gênant</option><option value="blocking">Bloquant</option><option value="urgent">Urgent</option></select>
        </div>
        <div>
          <label class="label" for="new-mechanic">Mécanicien</label>
          <select id="new-mechanic" v-model="draft.mechanic_id" class="input"><option :value="null">Automatique (le plus proche en service)</option><option v-for="m in mechanics" :key="m.id" :value="m.id">{{ m.name }}</option></select>
        </div>
        <div><label class="label" for="new-note">Précisions</label><textarea id="new-note" v-model="draft.note" class="input" rows="2" maxlength="1000" /></div>
        <button type="submit" class="btn-primary" :disabled="busy || !draft.title">Créer l'intervention</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import Icone from '../../components/ui/Icone.vue'
import PageTabs from '../../components/PageTabs.vue'
import { FLEET_TABS } from '../../utils/pageTabs'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { authImage } from '../../composables/useAuthImage'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { dateTime, localPhone, mapsLink, money } from '../../utils/format'
import { promptDialog } from '../../composables/useDialog'

const TABS = [{ value: 'waiting', label: 'En attente' }, { value: 'open', label: 'En cours' }, { value: 'done', label: 'Terminées' }, { value: 'all', label: 'Toutes' }]
const STATUS_STYLES = {
  searching: 'bg-red-100 text-red-800', offered: 'bg-orange-100 text-orange-800', accepted: 'bg-sky-100 text-sky-800', en_route: 'bg-sky-100 text-sky-800',
  on_site: 'bg-violet-100 text-violet-800', repairing: 'bg-violet-100 text-violet-800', done: 'bg-emerald-100 text-emerald-800', cancelled: 'bg-slate-100 text-slate-600',
}

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const toasts = useToastStore()
const tab = ref('open')
const q = ref('')
const page = ref(1)
const repairs = ref([])
const meta = ref({ last_page: 1 })
const counts = ref(null)
const averages = ref({})
const purchases = ref([])
const maxAmounts = reactive({})
const loading = ref(false)
const busy = ref(false)
const current = ref(null)
const photos = ref([])
const mechanics = ref([])
const assignTo = ref(null)
const vehicles = ref([])
const createOpen = ref(false)
const draft = reactive({ title: '', vehicle_id: null, severity: 'blocking', mechanic_id: null, note: '' })

const minutes = (v) => (v === null || v === undefined ? '—' : v < 60 ? `${v} min` : `${Math.floor(v / 60)} h ${String(v % 60).padStart(2, '0')}`)
const clock = (v) => new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const timeline = computed(() => current.value ? [
  { label: 'Panne reçue', at: current.value.created_at },
  current.value.status === 'offered'
    ? { label: `Proposée à ${current.value.mechanic?.name}, en attente de sa réponse`, at: current.value.offered_at }
    : { label: current.value.mechanic ? `Acceptée par ${current.value.mechanic.name}` : 'Acceptée', at: current.value.accepted_at },
  { label: 'Départ', at: current.value.departed_at },
  { label: 'Arrivée sur place', at: current.value.arrived_at },
  { label: 'Début de la réparation', at: current.value.started_at },
  { label: current.value.status === 'cancelled' ? 'Annulée' : 'Fin', at: current.value.status === 'cancelled' ? current.value.cancelled_at : current.value.finished_at },
] : [])

async function load() {
  if (!auth.can('repairs.manage')) return
  loading.value = true
  try {
    const { data } = await http.get('/repairs', { params: { tab: tab.value, q: q.value || undefined, page: page.value } })
    repairs.value = data.data
    meta.value = data.meta
    counts.value = data.counts
    averages.value = data.averages
  } finally {
    loading.value = false
  }
}

async function loadPurchases() {
  if (!auth.can('repairs.purchases')) return
  purchases.value = (await http.get('/repair-purchases')).data.data
}

let searchTimer
function searchLater() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; load() }, 300)
}
watch(tab, () => { page.value = 1; load() })
watch(page, load)

async function open(id) {
  router.replace({ query: { ...route.query, intervention: id } })
}
function close() {
  const { intervention, ...rest } = route.query
  router.replace({ query: rest })
}
watch(() => route.query.intervention, async (id) => {
  photos.value.forEach((p) => URL.revokeObjectURL(p.src))
  photos.value = []
  if (!id) { current.value = null; return }
  try {
    const [r, m] = await Promise.all([http.get(`/repairs/${id}`), http.get('/repairs/mechanics', { params: { repair: id } })])
    current.value = r.data.data
    mechanics.value = m.data.data
    assignTo.value = mechanics.value[0]?.id ?? null
    photos.value = (await Promise.all(current.value.files.map(async (f) => ({ ...f, src: await authImage(f.url).catch(() => null) })))).filter((p) => p.src)
  } catch (e) {
    toasts.error(apiErrorMessage(e, 'Intervention introuvable.'))
    close()
  }
}, { immediate: true })

async function assign() {
  busy.value = true
  try {
    current.value = (await http.post(`/repairs/${current.value.id}/assign`, { mechanic_id: assignTo.value })).data.data
    toasts.success(`Panne confiée à ${current.value.mechanic?.name}.`)
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function cancel() {
  const reason = (await promptDialog('Pourquoi annuler ? (le mécanicien est prévenu)', ''))
  if (reason === null) return
  busy.value = true
  try {
    current.value = (await http.post(`/repairs/${current.value.id}/cancel`, { reason: reason || null })).data.data
    toasts.success('Intervention annulée.')
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function decide(purchase, approve) {
  let note = null
  if (!approve) {
    note = (await promptDialog('Motif du refus (le mécanicien le voit)', ''))
    if (note === null) return
  }
  busy.value = true
  try {
    await http.post(`/repair-purchases/${purchase.id}/decide`, { approve, max_amount: approve ? maxAmounts[purchase.id] || null : null, note: note || null })
    toasts.success(approve ? 'Achat accordé : le mécanicien est prévenu.' : 'Achat refusé : le mécanicien est prévenu.')
    loadPurchases()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function create() {
  busy.value = true
  try {
    const created = (await http.post('/repairs', draft)).data.data
    toasts.success(created.mechanic ? `Intervention proposée à ${created.mechanic.name}.` : 'Intervention créée : aucun mécanicien en service pour l\'instant.')
    Object.assign(draft, { title: '', vehicle_id: null, severity: 'blocking', mechanic_id: null, note: '' })
    createOpen.value = false
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

let timer
onMounted(async () => {
  load()
  loadPurchases()
  if (auth.can('repairs.manage')) {
    mechanics.value = (await http.get('/repairs/mechanics').catch(() => null))?.data.data ?? []
    if (auth.can('fleet.manage')) {
      vehicles.value = ((await http.get('/vehicles').catch(() => null))?.data.data ?? []).filter((v) => v.status !== 'sold')
    }
  }
  timer = setInterval(() => { if (!document.hidden) { load(); loadPurchases() } }, 30000)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  photos.value.forEach((p) => URL.revokeObjectURL(p.src))
})
</script>
