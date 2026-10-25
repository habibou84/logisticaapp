<template>
  <!-- Annuaire des destinations : la destination choisie remplit la fiche -->
  <div class="relative space-y-2">
    <div v-if="selected" :class="['rounded-xl p-3 text-sm', mobile ? 'bg-violet-50' : 'bg-violet-50 border border-violet-200']">
      <div class="flex items-start justify-between gap-2">
        <p>📇 <b>{{ selected.label }}</b><span class="text-gray-600"> · {{ [selected.zone_name || selected.city, selected.district].filter(Boolean).join(' · ') }}</span>
          <span v-if="selected.lat" class="block text-emerald-700">📍 Position confirmée{{ selected.located_at ? ` le ${date(selected.located_at)}` : '' }} : le livreur ira droit à la porte</span></p>
        <button type="button" class="shrink-0 text-xs text-blue-700" @click="$emit('clear')">Changer</button>
      </div>
      <!-- Numéro « à vérifier » : jamais repris, le bon numéro est à saisir (l'annuaire sera corrigé) -->
      <div v-if="selected.phone_check && !selected.phone" class="mt-2 rounded-lg bg-amber-100 p-2 text-amber-900">
        ⚠️ Numéro à vérifier dans l'annuaire : <b>{{ selected.phones_raw }}</b>. Saisissez le bon numéro, l'annuaire sera corrigé.
        <span v-for="d in selected.doubtful.filter((x) => x.suggestion)" :key="d.raw" class="block">Peut-être {{ d.suggestion }} <button type="button" class="underline" @click="$emit('use-phone', d.suggestion)">Utiliser</button></span>
      </div>
    </div>
    <template v-else>
      <label :class="mobile ? 'm-label' : 'label'" :for="inputId">📇 Chercher dans l'annuaire</label>
      <input
        :id="inputId" v-model="query" :class="mobile ? 'm-input' : 'input'" autocomplete="off"
        placeholder="Nom, sigle, quartier ou numéro (ex. : ITWA, Sitab, 27 22…)" @input="search" @blur="close"
      >
      <ul v-if="results.length" :class="['absolute z-30 mt-1 max-h-80 w-full overflow-y-auto rounded-xl bg-white shadow-lg ring-1 ring-slate-200', mobile ? 'text-base' : 'text-sm']">
        <li v-for="d in results" :key="d.id">
          <button type="button" class="w-full px-3 py-2 text-left hover:bg-slate-50 active:bg-slate-100" @mousedown.prevent="pick(d)">
            <span class="block font-medium">{{ d.label }}</span>
            <span class="block text-xs text-gray-500">{{ [d.zone_name || d.city, d.district, d.address].filter(Boolean).join(' · ') }}</span>
            <span class="flex flex-wrap gap-2 text-xs">
              <span v-if="d.phones.length">📞 {{ d.phones[0] }}</span>
              <span v-else-if="d.phone_check" class="text-amber-700">⚠️ numéro à vérifier</span>
              <span v-if="d.lat" class="text-emerald-700">📍 position confirmée</span>
              <span v-if="d.mine" class="text-violet-700">déjà livrée {{ d.mine }} fois pour {{ mobile ? 'vous' : 'ce client' }}</span>
            </span>
          </button>
        </li>
      </ul>
      <p v-else-if="searched && query.length >= 2" class="text-xs text-gray-500">Pas dans l'annuaire : remplissez la fiche, elle y sera ajoutée.</p>
    </template>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import http from '../../bootstrap/axios'
import { date } from '../../utils/format'

const props = defineProps({
  selected: { type: Object, default: null },
  merchantId: { type: Number, default: null },
  mobile: Boolean,
})
const emit = defineEmits(['pick', 'clear', 'use-phone'])
const inputId = `dest-${Math.random().toString(36).slice(2, 7)}`
const query = ref('')
const results = ref([])
const searched = ref(false)

let timer
function search() {
  clearTimeout(timer)
  searched.value = false
  if (query.value.trim().length < 2) return (results.value = [])
  timer = setTimeout(async () => {
    try {
      results.value = (await http.get('/destinations/suggest', { params: { q: query.value, merchant_id: props.merchantId || undefined } })).data.data
    } catch {
      results.value = []
    }
    searched.value = true
  }, 250)
}
function pick(d) {
  results.value = []
  query.value = ''
  emit('pick', d)
}
function close() {
  setTimeout(() => { results.value = [] }, 150)
}
</script>
