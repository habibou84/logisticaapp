<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <h1 class="text-xl font-bold">Chiffre d'affaires</h1>
      <div class="flex flex-wrap items-end gap-2">
        <div><label class="label" for="rv-from">Du mois</label><input id="rv-from" v-model="from" type="month" class="input w-auto" :max="to" @change="load"></div>
        <div><label class="label" for="rv-to">Au mois</label><input id="rv-to" v-model="to" type="month" class="input w-auto" :min="from" @change="load"></div>
        <div>
          <label class="label" for="rv-mode">Montants</label>
          <select id="rv-mode" v-model="mode" class="input w-auto">
            <option value="billed">Facturés</option>
            <option value="collected">Encaissés</option>
          </select>
        </div>
      </div>
    </div>

    <p class="text-sm text-gray-600">
      Chaque activité a son chiffre d'affaires. <strong>E-commerce</strong> : frais de livraison, de retour et de stockage retenus
      aux clients (encaissés quand le reversement est payé). <strong>Distribution</strong> et <strong>MAD</strong> : factures du mois
      émises (facturé) et paiements reçus dans le mois (encaissé).
    </p>

    <p v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</p>

    <template v-if="report">
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div v-for="a in report.activities" :key="a.value" class="card p-4">
          <p class="text-sm text-gray-500">{{ a.label }}</p>
          <p class="text-xl font-bold">{{ money(report.totals[a.value][mode]) }}</p>
          <p class="text-xs text-gray-500">{{ share(a.value) }} % · {{ report.totals[a.value].orders }} course(s)</p>
        </div>
        <div v-if="report.activities.length > 1" class="card p-4 bg-slate-900 text-white">
          <p class="text-sm text-slate-300">Total</p>
          <p class="text-xl font-bold">{{ money(report.totals.total[mode]) }}</p>
          <p class="text-xs text-slate-300">{{ report.totals.total.orders }} course(s)</p>
        </div>
      </div>

      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr>
              <th class="p-2">Mois</th>
              <th v-for="a in report.activities" :key="a.value" class="p-2 text-right">{{ a.label }}</th>
              <th v-if="report.activities.length > 1" class="p-2 text-right">Total</th>
              <th class="p-2 w-1/4 hidden sm:table-cell">Répartition</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="m in [...report.months].reverse()" :key="m.month">
              <td class="p-2 font-medium whitespace-nowrap">{{ m.label }}</td>
              <td v-for="a in report.activities" :key="a.value" class="p-2 text-right whitespace-nowrap">
                {{ money(m.activities[a.value][mode]) }}
                <div class="text-xs text-gray-500">{{ m.activities[a.value].orders }} course(s)</div>
              </td>
              <td v-if="report.activities.length > 1" class="p-2 text-right font-semibold whitespace-nowrap">{{ money(m.total[mode]) }}</td>
              <td class="p-2 hidden sm:table-cell">
                <div v-if="m.total[mode] > 0" class="flex h-3 rounded overflow-hidden bg-slate-100" :title="m.label">
                  <div
                    v-for="a in report.activities" :key="a.value" :class="BAR[a.value]"
                    :style="{ width: `${(100 * m.activities[a.value][mode]) / m.total[mode]}%` }"
                    :title="`${a.label} : ${money(m.activities[a.value][mode])}`"
                  />
                </div>
              </td>
            </tr>
          </tbody>
          <tfoot class="border-t font-bold">
            <tr>
              <td class="p-2">Total</td>
              <td v-for="a in report.activities" :key="a.value" class="p-2 text-right whitespace-nowrap">{{ money(report.totals[a.value][mode]) }}</td>
              <td v-if="report.activities.length > 1" class="p-2 text-right whitespace-nowrap">{{ money(report.totals.total[mode]) }}</td>
              <td class="hidden sm:table-cell" />
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="flex flex-wrap gap-4 text-xs text-gray-600">
        <span v-for="a in report.activities" :key="a.value" class="flex items-center gap-1"><span :class="['inline-block w-3 h-3 rounded', BAR[a.value]]" /> {{ a.label }}</span>
      </div>
    </template>
    <p v-else-if="!error" class="text-gray-500">Chargement…</p>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { money } from '../../utils/format'

const BAR = { ecommerce: 'bg-sky-500', distribution: 'bg-amber-500', mad: 'bg-violet-500' }
const ym = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`

// Par défaut : les douze derniers mois, mois en cours compris
const now = new Date()
const to = ref(ym(now))
const from = ref(ym(new Date(now.getFullYear(), now.getMonth() - 11, 1)))
const mode = ref('billed')
const report = ref(null)
const error = ref('')

const share = (activity) => {
  const total = report.value.totals.total[mode.value]
  return total ? Math.round((100 * report.value.totals[activity][mode.value]) / total) : 0
}

async function load() {
  if (!from.value || !to.value) return
  error.value = ''
  try {
    report.value = (await http.get('/revenue', { params: { from: from.value, to: to.value } })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

load()
</script>
