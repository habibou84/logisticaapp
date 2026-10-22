<template>
  <div class="space-y-4">
    <!-- Distribution à l'unité sur Abidjan -->
    <section class="card p-4 space-y-3">
      <div>
        <h2 class="font-semibold">Distribution à l'unité (Abidjan)</h2>
        <p class="text-sm text-gray-600">
          Clients de distribution sans contrat : prix de chaque envoi selon le délai choisi. Case vide = pas proposé.
          Au-delà de 5 kg, le kilogramme est facturé au prix de la formule du client.
        </p>
      </div>
      <div class="overflow-x-auto">
        <table class="text-sm">
          <thead class="text-left text-gray-600">
            <tr><th class="p-2">Délai</th><th class="p-2">Pli (0-500 g)</th><th class="p-2">Colis (0-5 kg)</th><th class="p-2">Chèque</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in distribution" :key="row.delivery_delay">
              <th class="p-2 text-left font-medium">{{ row.label }}</th>
              <td v-for="t in TYPES" :key="t" class="p-1">
                <input v-model="row.prices[t]" type="number" min="0" class="input w-28" :aria-label="`${t} en ${row.label}`" @input="distributionDirty = true">
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <button class="btn-primary" :disabled="!distributionDirty || saving" @click="saveDistribution">Enregistrer les prix à l'unité</button>
    </section>

    <!-- Villes de l'intérieur -->
    <section class="card p-4 space-y-3">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 class="font-semibold">Villes de l'intérieur</h2>
          <p class="text-sm text-gray-600">
            Clients sous formule (distribution, MAD) : prix d'un pli (0-500 g), d'un colis (0-5 kg) et de chaque kilogramme
            au-delà de 5 kg, pour chaque délai. Chaque ville reçoit sa zone de livraison, à choisir sur la course.
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <a href="/api/v1/interior-rates/template" class="btn-secondary" @click.prevent="downloadTemplate">Modèle CSV</a>
          <label class="btn-secondary cursor-pointer">
            Importer un fichier…
            <input type="file" accept=".csv,.xlsx,.txt" class="hidden" @change="pickFile">
          </label>
        </div>
      </div>

      <div v-if="report" class="rounded border p-3 text-sm space-y-1" :class="report.applied ? 'bg-emerald-50 border-emerald-200' : 'bg-sky-50 border-sky-200'">
        <p class="font-medium">{{ report.applied ? 'Import terminé' : 'Aperçu de l\'import (rien n\'est encore enregistré)' }} : {{ fileName }}</p>
        <p>{{ report.created }} tarif(s) nouveau(x), {{ report.updated }} mis à jour<template v-if="report.zones_created.length"> · zones créées : {{ report.zones_created.join(', ') }}</template>.</p>
        <ul v-if="report.errors.length" class="text-red-700 list-disc pl-5">
          <li v-for="e in report.errors" :key="e">{{ e }}</li>
        </ul>
        <div v-if="!report.applied" class="flex gap-2 pt-1">
          <button class="btn-primary" :disabled="importing || !(report.created + report.updated)" @click="runImport(false)">Importer {{ report.created + report.updated }} tarif(s)</button>
          <button class="btn-secondary" @click="clearImport">Annuler</button>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Ville</th><th class="p-2">Délai</th><th class="p-2">Pli</th><th class="p-2">Colis</th><th class="p-2">Kg en plus</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="r in interior" :key="r.id">
              <td class="p-2 font-medium">{{ r.city }}</td>
              <td data-label="Délai" class="p-2">{{ r.delivery_delay.replace('h', ' h') }}</td>
              <td v-for="f in PRICE_FIELDS" :key="f" :data-label="LABELS[f]" class="p-1">
                <input v-model.number="r[f]" type="number" min="0" class="input w-24" @change="updateRate(r)">
              </td>
              <td class="p-2 text-right"><button class="text-red-600" @click="removeRate(r)">Retirer</button></td>
            </tr>
            <tr v-if="!interior.length"><td colspan="6" class="p-4 text-center text-gray-500">Aucune ville : ajoutez-en une ou importez un fichier.</td></tr>
          </tbody>
        </table>
      </div>

      <form class="flex flex-wrap items-end gap-2 border-t pt-3" @submit.prevent="addRate">
        <div><label class="label" for="ir-city">Ville</label><input id="ir-city" v-model="draft.city" class="input w-40" required></div>
        <div>
          <label class="label" for="ir-delay">Délai</label>
          <select id="ir-delay" v-model="draft.delivery_delay" class="input w-auto"><option value="24h">24 h</option><option value="48h">48 h</option><option value="72h">72 h</option></select>
        </div>
        <div v-for="f in PRICE_FIELDS" :key="f">
          <label class="label" :for="`ir-${f}`">{{ LABELS[f] }}</label>
          <input :id="`ir-${f}`" v-model.number="draft[f]" type="number" min="0" class="input w-24" :required="f !== 'extra_kg_price'">
        </div>
        <button class="btn-secondary">+ Ajouter</button>
      </form>
    </section>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'

