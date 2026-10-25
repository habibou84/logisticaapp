<template>
  <Modal :open="open" :title="destination ? destination.label : 'Nouvelle destination'" @close="$emit('close')">
    <form class="space-y-3" @submit.prevent="save">
      <div class="grid grid-cols-[7rem_1fr] gap-3">
        <div><label class="label" for="ds-acr">Sigle</label><input id="ds-acr" v-model="form.acronym" class="input uppercase" maxlength="60" placeholder="ITWA"></div>
        <div><label class="label" for="ds-name">Nom</label><input id="ds-name" v-model="form.name" class="input" required maxlength="255"></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="ds-zone">Commune</label><ZoneSelect id="ds-zone" v-model="form.zone_id" :zones="zones" /></div>
        <div><label class="label" for="ds-district">Quartier</label><input id="ds-district" v-model="form.district" class="input" maxlength="120"></div>
      </div>
      <div><label class="label" for="ds-address">Adresse</label><textarea id="ds-address" v-model="form.address" rows="2" class="input" /></div>
      <div><label class="label" for="ds-landmark">Repère</label><input id="ds-landmark" v-model="form.landmark" class="input" maxlength="255"></div>

      <fieldset class="rounded-lg border border-slate-200 p-3 space-y-2">
        <legend class="px-1 text-sm font-medium">Téléphones</legend>
        <div class="grid grid-cols-3 gap-2">
          <input v-for="(f, i) in ['phone', 'phone2', 'phone3']" :key="f" v-model="form[f]" class="input" inputmode="tel" :placeholder="i === 0 ? '07 00 00 00 00' : 'Autre numéro'" :aria-label="`Téléphone ${i + 1}`">
        </div>
        <!-- Numéros d'origine douteux : gardés tels quels, une correction seulement proposée -->
        <div v-if="destination?.phone_check" class="rounded bg-amber-50 p-2 text-sm text-amber-900 space-y-1">
          <p>⚠️ Numéros d'origine à vérifier : <b>{{ destination.phones_raw }}</b></p>
          <p v-for="d in destination.doubtful" :key="d.raw" class="flex flex-wrap items-center gap-2">
            <span class="font-mono">{{ d.raw }}</span>
            <template v-if="d.suggestion">→ peut-être <span class="font-mono">{{ d.suggestion }}</span>
              <button type="button" class="text-blue-700 underline" @click="use(d.suggestion)">Utiliser</button></template>
            <span v-else class="text-amber-700">(illisible)</span>
          </p>
          <label class="flex items-center gap-2"><input v-model="form.phone_check" type="checkbox"> Encore à vérifier</label>
        </div>
      </fieldset>

      <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="ds-email">E-mail</label><input id="ds-email" v-model="form.email" type="email" class="input"></div>
        <div><label class="label" for="ds-sector">Secteur</label><input id="ds-sector" v-model="form.sector" class="input" maxlength="120"></div>
      </div>
      <label v-if="destination?.status === 'pending'" class="flex items-center gap-2 text-sm"><input v-model="validated" type="checkbox"> Valider : proposée aux clients</label>

      <!-- Position : confirmée sur place, historique, écart à trancher -->
      <section v-if="destination" class="rounded-lg border border-slate-200 p-3 space-y-2 text-sm">
        <p class="font-medium">📍 Position</p>
        <p v-if="destination.lat">
          <a :href="mapsLink(destination.lat, destination.lng)" target="_blank" class="text-blue-700">{{ destination.lat }}, {{ destination.lng }}</a>
          <span class="text-gray-500"> · {{ SOURCES[destination.location_source] || '' }}<template v-if="destination.located_by"> ({{ destination.located_by }})</template><template v-if="destination.located_at"> le {{ dateTime(destination.located_at) }}</template><template v-if="destination.location_accuracy"> · ± {{ destination.location_accuracy }} m</template></span>
        </p>
        <p v-else class="text-gray-500">Pas encore de position : le premier livreur sur place l'enregistrera.</p>
        <p v-if="destination.location_conflict" class="rounded bg-red-50 p-2 text-red-800">Positions qui ne concordent pas : choisissez la bonne ci-dessous.</p>
        <ul v-if="destination.locations?.length" class="divide-y">
          <li v-for="l in destination.locations.slice(0, 8)" :key="l.id" class="flex flex-wrap items-center justify-between gap-2 py-1">
            <span><a :href="mapsLink(l.lat, l.lng)" target="_blank" class="text-blue-700">{{ l.lat }}, {{ l.lng }}</a>
              <span class="text-gray-500"> · {{ l.by || '—' }} · {{ dateTime(l.at) }}<template v-if="l.accuracy"> · ± {{ l.accuracy }} m</template><template v-if="l.distance !== null"> · à {{ l.distance }} m</template></span>
              <span :class="['ml-1 rounded-full px-1.5 text-xs', OUTCOMES[l.outcome][1]]">{{ OUTCOMES[l.outcome][0] }}</span></span>
            <button v-if="l.outcome === 'conflict' || destination.location_conflict" type="button" class="text-xs text-blue-700" @click="pick(l)">Garder celle-ci</button>
          </li>
        </ul>
        <button v-if="destination.location_conflict" type="button" class="text-xs text-gray-600 underline" @click="keepCurrent">Garder la position actuelle</button>
      </section>

      <!-- Doublons -->
      <section v-if="destination" class="rounded-lg border border-slate-200 p-3 space-y-2 text-sm">
        <p class="font-medium">Doublon ?</p>
        <input v-model="dupQuery" class="input" placeholder="Chercher la fiche en double…" @input="searchDuplicates">
        <ul v-if="duplicates.length" class="divide-y">
          <li v-for="d in duplicates" :key="d.id" class="flex items-center justify-between gap-2 py-1">
            <span>{{ d.label }} <span class="text-gray-500">· {{ d.zone_name || d.city || '' }}</span></span>
            <button type="button" class="text-xs text-blue-700" @click="merge(d)">Regrouper ici</button>
          </li>
        </ul>
      </section>

      <p v-if="error" class="field-error">{{ error }}</p>
      <div class="flex items-center gap-2">
        <button class="btn-primary flex-1" :disabled="busy">{{ destination ? 'Enregistrer' : 'Ajouter' }}</button>
        <button v-if="destination" type="button" class="text-sm text-red-600" @click="remove">Supprimer</button>
      </div>
    </form>
  </Modal>
