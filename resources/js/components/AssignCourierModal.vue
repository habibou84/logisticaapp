<template>
  <!-- Choisir un ramasseur ou un livreur : recherche, filtres, état actuel de chacun ; suggestions en tête -->
  <Modal :open="open" :title="title" wide @close="$emit('close')">
    <div class="space-y-3">
      <p v-if="subtitle" class="text-sm text-slate-700">{{ subtitle }}</p>
      <input
        ref="searchInput" v-model="search" type="search" class="input" placeholder="Rechercher un nom, un téléphone, une zone…"
        aria-label="Rechercher un livreur"
      >
      <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filtrer les livreurs">
        <button
          v-for="f in filters" :key="f.value" type="button"
          :class="['rounded-full px-3 py-1 text-sm ring-1', filter === f.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
          :aria-pressed="filter === f.value" @click="filter = f.value"
        >
          {{ f.label }}
        </button>
      </div>

      <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
      <p v-else-if="!rows" class="py-6 text-center text-sm text-slate-600">Chargement des livreurs…</p>
      <p v-else-if="!sorted.length" class="py-6 text-center text-sm text-slate-600">Aucun livreur ne correspond. Effacez la recherche ou changez de filtre.</p>

      <div v-else class="max-h-[55vh] space-y-2 overflow-y-auto pr-1">
        <template v-for="section in sections" :key="section.label">
          <p class="px-1 pt-1 text-xs font-bold uppercase tracking-wide text-slate-600">{{ section.label }}</p>
          <div
            v-for="c in section.items" :key="c.id"
            :class="['flex items-center gap-3 rounded-xl border p-3', c.id === currentId ? 'border-emerald-400 bg-emerald-50' : 'border-slate-200 bg-white']"
          >
            <span class="hidden h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-100 font-bold text-slate-700 sm:grid" aria-hidden="true">{{ initials(c.name) }}</span>
            <div class="min-w-0 flex-1 space-y-1">
              <p class="flex flex-wrap items-center gap-1.5">
                <span class="font-semibold">{{ c.name }}</span>
                <span :class="['rounded-full px-2 py-0.5 text-xs font-bold', STATES[c.state]?.class || 'bg-slate-100 text-slate-700']">{{ STATES[c.state]?.label || c.state }}</span>
                <span v-if="inZone(c)" class="text-xs font-semibold text-emerald-700">✓ {{ zone }}</span>
              </p>
              <p class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-slate-600">
                <span><b class="tabular-nums text-slate-900">{{ c.remaining }}</b> à faire</span>
                <span><b class="tabular-nums text-slate-900">{{ done(c) }}</b> faite{{ done(c) > 1 ? 's' : '' }} aujourd'hui</span>
                <span><b class="tabular-nums text-slate-900">{{ c.parcels_in_hand || 0 }}</b> colis en main</span>
                <span v-if="c.estimated_end">fin estimée <b class="text-slate-900">{{ hour(c.estimated_end) }}</b></span>
                <span v-if="c.zones?.length">{{ c.zones.join(', ') }}</span>
                <span v-if="c.phone">{{ c.phone }}</span>
              </p>
              <p v-if="c.flags?.length" class="text-xs font-semibold text-red-700">{{ c.flags.join(' · ') }}</p>
            </div>
            <span v-if="c.id === currentId" class="text-xs font-semibold text-emerald-800">Actuel</span>
            <button v-else type="button" class="btn-primary shrink-0" :disabled="busy" @click="pick(c)">{{ actionLabel }}</button>
          </div>
        </template>
      </div>
      <p class="text-xs text-slate-600">Triés par zone, disponibilité puis charge. Un livreur hors ligne ou en MAD reste assignable, après confirmation.</p>
    </div>
  </Modal>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import Modal from './Modal.vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { confirmDialog } from '../composables/useDialog'

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: 'Choisir un livreur' },
  subtitle: { type: String, default: '' },
  // Zone de l'étape (ramassage : zone du client ; livraison : zone du destinataire) pour les suggestions
  zone: { type: String, default: '' },
  currentId: { type: Number, default: null },
  actionLabel: { type: String, default: 'Assigner' },
  // Ce qu'on confie, pour la confirmation (« cette course », « ces 4 courses »)
  what: { type: String, default: 'cette course' },
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'pick'])

