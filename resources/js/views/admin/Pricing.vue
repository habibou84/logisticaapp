<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Tarifs</h1>
      <div class="flex gap-2">
        <select v-model="gridId" class="input w-auto" @change="switchGrid">
          <option v-for="g in grids" :key="g.id" :value="g.id">{{ g.name }}{{ g.is_default ? ' (par défaut)' : '' }}</option>
        </select>
        <button class="btn-secondary" @click="newGrid">+ Grille négociée</button>
      </div>
    </div>

    <div v-if="grid" class="flex flex-wrap items-center gap-3 text-sm">
      <span class="text-gray-600">
        Grille « {{ grid.name }} »{{ grid.is_default ? ' : appliquée à tous les e-commerçants sans grille négociée' : ` : ${grid.merchants_count ?? 0} e-commerçant(s)` }}
      </span>
      <button class="text-blue-600" @click="renameGrid">Renommer</button>
      <button v-if="!grid.is_default" class="text-red-600" @click="deleteGrid">Supprimer la grille</button>
    </div>

    <p class="text-sm text-gray-600">
      Prix en francs CFA. Ligne = départ, colonne = arrivée. Les cases grisées reprennent le tarif du sens inverse (tarif symétrique) ;
      saisissez une valeur pour définir un tarif différent dans ce sens. Case vide = trajet non desservi.
      Pour <strong>modifier</strong> un tarif, changez la valeur ; pour le <strong>supprimer</strong>, videz la case (un tarif symétrique
      se supprime depuis la case où il a été saisi, dans les deux sens à la fois). Puis « Enregistrer les tarifs ».
    </p>

    <p v-if="!loaded" class="text-gray-500">Chargement de la grille…</p>

    <div v-if="loaded" class="card overflow-auto max-h-[70vh]">
      <table class="text-xs border-collapse">
        <thead class="sticky top-0 bg-slate-100 z-10">
          <tr>
            <th class="p-2 sticky left-0 bg-slate-100">Départ \ Arrivée</th>
            <th v-for="z in zones" :key="z.id" class="p-2 font-medium whitespace-nowrap">{{ z.name }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="from in zones" :key="from.id">
            <th class="p-2 text-left sticky left-0 bg-slate-50 whitespace-nowrap font-medium">{{ from.full_name }}</th>
            <td v-for="to in zones" :key="to.id" class="p-0.5 border">
              <input
                :value="cell(from.id, to.id) ?? ''"
                :placeholder="mirror(from.id, to.id) ?? ''"
                :class="['w-16 px-1 py-1 text-right rounded', mirror(from.id, to.id) !== null && cell(from.id, to.id) === null ? 'bg-slate-100' : '']"
                inputmode="numeric"
                :aria-label="`${from.name} vers ${to.name}`"
                @change="setCell(from.id, to.id, $event.target.value)"
              >
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="loaded" class="card p-4 space-y-3">
      <h2 class="font-semibold">Suppléments</h2>
      <div v-for="(s, i) in surcharges" :key="i" class="flex flex-wrap gap-2 items-end" @input="dirty = true" @change="dirty = true">
        <div>
          <label class="label">Type</label>
          <select v-model="s.type" class="input w-auto">
            <option value="express">Express</option>
            <option value="fragile">Fragile</option>
            <option value="weight">Poids</option>
          </select>
        </div>
        <template v-if="s.type === 'weight'">
          <div><label class="label">À partir de (kg)</label><input v-model.number="s.min_value" type="number" min="0" class="input w-24"></div>
          <div><label class="label">Jusqu'à (kg, exclu)</label><input v-model.number="s.max_value" type="number" min="0" class="input w-24"></div>
        </template>
        <div><label class="label">Montant (F)</label><input v-model.number="s.amount" type="number" min="0" class="input w-28"></div>
        <div><label class="label">ou % du tarif</label><input v-model.number="s.percent" type="number" min="0" max="100" class="input w-20"></div>
        <button class="btn-secondary" @click="surcharges.splice(i, 1); dirty = true">Retirer</button>
      </div>
      <button class="btn-secondary" @click="dirty = true; surcharges.push({ type: 'express', amount: 0, percent: null, min_value: null, max_value: null })">+ Supplément</button>
    </div>

    <p v-if="dirty" class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-800" role="status">
      Modifications non enregistrées.
      <button type="button" class="underline ml-2" @click="loadGrid">Annuler les modifications</button>
    </p>

    <div class="flex gap-2">
      <!-- Enregistrer remplace toute la grille : jamais avant son chargement complet -->
      <button class="btn-primary" :disabled="saving || !loaded" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer les tarifs' }}</button>
      <button v-if="grid && !grid.is_default" class="btn-secondary" @click="makeDefault">Définir comme grille par défaut</button>
    </div>
    <ReturnFeeSettings />
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import ReturnFeeSettings from '../../components/settings/ReturnFeeSettings.vue'

const toasts = useToastStore()
const grids = ref([])
const gridId = ref(null)
const grid = ref(null)
const zones = ref([])
const prices = ref({}) // "origine-destination" -> prix
const surcharges = ref([])
const saving = ref(false)
const loaded = ref(false)
const dirty = ref(false)
let shownGridId = null

const leaveWarning = 'Des tarifs modifiés ne sont pas enregistrés. Quitter quand même ?'

const key = (a, b) => `${a}-${b}`

function cell(a, b) {
  return prices.value[key(a, b)] ?? null
}

function mirror(a, b) {
  return a === b ? null : prices.value[key(b, a)] ?? null
}

function setCell(a, b, raw) {
  dirty.value = true
  const value = raw === '' ? null : Number(raw)
  if (value === null || Number.isNaN(value)) delete prices.value[key(a, b)]
  else prices.value[key(a, b)] = value
}

async function loadGrids() {
  grids.value = (await http.get('/pricing-grids')).data.data
  gridId.value ??= grids.value.find((g) => g.is_default)?.id ?? grids.value[0]?.id
}

async function loadGrid() {
  loaded.value = false
  grid.value = (await http.get(`/pricing-grids/${gridId.value}`)).data.data
  const map = {}
  for (const r of grid.value.rules) {
    map[key(r.origin_zone_id, r.destination_zone_id)] = r.price
  }
  prices.value = map
  surcharges.value = grid.value.surcharges.map((s) => ({ ...s }))
  shownGridId = gridId.value
  dirty.value = false
  loaded.value = true
}

// Changer de grille fait perdre les modifications en cours : on demande d'abord
function switchGrid() {
  if (dirty.value && !window.confirm(leaveWarning)) {
    gridId.value = shownGridId
    return
  }
  loadGrid()
}

async function renameGrid() {
  const name = window.prompt('Nouveau nom de la grille :', grid.value.name)?.trim()
  if (!name || name === grid.value.name) return
  try {
    await http.patch(`/pricing-grids/${gridId.value}`, { name })
    grid.value.name = name
    await loadGrids()
    toasts.success('Grille renommée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function deleteGrid() {
  const count = grid.value.merchants_count ?? 0
  const consequence = count ? ` Ses ${count} e-commerçant(s) passeront sur la grille par défaut.` : ''
  if (!window.confirm(`Supprimer la grille « ${grid.value.name} » et tous ses tarifs ?${consequence}`)) return
  try {
    await http.delete(`/pricing-grids/${gridId.value}`)
    gridId.value = null
    await loadGrids()
    if (gridId.value) await loadGrid()
    toasts.success('Grille supprimée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function save() {
  saving.value = true
  try {
    // Une règle est symétrique si le sens inverse n'a pas de tarif propre
    const rules = Object.entries(prices.value).map(([k, price]) => {
      const [o, d] = k.split('-').map(Number)
      return { origin_zone_id: o, destination_zone_id: d, price, is_symmetric: prices.value[key(d, o)] === undefined }
    })
    await http.put(`/pricing-grids/${gridId.value}/rules`, { rules })
    await http.put(`/pricing-grids/${gridId.value}/surcharges`, {
      surcharges: surcharges.value.map(({ type, min_value, max_value, amount, percent }) => ({
        type, min_value, max_value, amount: amount || 0, percent: percent || null,
      })),
    })
    toasts.success('Tarifs enregistrés.')
    await loadGrid()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function newGrid() {
  if (dirty.value && !window.confirm(leaveWarning)) return
  const name = window.prompt('Nom de la grille négociée (ex. : Gros volume) :')
  if (!name) return
  const { data } = await http.post('/pricing-grids', { name })
  await loadGrids()
  gridId.value = data.data.id
  await loadGrid()
}

async function makeDefault() {
  await http.patch(`/pricing-grids/${gridId.value}`, { is_default: true })
  await loadGrids()
  await loadGrid()
}

onBeforeRouteLeave(() => !dirty.value || window.confirm(leaveWarning))

function warnBeforeUnload(event) {
  if (dirty.value) event.preventDefault()
}
window.addEventListener('beforeunload', warnBeforeUnload)
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload))

onMounted(async () => {
  zones.value = (await http.get('/zones')).data.data
  await loadGrids()
  if (gridId.value) await loadGrid()
})
</script>
