<template>
  <div class="space-y-4 max-w-6xl">
    <div>
      <h1 v-if="!embedded" class="text-xl font-bold">Pointages des livreurs</h1>
      <p class="text-sm text-slate-600">
        Chaque livreur pointe son arrivée sur son lieu de début : l'agence (distribution, e-commerce), le client en MAD,
        ou la première étape de son parcours. Le pointage n'est accepté qu'à moins de {{ board?.settings.radius ?? 50 }} m du lieu.
      </p>
    </div>

    <p v-if="board && !board.settings.agency_located" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
      ⚠️ L'agence n'a pas encore de position sur la carte : les livreurs ne peuvent pas y pointer.
      <RouterLink to="/admin/reglages/pointage" class="font-semibold underline">Placer l'agence</RouterLink>
    </p>

    <div class="flex flex-wrap items-center gap-2">
      <button class="btn-secondary" aria-label="Jour précédent" @click="move(-1)">←</button>
      <input v-model="date" type="date" class="input w-auto" aria-label="Jour" @change="load">
      <button class="btn-secondary" aria-label="Jour suivant" @click="move(1)">→</button>
      <button v-for="f in filters" :key="f.value" :class="['btn whitespace-nowrap', filter === f.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']" @click="filter = f.value">
        {{ f.label }}<span v-if="f.value !== 'all'" class="ml-1 opacity-70">{{ board?.counts[f.value] ?? 0 }}</span>
      </button>
    </div>

    <p v-if="loading" class="py-8 text-center text-slate-500">Chargement…</p>
    <p v-else-if="error" class="py-8 text-center text-red-600">{{ error }}</p>
    <p v-else-if="!visible.length" class="py-8 text-center text-slate-500">Aucun livreur dans cette liste.</p>

    <section v-for="r in visible" :key="r.courier_id" class="card p-4 flex flex-wrap items-start gap-4">
      <div class="min-w-0 flex-1 space-y-1">
        <p class="font-semibold">
          🛵 {{ r.name }}
          <span :class="['ml-1 rounded-full px-2 py-0.5 text-xs font-medium', SHIFT_STATES[r.state].cls]">{{ SHIFT_STATES[r.state].label }}</span>
          <span v-if="r.shift?.method === 'manual'" class="ml-1 rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800">Manuel</span>
        </p>
        <p v-if="r.phone" class="text-sm"><a :href="telLink(r.phone)" class="text-blue-600">{{ r.phone }}</a></p>
        <p v-if="r.place" class="text-sm text-slate-700">
          📍 {{ r.place.label }}
          <span v-if="r.place.routine" class="text-slate-600"> · étape 1 du parcours « {{ r.place.routine }} »</span>
          <span v-if="r.place.merchant && !r.place.routine" class="text-slate-600"> · MAD</span>
          <span v-if="!r.place.located" class="text-amber-700"> · sans position sur la carte</span>
          <template v-if="r.place.expected_at"> · attendu à <strong>{{ hm(r.place.expected_at) }}</strong></template>
        </p>
        <p v-else-if="!r.shift" class="text-sm text-slate-600">Pas de pointage prévu ce jour-là (absent ou jour non travaillé).</p>
        <p v-if="r.shift" class="text-sm">
          ✅ Pointé à <strong>{{ hm(r.shift.clocked_in_at) }}</strong>
          <span v-if="r.shift.late_minutes" class="text-amber-700"> · {{ r.shift.late_minutes }} min de retard</span>
          <span v-if="r.shift.distance !== null" class="text-slate-600"> · à {{ meters(r.shift.distance) }} du lieu (GPS ± {{ r.shift.accuracy }} m)</span>
          <span v-if="r.shift.method === 'manual'" class="text-slate-600"> · par {{ r.shift.recorded_by }} : « {{ r.shift.reason }} »</span>
        </p>
        <p v-else-if="r.state === 'missing'" class="text-sm text-red-700">Pas encore pointé<template v-if="r.late_now"> · {{ r.late_now }} min après l'heure</template></p>
        <p v-if="r.attempts_count" class="text-xs text-slate-600">
          {{ r.attempts_count }} tentative{{ r.attempts_count > 1 ? 's' : '' }} refusée{{ r.attempts_count > 1 ? 's' : '' }} :
          {{ r.attempts.map((a) => `${a.at} ${a.reason_label.toLowerCase()}${a.distance !== null ? ` (${meters(a.distance)})` : ''}`).join(', ') }}
        </p>
      </div>
      <div class="flex gap-2">
        <button v-if="!r.shift && board.is_today" class="btn-secondary" @click="openManual(r)">Pointer à la main</button>
        <button v-if="r.shift" class="btn-secondary text-red-700" @click="cancel(r)">Annuler le pointage</button>
      </div>
    </section>

    <Modal :open="!!manual" :title="manual ? `Pointer ${manual.name} à la main` : ''" @close="manual = null">
      <form v-if="manual" class="space-y-3" @submit.prevent="saveManual">
        <p class="text-sm text-slate-600">À utiliser quand le livreur est bien arrivé mais ne peut pas pointer (GPS en panne, lieu sans position…). Le motif est gardé.</p>
        <label class="block">
          <span class="label">Heure d'arrivée</span>
          <input v-model="manualForm.time" type="time" class="input w-auto">
        </label>
        <label class="block">
          <span class="label">Motif</span>
          <input v-model="manualForm.reason" type="text" maxlength="255" required class="input" placeholder="Ex. : téléphone sans GPS, vu sur place par le chef d'équipe">
        </label>
        <p v-if="manualError" class="text-sm text-red-600">{{ manualError }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="manual = null">Fermer</button>
          <button class="btn-primary" :disabled="saving">Pointer</button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useToastStore } from '../../stores/toasts'
