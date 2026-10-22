<template>
  <div class="card p-4 space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-semibold">🧮 Simulateur</h2>
      <div class="flex rounded-lg bg-slate-100 p-1 text-sm">
        <button v-for="m in MODES" :key="m.value" :class="['rounded-md px-3 py-1', mode === m.value ? 'bg-white shadow font-medium' : 'text-gray-600']" @click="mode = m.value">{{ m.label }}</button>
      </div>
    </div>
    <p class="text-sm text-gray-600">Calcule avec les réglages affichés ci-dessus, même non enregistrés. Rien n'est payé ni modifié.</p>

    <!-- Une course fictive -->
    <form v-if="mode === 'scenario'" class="space-y-3" @submit.prevent="simulate">
      <div class="flex flex-wrap gap-2 items-end">
        <div>
          <label class="label">Étape</label>
          <select v-model="scenario.event" class="input w-auto">
            <option v-for="e in events" :key="e.value" :value="e.value">{{ e.label }}</option>
          </select>
        </div>
        <div>
          <label class="label">Zone {{ scenario.event === 'pickup' ? 'de ramassage' : 'du destinataire' }}</label>
          <select v-model="scenario.zone_id" class="input w-auto">
            <option :value="null">—</option>
            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
          </select>
        </div>
        <div>
          <label class="label">Frais de livraison (F)</label>
          <input v-model.number="scenario.fees" type="number" min="0" class="input w-28">
        </div>
        <div>
          <label class="label">Encaissé (F)</label>
          <input v-model.number="scenario.collected" type="number" min="0" class="input w-28">
        </div>
        <div>
          <label class="label">Véhicule</label>
          <select v-model="scenario.vehicle_type" class="input w-auto">
            <option v-for="(label, value) in VEHICLES" :key="value" :value="value">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="label">Jour et heure</label>
          <input v-model="scenario.at" type="datetime-local" class="input w-auto">
        </div>
        <div v-if="scenario.event === 'failed_attempt'">
          <label class="label">Motif d'échec</label>
          <select v-model="scenario.incident_reason_id" class="input w-auto">
            <option :value="null">—</option>
            <option v-for="r in reasons" :key="r.id" :value="r.id">{{ r.label }}</option>
          </select>
        </div>
      </div>
      <div class="flex flex-wrap gap-4 text-sm">
        <label class="flex items-center gap-2"><input v-model="scenario.express" type="checkbox"> Express</label>
        <label class="flex items-center gap-2"><input v-model="scenario.fragile" type="checkbox"> Fragile</label>
        <label v-if="scenario.event === 'pickup'" class="flex items-center gap-2"><input v-model="scenario.same_visit" type="checkbox"> Colis suivant chez le même marchand</label>
      </div>
      <button class="btn-secondary" :disabled="loading">{{ loading ? 'Calcul…' : 'Calculer' }}</button>

      <div v-if="result" class="rounded-lg border">
        <p v-if="!result.lines.length" class="p-3 text-sm text-gray-500">Aucune règle ne s'applique : rien n'est payé pour cette étape.</p>
        <div v-for="(l, i) in result.lines" :key="i" class="flex justify-between gap-2 p-2 border-b text-sm">
          <span>{{ l.label }} <span class="block text-xs text-gray-500">{{ l.detail }}</span></span>
          <span :class="['whitespace-nowrap', signedClass(l.amount)]">{{ money(l.amount) }}</span>
        </div>
        <p class="flex justify-between p-2 font-semibold"><span>Gain du livreur</span><span>{{ money(result.total) }}</span></p>
      </div>
    </form>

    <!-- Rejouer une période passée -->
    <form v-else class="space-y-3" @submit.prevent="simulate">
      <div class="flex flex-wrap items-end gap-2">
        <div>
          <label class="label">Période contenant le</label>
          <input v-model="date" type="date" :max="today()" class="input w-auto" required>
        </div>
        <button class="btn-secondary" :disabled="loading">{{ loading ? 'Calcul…' : 'Rejouer' }}</button>
      </div>
      <p class="text-xs text-gray-500">
        Reprend les courses réellement faites par chaque livreur pendant la période de paie du plan (le mois si « à la demande »)
        et compare avec ce qu'il a réellement gagné. Salaire de base compté en entier ; primes calculées sur la période.
      </p>

      <div v-if="replay" class="overflow-x-auto">
        <p class="text-sm mb-2">Période du <strong>{{ date_(replay.period_start) }}</strong> au <strong>{{ date_(replay.period_end) }}</strong></p>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr>
              <th class="p-2">Livreur</th><th class="p-2">Activité</th><th class="p-2 text-right">Courses</th><th class="p-2 text-right">Primes</th>
              <th class="p-2 text-right">Salaire</th><th class="p-2 text-right">Avec ce plan</th><th class="p-2 text-right">Réel</th><th class="p-2 text-right">Écart</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="c in replay.couriers" :key="c.courier_id">
              <td class="p-2 font-medium">{{ c.name }}</td>
              <td class="p-2 text-xs text-gray-600">{{ activity(c) }}</td>
              <td class="p-2 text-right">{{ money(c.courses) }}</td>
              <td class="p-2 text-right" :title="c.bonuses.map((b) => `${b.label} : ${money(b.amount)}`).join('\n')">{{ money(c.bonuses.reduce((s, b) => s + b.amount, 0)) }}</td>
              <td class="p-2 text-right">{{ money(c.salary) }}</td>
              <td class="p-2 text-right font-semibold">{{ money(c.simulated) }}</td>
              <td class="p-2 text-right">{{ money(c.actual) }}</td>
              <td :class="['p-2 text-right font-medium', signedClass(c.simulated - c.actual)]">{{ c.simulated - c.actual > 0 ? '+' : '' }}{{ money(c.simulated - c.actual) }}</td>
            </tr>
            <tr v-if="!replay.couriers.length"><td colspan="8" class="p-4 text-center text-gray-500">Aucun livreur actif.</td></tr>
          </tbody>
          <tfoot v-if="replay.couriers.length" class="font-semibold">
            <tr>
              <td class="p-2" colspan="5">Total</td>
              <td class="p-2 text-right">{{ money(replay.totals.simulated) }}</td>
              <td class="p-2 text-right">{{ money(replay.totals.actual) }}</td>
              <td :class="['p-2 text-right', signedClass(replay.totals.simulated - replay.totals.actual)]">{{ money(replay.totals.simulated - replay.totals.actual) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </form>

    <p v-if="error" class="field-error">{{ error }}</p>
  </div>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { date as date_, money, signedClass, today } from '../utils/format'

const props = defineProps({
  settings: { type: Object, required: true },
  events: { type: Array, default: () => [] },
  zones: { type: Array, default: () => [] },
  reasons: { type: Array, default: () => [] },
})

const MODES = [{ value: 'scenario', label: 'Une course' }, { value: 'replay', label: 'Rejouer une période' }]
const VEHICLES = { moto: 'Moto', velo: 'Vélo', voiture: 'Voiture', tricycle: 'Tricycle', pieton: 'À pied' }
const EVENTS_SHORT = { pickup: 'ramassages', delivery: 'livraisons', failed_attempt: 'échecs', return: 'retours', shipping: 'expéditions' }

const mode = ref('scenario')
const loading = ref(false)
const error = ref('')
const result = ref(null)
const replay = ref(null)
const date = ref(today())
const scenario = reactive({
  event: 'delivery', zone_id: null, fees: 1500, collected: 10000, vehicle_type: 'moto',
  at: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16),
  incident_reason_id: null, express: false, fragile: false, same_visit: false,
})

// Un réglage modifié rend le dernier résultat périmé
watch(() => props.settings, () => { result.value = null; replay.value = null }, { deep: true })

function activity(c) {
  // Libellés au pluriel : singulier pour 1
  return Object.entries(c.counts).map(([k, n]) => `${n} ${(EVENTS_SHORT[k] ?? k).replace(/s$/, n > 1 ? 's' : '')}`).join(' · ') || 'aucune course'
}

async function simulate() {
  loading.value = true
  error.value = ''
  try {
    const body = mode.value === 'scenario'
      ? { ...props.settings, mode: 'scenario', scenario: { ...scenario, at: scenario.at ? scenario.at.replace('T', ' ') : null } }
      : { ...props.settings, mode: 'replay', date: date.value }
    const { data } = await http.post('/pay-plans/simulate', body)
    if (mode.value === 'scenario') result.value = data.data
    else replay.value = data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>
