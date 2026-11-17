<template>
  <!-- Parcours d'un livreur en MAD : la routine du matin avant d'arriver chez le client. Il pointe à la 1re étape. -->
  <form class="space-y-4" @submit.prevent="submit">
    <label class="block">
      <span :class="c.label">Nom du parcours</span>
      <input v-model="form.name" type="text" maxlength="120" required :class="c.input" placeholder="Ex. : Tournée des agences du matin">
    </label>

    <div>
      <p :class="c.label">Jours</p>
      <div class="flex flex-wrap gap-2">
        <label v-for="d in DAYS" :key="d.value" :class="['flex cursor-pointer items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm ring-1', form.days.includes(d.value) ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']">
          <input v-model="form.days" type="checkbox" :value="d.value" class="sr-only"> {{ d.short }}
        </label>
      </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
      <label class="block">
        <span :class="c.label">Heure de départ</span>
        <input v-model="form.starts_at" type="time" :class="c.input">
        <span class="block text-xs text-slate-500">{{ plan.mad_from ? `Vide : ${plan.mad_from}, heure de début du contrat.` : 'Heure à laquelle le livreur doit pointer à la 1re étape.' }}</span>
      </label>
      <label class="block">
        <span :class="c.label">Livreur</span>
        <select v-model="form.courier_id" :class="c.input">
          <option :value="null">Tous les livreurs du contrat</option>
          <option v-for="k in plan.couriers" :key="k.id" :value="k.id">{{ k.name }}</option>
        </select>
      </label>
    </div>

    <div class="space-y-3">
      <p :class="c.label">Étapes, dans l'ordre <span class="font-normal text-slate-500">(le livreur pointe à la première)</span></p>
      <div v-for="(s, i) in form.stops" :key="s.key" class="space-y-2 rounded-xl p-3 ring-1 ring-slate-200">
        <div class="flex items-center gap-2">
          <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-bold text-white', i === 0 ? 'bg-emerald-600' : 'bg-slate-500']">{{ i + 1 }}</span>
          <select v-model="s.action" :class="[c.input, 'w-auto']" aria-label="Action">
            <option v-for="a in ACTIONS" :key="a.value" :value="a.value">{{ a.label }}</option>
          </select>
          <span class="flex-1 text-xs text-slate-500">{{ i === 0 ? 'Lieu de pointage' : '' }}</span>
          <button type="button" class="px-1 text-slate-500 disabled:opacity-30" :disabled="i === 0" aria-label="Monter" @click="move(i, -1)">↑</button>
          <button type="button" class="px-1 text-slate-500 disabled:opacity-30" :disabled="i === form.stops.length - 1" aria-label="Descendre" @click="move(i, 1)">↓</button>
          <button type="button" class="px-1 text-red-600" aria-label="Retirer l'étape" @click="form.stops.splice(i, 1)">✕</button>
        </div>

        <!-- Lieu : siège du client, lieu de l'annuaire, ou nouveau lieu placé sur la carte -->
        <div class="flex flex-wrap gap-3 text-sm">
          <label class="flex items-center gap-1"><input v-model="s.mode" type="radio" value="merchant"> Siège{{ plan.merchant ? ` de ${plan.merchant}` : '' }}</label>
          <label class="flex items-center gap-1"><input v-model="s.mode" type="radio" value="search"> Un lieu enregistré</label>
          <label class="flex items-center gap-1"><input v-model="s.mode" type="radio" value="new"> Nouveau lieu</label>
        </div>
        <p v-if="s.mode === 'merchant' && !plan.merchant_located" class="text-xs text-amber-700">Le siège n'a pas encore de position sur la carte.</p>

        <template v-if="s.mode === 'search'">
          <div v-if="s.destination_id" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm">
            <span class="min-w-0 flex-1"><strong>{{ s.label }}</strong><span v-if="s.address" class="block text-slate-500">{{ s.address }}</span>
              <span v-if="!s.located" class="block text-xs text-amber-700">Sans position sur la carte</span></span>
            <button type="button" class="font-semibold text-[var(--app-color,#1f2937)]" @click="s.destination_id = null">Changer</button>
          </div>
          <template v-else>
            <input v-model="s.query" type="search" :class="c.input" placeholder="Nom, sigle, quartier de l'agence…" @input="search(s)">
            <div v-if="s.results.length" class="max-h-48 divide-y overflow-y-auto rounded-lg ring-1 ring-slate-200">
              <button v-for="r in s.results" :key="r.id" type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50" @click="pick(s, r)">
                <strong>{{ r.label }}</strong> <span v-if="!r.located" class="text-xs text-amber-700">(sans position)</span>
                <span v-if="r.address" class="block text-slate-500">{{ r.address }}</span>
              </button>
            </div>
            <p v-else-if="s.query.length >= 2 && !s.searching" class="text-xs text-slate-500">Rien trouvé : choisissez « Nouveau lieu ».</p>
          </template>
        </template>

        <template v-if="s.mode === 'new'">
          <input v-model="s.new_place.name" type="text" maxlength="191" :class="c.input" placeholder="Nom du lieu (ex. : Agence Mamadou Cocody)">
          <input v-model="s.new_place.address" type="text" maxlength="255" :class="c.input" placeholder="Adresse, repère">
          <LocationPicker v-model="s.point" allow-link height="h-48" :button-class="mobile ? 'm-btn-secondary' : 'btn-secondary'" gps-label="📍 Je suis sur place : utiliser ma position" />
        </template>

        <div class="grid gap-2 sm:grid-cols-[1fr_auto]">
          <input v-model="s.instructions" type="text" maxlength="255" :class="c.input" placeholder="Consigne (ex. : demander Awa à l'accueil)">
          <input v-model="s.expected_time" type="time" :class="[c.input, 'sm:w-32']" aria-label="Heure de passage prévue" title="Heure de passage prévue (facultatif)">
        </div>
      </div>
      <button type="button" :class="c.secondary" @click="addStop">+ Ajouter une étape</button>
    </div>

    <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox"> Parcours actif</label>

    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    <div class="flex justify-end gap-2">
      <button type="button" :class="c.secondary" @click="$emit('cancel')">Annuler</button>
      <button :class="c.primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
    </div>
  </form>