const STATES = {
  available: { label: 'Disponible', class: 'bg-emerald-100 text-emerald-800' },
  mission: { label: 'En mission', class: 'bg-blue-100 text-blue-800' },
  mad: { label: 'MAD', class: 'bg-violet-100 text-violet-800' },
  offline: { label: 'Hors ligne', class: 'bg-slate-200 text-slate-700' },
  idle: { label: 'Sans mission', class: 'bg-slate-100 text-slate-700' },
}
const filters = computed(() => [
  { value: 'all', label: 'Tous' },
  { value: 'available', label: 'Disponibles' },
  ...(props.zone ? [{ value: 'zone', label: 'Dans la zone' }] : []),
  { value: 'light', label: 'Peu chargés (2 ou moins)' },
])

const rows = ref(null)
const error = ref('')
const search = ref('')
const filter = ref('all')
const searchInput = ref(null)
// Liste du jour gardée une minute : ouvrir la fenêtre plusieurs fois de suite ne recharge pas tout
let loadedAt = 0

async function load() {
  if (rows.value && Date.now() - loadedAt < 60000) return
  error.value = ''
  try {
    rows.value = (await http.get('/couriers/activity')).data.data
    loadedAt = Date.now()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

watch(() => props.open, async (open) => {
  if (!open) return
  search.value = ''
  filter.value = 'all'
  load()
  await nextTick()
  searchInput.value?.focus()
})

const initials = (name) => (name || '').split(/[\s-]+/).filter(Boolean).map((p) => p[0]).slice(0, 2).join('').toUpperCase()
const hour = (v) => new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', ' h ')
const done = (c) => (c.deliveries?.done || 0) + (c.pickups?.done || 0)
const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const inZone = (c) => !!props.zone && (c.zones || []).some((z) => norm(z) === norm(props.zone))
// Ordre : dans la zone, disponible, en mission, MAD, hors ligne ; puis le moins chargé
const RANK = { available: 0, idle: 1, mission: 2, mad: 6, offline: 8 }
const score = (c) => (inZone(c) ? 0 : 10) + (RANK[c.state] ?? 5) + (c.remaining || 0)

const sorted = computed(() => {
  const q = norm(search.value.trim())
  return (rows.value || [])
    .filter((c) => !q || norm([c.name, c.phone, ...(c.zones || [])].join(' ')).includes(q))
    .filter((c) => filter.value !== 'available' || c.state === 'available')
    .filter((c) => filter.value !== 'zone' || inZone(c))
    .filter((c) => filter.value !== 'light' || ((c.remaining || 0) <= 2 && c.state !== 'offline'))
    .sort((a, b) => score(a) - score(b) || a.name.localeCompare(b.name))
})
// Suggérés : dans la zone, disponibles ou en mission, peu chargés (2 au plus)
const sections = computed(() => {
  const suggested = sorted.value
    .filter((c) => c.id !== props.currentId && inZone(c) && ['available', 'mission', 'idle'].includes(c.state) && (c.remaining || 0) <= 3)
    .slice(0, 2)
  const others = sorted.value.filter((c) => !suggested.includes(c))
  return [
    ...(suggested.length ? [{ label: `Suggérés : zone ${props.zone}, peu chargés`, items: suggested }] : []),
    ...(others.length ? [{ label: `${suggested.length ? 'Les autres' : 'Livreurs'} (${others.length})`, items: others }] : []),
  ]
})

// Hors ligne ou réservé en MAD : on demande confirmation avant de lui confier la course
async function pick(c) {
  if (c.state === 'offline' && !(await confirmDialog(`${c.name} est hors ligne. Voulez-vous vraiment lui assigner ${props.what} ?`, { okLabel: 'Oui, assigner' }))) return
  if (c.state === 'mad' && !(await confirmDialog(`${c.name} est assigné(e) en MAD (mise à disposition d'un client). Voulez-vous vraiment lui assigner ${props.what} ?`, { okLabel: 'Oui, assigner' }))) return
  emit('pick', c)
}
</script>
