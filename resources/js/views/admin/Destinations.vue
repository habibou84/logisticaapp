<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">Annuaire des destinations</h1>
        <p class="text-sm text-gray-600">Partagé par tous les clients de distribution et de MAD : proposé pendant la saisie d'une course, enrichi par chaque course, positions confirmées par les livreurs.</p>
      </div>
      <div v-if="auth.can('merchants.manage')" class="flex gap-2">
        <button type="button" class="btn-secondary" @click="importOpen = true">⬆️ Importer</button>
        <button type="button" class="btn-primary" @click="edit(null)">+ Nouvelle destination</button>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <input v-model="search" class="input w-full sm:w-72" placeholder="Nom, sigle, quartier, numéro…" @input="debounced">
      <button
        v-for="f in FILTERS" :key="f.value" type="button"
        :class="['rounded-full px-3 py-1 text-sm', filter === f.value ? 'bg-slate-900 text-white' : 'bg-white ring-1 ring-slate-300']"
        @click="filter = f.value; load()"
      >
        {{ f.label }} <span class="opacity-70">{{ counts[f.value] ?? '' }}</span>
      </button>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm min-w-[52rem]">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Destination</th><th class="p-2">Commune, adresse</th><th class="p-2">Téléphones</th><th class="p-2">Position</th><th class="p-2 text-right">Livraisons</th><th /></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="d in rows" :key="d.id" :class="d.status === 'pending' ? 'bg-amber-50/50' : ''">
            <td class="p-2">
              <b>{{ d.acronym ? d.acronym + ' · ' : '' }}</b>{{ d.name }}
              <span v-if="d.status === 'pending'" class="ml-1 rounded-full bg-amber-100 px-2 text-xs text-amber-800">à valider</span>
              <span v-if="d.sector" class="block text-xs text-gray-500">{{ d.sector }}</span>
            </td>
            <td class="p-2">{{ d.zone_name || d.city || '—' }}<template v-if="d.district"> · {{ d.district }}</template>
              <span class="block max-w-xs truncate text-xs text-gray-500">{{ d.address }}</span></td>
            <td class="p-2 whitespace-nowrap">
              <span v-for="p in d.phones" :key="p" class="block">{{ p }}</span>
              <span v-if="d.phone_check" class="mt-0.5 inline-block rounded-full bg-amber-100 px-2 text-xs text-amber-900" :title="d.phones_raw">⚠️ à vérifier : {{ d.phones_raw }}</span>
              <span v-if="!d.phones.length && !d.phone_check" class="text-gray-400">—</span>
            </td>
            <td class="p-2">
              <span v-if="d.location_conflict" class="rounded-full bg-red-100 px-2 text-xs text-red-800">écart à trancher</span>
              <a v-else-if="d.lat" :href="mapsLink(d.lat, d.lng)" target="_blank" class="text-emerald-700">✓ {{ d.location_source === 'staff' ? 'posée' : 'confirmée' }}<span class="block text-xs text-gray-500">{{ d.located_at ? date(d.located_at) : '' }}</span></a>
              <span v-else class="text-gray-400">—</span>
            </td>
            <td class="p-2 text-right">{{ d.deliveries_count }}</td>
            <td class="p-2 text-right"><button v-if="auth.can('merchants.manage')" type="button" class="text-blue-600" @click="open(d)">Modifier</button></td>
          </tr>
          <tr v-if="!loading && !rows.length"><td colspan="6" class="p-8 text-center text-gray-500">Aucune destination{{ search ? ' pour cette recherche' : '' }}. Importez l'ancienne base ou laissez les courses l'enrichir.</td></tr>
        </tbody>
      </table>
    </div>
    <Pagination v-if="meta.last_page > 1" :meta="meta" @change="load" />

    <DestinationModal :open="modalOpen" :destination="editing" :zones="zones" @close="modalOpen = false" @saved="saved" />
    <DestinationImport :open="importOpen" @close="importOpen = false" @imported="importOpen = false; load()" />
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import Pagination from '../../components/Pagination.vue'
import DestinationModal from '../../components/destinations/DestinationModal.vue'
import DestinationImport from '../../components/destinations/DestinationImport.vue'
import { date, mapsLink } from '../../utils/format'

const FILTERS = [
  { value: 'all', label: 'Toutes' }, { value: 'check', label: 'Numéros à vérifier' }, { value: 'no_location', label: 'Sans position' },
  { value: 'located', label: 'Position confirmée' }, { value: 'conflict', label: 'Écart de position' }, { value: 'pending', label: 'À valider' }, { value: 'no_zone', label: 'Sans commune' },
]
const auth = useAuthStore()
const route = useRoute()
const rows = ref([])
const counts = ref({})
const meta = ref({ current_page: 1, last_page: 1 })
const search = ref('')
const filter = ref(FILTERS.some((f) => f.value === route.query.filtre) ? route.query.filtre : 'all')
const loading = ref(false)
const zones = ref([])
const modalOpen = ref(false)
const editing = ref(null)
const importOpen = ref(false)

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await http.get('/destinations', { params: { search: search.value || undefined, filtre: filter.value, page } })
    rows.value = data.data
    counts.value = data.counts
    meta.value = data.meta
  } finally {
    loading.value = false
  }
}
let timer
const debounced = () => { clearTimeout(timer); timer = setTimeout(load, 300) }

function edit(d) {
  editing.value = d
  modalOpen.value = true
}
async function open(d) {
  edit((await http.get(`/destinations/${d.id}`)).data.data)
}
function saved(d, stay = false) {
  if (stay && d) editing.value = d
  else modalOpen.value = false
  load(meta.value.current_page)
}

onMounted(async () => {
  load()
  zones.value = (await http.get('/zones')).data.data
  if (route.query.id) open({ id: route.query.id })
})
</script>