</template>

<script setup>
import { ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../Modal.vue'
import ZoneSelect from '../ZoneSelect.vue'
import { dateTime, mapsLink } from '../../utils/format'

const props = defineProps({ open: Boolean, destination: { type: Object, default: null }, zones: { type: Array, default: () => [] } })
const emit = defineEmits(['close', 'saved'])

const FIELDS = ['acronym', 'name', 'zone_id', 'district', 'address', 'landmark', 'phone', 'phone2', 'phone3', 'phone_check', 'email', 'sector']
const SOURCES = { courier: 'confirmée par un livreur', staff: 'posée par l\'équipe' }
const OUTCOMES = { replaced: ['retenue', 'bg-emerald-100 text-emerald-800'], kept: ['confirme', 'bg-slate-100'], conflict: ['écart', 'bg-red-100 text-red-800'], rejected: ['refusée', 'bg-slate-100'] }
const form = ref({})
const validated = ref(false)
const busy = ref(false)
const error = ref('')
const dupQuery = ref('')
const duplicates = ref([])

const display = (p) => (p ? p.replace(/^\+225/, '').replace(/(\d{2})(?=\d)/g, '$1 ') : '')
watch(() => [props.open, props.destination], () => {
  if (!props.open) return
  const d = props.destination || {}
  form.value = Object.fromEntries(FIELDS.map((k) => [k, ['phone', 'phone2', 'phone3'].includes(k) ? display(d[k]) : (d[k] ?? (k === 'phone_check' ? false : null))]))
  validated.value = false
  error.value = ''
  dupQuery.value = ''
  duplicates.value = []
}, { immediate: true })

function use(number) {
  const slot = ['phone', 'phone2', 'phone3'].find((f) => !form.value[f])
  if (slot) form.value[slot] = number
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const payload = { ...form.value, ...(validated.value ? { status: 'active' } : {}) }
    const { data } = props.destination ? await http.put(`/destinations/${props.destination.id}`, payload) : await http.post('/destinations', payload)
    emit('saved', data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function locate(payload) {
  try {
    emit('saved', (await http.post(`/destinations/${props.destination.id}/location`, payload)).data.data, true)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}
const pick = (l) => locate({ location_id: l.id })
const keepCurrent = () => locate({ clear_conflict: true })

let timer
function searchDuplicates() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    if (dupQuery.value.trim().length < 2) return (duplicates.value = [])
    const { data } = await http.get('/destinations', { params: { search: dupQuery.value, per_page: 8 } })
    duplicates.value = data.data.filter((d) => d.id !== props.destination.id)
  }, 250)
}

async function merge(d) {
  if (!confirm(`Regrouper « ${d.label} » dans cette fiche ? Ses numéros, courses et positions y passent, puis elle est supprimée.`)) return
  try {
    emit('saved', (await http.post(`/destinations/${props.destination.id}/merge`, { ids: [d.id] })).data.data, true)
    duplicates.value = []
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function remove() {
  if (!confirm('Supprimer cette destination de l\'annuaire ?')) return
  await http.delete(`/destinations/${props.destination.id}`)
  emit('saved', null)
}
</script>