import { telLink, today } from '../../utils/format'
import { SHIFT_STATES, hm, meters } from '../../utils/shifts'
import { confirmDialog } from '../../composables/useDialog'

defineProps({ embedded: { type: Boolean, default: false } })
const toasts = useToastStore()
const board = ref(null)
const loading = ref(true)
const error = ref('')
const date = ref(today())
const filter = ref('all')
const filters = [
  { value: 'all', label: 'Tous' },
  { value: 'missing', label: 'Pas pointés' },
  { value: 'late', label: 'En retard' },
  { value: 'waiting', label: 'Attendus' },
  { value: 'on_time', label: 'À l\'heure' },
  { value: 'none', label: 'Pas prévus' },
]
const visible = computed(() => (board.value?.rows || []).filter((r) => filter.value === 'all' || r.state === filter.value))

async function load() {
  loading.value = true
  try {
    board.value = (await http.get('/shifts', { params: { date: date.value } })).data.data
    error.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger les pointages.')
  } finally {
    loading.value = false
  }
}

function move(days) {
  const d = new Date(`${date.value}T12:00:00`)
  d.setDate(d.getDate() + days)
  date.value = d.toISOString().slice(0, 10)
  load()
}

const manual = ref(null)
const manualForm = ref({ time: '', reason: '' })
const manualError = ref('')
const saving = ref(false)
function openManual(r) {
  manual.value = r
  manualForm.value = { time: new Date().toTimeString().slice(0, 5), reason: '' }
  manualError.value = ''
}
async function saveManual() {
  saving.value = true
  try {
    await http.post(`/couriers/${manual.value.courier_id}/shift`, manualForm.value)
    toasts.success(`${manual.value.name} est pointé et en service.`)
    manual.value = null
    load()
  } catch (e) {
    manualError.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function cancel(r) {
  if (!(await confirmDialog(`Annuler le pointage de ${r.name} ? Il pourra pointer à nouveau.`))) return
  try {
    await http.delete(`/courier-shifts/${r.shift.id}`)
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>