</template>

<script setup>
import { computed, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import LocationPicker from '../LocationPicker.vue'
import { ACTIONS, DAYS } from '../../utils/shifts'

const props = defineProps({
  // Contrat : livreurs, heure de début, siège placé ou non
  plan: { type: Object, required: true },
  routine: { type: Object, default: null },
  // Adresses : création, puis modification (suivie de l'identifiant)
  createUrl: { type: String, required: true },
  updateUrl: { type: String, required: true },
  // Application client (mobile) ou back-office
  mobile: { type: Boolean, default: false },
})
const emit = defineEmits(['saved', 'cancel'])

const c = computed(() => (props.mobile
  ? { input: 'm-input', label: 'm-label', primary: 'm-btn-primary w-auto px-5', secondary: 'm-btn-secondary w-auto px-4' }
  : { input: 'input', label: 'label', primary: 'btn-primary', secondary: 'btn-secondary' }))

let keys = 0
function stopState(s = {}) {
  return {
    key: ++keys,
    mode: s.place_type === 'merchant' ? 'merchant' : (s.place_type ? 'search' : 'search'),
    destination_id: s.destination_id ?? null,
    label: s.label ?? '',
    address: s.address ?? '',
    located: s.lat != null,
    query: '',
    results: [],
    searching: false,
    new_place: { name: '', address: '' },
    point: null,
    action: s.action ?? 'pickup',
    instructions: s.instructions ?? '',
    expected_time: s.expected_time ?? '',
  }
}

const r = props.routine
const form = ref({
  name: r?.name ?? '',
  days: r?.days ? [...r.days] : (props.plan.mad_days?.length ? [...props.plan.mad_days] : [1, 2, 3, 4, 5]),
  starts_at: r?.starts_at ?? '',
  courier_id: r?.courier_id ?? null,
  is_active: r?.is_active ?? true,
  stops: r?.stops?.length ? r.stops.map(stopState) : [stopState(), stopState({ place_type: 'merchant', action: 'drop' })],
})
const saving = ref(false)
const error = ref('')

function addStop() {
  form.value.stops.push(stopState())
}
function move(i, d) {
  const list = form.value.stops
  ;[list[i], list[i + d]] = [list[i + d], list[i]]
}

let timer = null
function search(s) {
  clearTimeout(timer)
  if (s.query.trim().length < 2) {
    s.results = []
    return
  }
  s.searching = true
  timer = setTimeout(async () => {
    try {
      s.results = (await http.get('/routine-places', { params: { q: s.query } })).data.data
    } catch {
      s.results = []
    } finally {
      s.searching = false
    }
  }, 250)
}
function pick(s, place) {
  Object.assign(s, { destination_id: place.id, label: place.label, address: place.address, located: place.located, results: [], query: '' })
}

function payload() {
  return {
    name: form.value.name,
    days: form.value.days,
    starts_at: form.value.starts_at || null,
    courier_id: form.value.courier_id,
    is_active: form.value.is_active,
    stops: form.value.stops.map((s) => ({
      place_type: s.mode === 'merchant' ? 'merchant' : 'destination',
      destination_id: s.mode === 'search' ? s.destination_id : null,
      new_place: s.mode === 'new' ? { name: s.new_place.name, address: s.new_place.address || null, lat: s.point?.lat ?? null, lng: s.point?.lng ?? null } : null,
      action: s.action,
      instructions: s.instructions || null,
      expected_time: s.expected_time || null,
    })),
  }
}

async function submit() {
  error.value = ''
  const missing = form.value.stops.findIndex((s) => (s.mode === 'search' && !s.destination_id) || (s.mode === 'new' && (!s.new_place.name || !s.point)))
  if (!form.value.stops.length) return (error.value = 'Ajoutez au moins une étape.')
  if (missing >= 0) return (error.value = `Étape ${missing + 1} : choisissez un lieu, ou donnez le nom et la position du nouveau lieu.`)
  saving.value = true
  try {
    const { data } = props.routine
      ? await http.put(`${props.updateUrl}/${props.routine.id}`, payload())
      : await http.post(props.createUrl, payload())
    emit('saved', data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
