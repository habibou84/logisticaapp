<template>
  <div class="space-y-3">
    <div class="flex flex-wrap items-end gap-2">
      <div class="flex gap-2 overflow-x-auto">
        <button
          v-for="s in states"
          :key="s.value"
          :class="['btn whitespace-nowrap', filters.state === s.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']"
          @click="filters.state = s.value; load(1)"
        >
          {{ s.label }}
        </button>
      </div>
      <select v-model="filters.type" class="input w-auto" aria-label="Type d'événement" @change="load(1)">
        <option value="">Tous les types</option>
        <option v-for="t in types" :key="t.key" :value="t.key">{{ t.icon }} {{ t.label }}</option>
      </select>
      <select v-model="filters.courier_id" class="input w-auto" aria-label="Livreur" @change="load(1)">
        <option value="">Tous les livreurs</option>
        <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <RouterLink v-if="auth.can('field_events.manage')" to="/admin/reglages/evenements-terrain" class="ml-auto text-sm text-blue-600">⚙️ Types et effets automatiques</RouterLink>
    </div>

    <FieldEventCard
      v-for="e in events"
      :key="e.id"
      :event="e"
      :open="e.id === focusId"
      :highlighted="highlighted.has(e.id) || e.id === focusId"
      @updated="updated"
    />

    <div v-if="loaded && !events.length" class="card p-8 text-center text-gray-500">
      {{ filters.state === 'open' ? '🎉 Aucun événement en cours sur le terrain.' : 'Aucun événement pour ces critères.' }}
    </div>

    <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between text-sm">
      <span class="text-gray-500">Page {{ meta.current_page }} / {{ meta.last_page }} · {{ meta.total }} événement(s)</span>
      <div class="flex gap-2">
        <button class="btn-secondary" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">Précédent</button>
        <button class="btn-secondary" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Suivant</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { nextTick, onMounted, reactive, ref, watch } from 'vue'
import http from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { useFieldReportStore } from '../stores/fieldReports'
import FieldEventCard from './FieldEventCard.vue'

/**
 * Événements terrain des livreurs (Remontées terrain › Événements) : en cours d'abord, les plus graves en tête.
 */
const props = defineProps({
  couriers: { type: Array, default: () => [] },
  focus: { type: [Number, String], default: null },
})

const auth = useAuthStore()
const store = useFieldReportStore()
const events = ref([])
const types = ref([])
const meta = ref(null)
const loaded = ref(false)
const highlighted = ref(new Set())
const focusId = ref(props.focus ? Number(props.focus) : null)
const filters = reactive({ state: 'open', type: '', courier_id: '' })
const states = [
  { value: 'open', label: 'En cours' },
  { value: 'resolved', label: 'Réglés' },
  { value: 'all', label: 'Tous' },
]

async function load(page = 1) {
  const params = Object.fromEntries(Object.entries({ ...filters, page }).filter(([, v]) => v !== ''))
  const { data } = await http.get('/field-events', { params })
  const known = new Set(events.value.map((e) => e.id))
  events.value = data.data
  meta.value = data.meta
  types.value = data.types
  store.setEvents(data.open)
  if (loaded.value) highlighted.value = new Set(data.data.filter((e) => !known.has(e.id)).map((e) => e.id))
  loaded.value = true
}

// Lien d'une alerte : l'événement est ouvert, même s'il est déjà réglé
async function showFocused() {
  if (!focusId.value) return
  if (!events.value.some((e) => e.id === focusId.value)) {
    const { data } = await http.get(`/field-events/${focusId.value}`).catch(() => ({ data: null }))
    if (data) events.value = [data.data, ...events.value]
  }
  await nextTick()
  document.getElementById(`evenement-${focusId.value}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function updated(event, open) {
  const i = events.value.findIndex((e) => e.id === event.id)
  if (i >= 0) events.value[i] = event
  store.setEvents(open)
}

watch(() => props.focus, async (id) => {
  focusId.value = id ? Number(id) : null
  await showFocused()
})
// Nouvel événement ou message reçu en direct
watch(() => store.eventsVersion, () => load(meta.value?.current_page || 1))

onMounted(async () => {
  await load()
  await showFocused()
})
</script>