const TYPES = ['pli', 'colis', 'cheque']
const PRICE_FIELDS = ['pli_price', 'colis_price', 'extra_kg_price']
const LABELS = { pli_price: 'Pli (F)', colis_price: 'Colis (F)', extra_kg_price: 'Kg en plus (F)' }

const toasts = useToastStore()
const distribution = ref([])
const distributionDirty = ref(false)
const saving = ref(false)
const interior = ref([])
const draft = reactive({ city: '', delivery_delay: '24h', pli_price: null, colis_price: null, extra_kg_price: 0 })
const report = ref(null)
const fileName = ref('')
const importing = ref(false)
let pendingFile = null

async function load() {
  const [d, i] = await Promise.all([http.get('/distribution-rates'), http.get('/interior-rates')])
  distribution.value = d.data.data
  interior.value = i.data.data
}

async function saveDistribution() {
  saving.value = true
  try {
    const rates = distribution.value.flatMap((row) => TYPES.map((t) => ({
      delivery_delay: row.delivery_delay,
      shipment_type: t,
      price: row.prices[t] === '' || row.prices[t] === null ? null : Number(row.prices[t]),
    })))
    distribution.value = (await http.put('/distribution-rates', { rates })).data.data
    distributionDirty.value = false
    toasts.success('Prix à l\'unité enregistrés.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function addRate() {
  try {
    await http.post('/interior-rates', { ...draft, extra_kg_price: draft.extra_kg_price || 0 })
    Object.assign(draft, { city: '', pli_price: null, colis_price: null, extra_kg_price: 0 })
    interior.value = (await http.get('/interior-rates')).data.data
    toasts.success('Tarif ajouté.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function updateRate(rate) {
  try {
    await http.patch(`/interior-rates/${rate.id}`, { pli_price: rate.pli_price || 0, colis_price: rate.colis_price || 0, extra_kg_price: rate.extra_kg_price || 0 })
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function removeRate(rate) {
  if (!window.confirm(`Retirer le tarif ${rate.city} en ${rate.delivery_delay} ?`)) return
  await http.delete(`/interior-rates/${rate.id}`)
  interior.value = interior.value.filter((r) => r.id !== rate.id)
}

async function downloadTemplate() {
  const { data } = await http.get('/interior-rates/template', { responseType: 'blob' })
  const url = URL.createObjectURL(data)
  Object.assign(document.createElement('a'), { href: url, download: 'modele-tarifs-interieur.csv' }).click()
  URL.revokeObjectURL(url)
}

function pickFile(event) {
  pendingFile = event.target.files[0]
  event.target.value = ''
  if (!pendingFile) return
  fileName.value = pendingFile.name
  runImport(true)
}

async function runImport(dryRun) {
  importing.value = true
  try {
    const body = new FormData()
    body.append('file', pendingFile)
    body.append('dry_run', dryRun ? '1' : '0')
    const { data } = await http.post('/interior-rates/import', body)
    report.value = { ...data.data, applied: !dryRun }
    if (!dryRun) {
      pendingFile = null
      interior.value = (await http.get('/interior-rates')).data.data
    }
  } catch (e) {
    report.value = null
    toasts.error(apiErrorMessage(e))
  } finally {
    importing.value = false
  }
}

function clearImport() {
  report.value = null
  pendingFile = null
}

onMounted(load)
</script>
