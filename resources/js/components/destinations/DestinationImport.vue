<template>
  <Modal :open="open" title="Importer des destinations" @close="$emit('close')">
    <div class="space-y-3 text-sm">
      <p class="text-gray-600">Export SQL de l'ancienne base (table <code>base_clients</code>) ou fichier CSV avec les colonnes nom, ville, commune, adresse, contact, secteur, whatsapp, email.</p>
      <p class="text-gray-600">Les numéros qui ne sont pas au format à 10 chiffres sont gardés tels quels, « à vérifier » : ils se corrigent dès qu'un bon numéro est saisi sur une course.</p>
      <input type="file" accept=".sql,.csv,.txt" class="block" @change="pick">
      <button type="button" class="btn-secondary" :disabled="!file || busy" @click="run(true)">1. Essayer (rien n'est enregistré)</button>

      <div v-if="report" class="rounded-lg border border-slate-200 p-3 space-y-2">
        <p class="font-semibold">{{ report.dry_run ? 'Ce qui serait fait' : 'Import terminé' }}</p>
        <ul class="grid grid-cols-2 gap-x-4">
          <li>Lignes lues : <b>{{ report.rows }}</b></li>
          <li>Destinations {{ report.dry_run ? 'à créer' : 'créées' }} : <b>{{ report.created }}</b></li>
          <li>Doublons regroupés : <b>{{ report.merged }}</b></li>
          <li>Déjà dans l'annuaire : <b>{{ report.already }}</b></li>
          <li>Numéros bons : <b>{{ report.phones_ok }}</b></li>
          <li>Numéros à vérifier : <b class="text-amber-700">{{ report.phones_check }}</b></li>
          <li>Sans numéro : <b>{{ report.no_phone }}</b></li>
          <li>Commune trouvée : <b>{{ report.zone_found }}</b> / {{ report.zone_found + report.zone_missing }}</li>
        </ul>
        <details v-if="Object.keys(report.unknown_communes).length">
          <summary class="cursor-pointer">Communes non reconnues (ville gardée)</summary>
          <p class="text-xs text-gray-600">{{ Object.entries(report.unknown_communes).map(([k, v]) => `${k} (${v})`).join(', ') }}</p>
        </details>
        <details v-if="Object.keys(report.cities).length">
          <summary class="cursor-pointer">Villes corrigées</summary>
          <p class="text-xs text-gray-600">{{ Object.entries(report.cities).map(([k, v]) => `${k} (${v})`).join(', ') }}</p>
        </details>
        <details v-if="report.samples.length">
          <summary class="cursor-pointer">Exemples</summary>
          <ul class="text-xs text-gray-600 space-y-1">
            <li v-for="s in report.samples" :key="s.name"><b>{{ s.name }}</b> · {{ s.phones.join(', ') || '—' }}<template v-if="s.check"> · <span class="text-amber-700">à vérifier : {{ s.raw }}</span></template></li>
          </ul>
        </details>
        <button v-if="report.dry_run && report.created" type="button" class="btn-primary w-full" :disabled="busy" @click="run(false)">2. Importer {{ report.created }} destinations</button>
      </div>
      <p v-if="error" class="field-error">{{ error }}</p>
    </div>
  </Modal>
</template>

<script setup>
import { ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../Modal.vue'

const props = defineProps({ open: Boolean })
const emit = defineEmits(['close', 'imported'])
const file = ref(null)
const report = ref(null)
const busy = ref(false)
const error = ref('')

watch(() => props.open, (v) => { if (v) { file.value = null; report.value = null; error.value = '' } })
function pick(e) {
  file.value = e.target.files[0] || null
  report.value = null
}

async function run(dryRun) {
  busy.value = true
  error.value = ''
  try {
    const body = new FormData()
    body.append('file', file.value)
    body.append('dry_run', dryRun ? '1' : '0')
    report.value = (await http.post('/destinations/import', body)).data.data
    if (!dryRun) setTimeout(() => emit('imported'), 1500)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>
