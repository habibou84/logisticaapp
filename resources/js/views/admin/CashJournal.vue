<template>
  <div class="space-y-4">
    <div>
      <RouterLink to="/admin/caisse" class="text-sm text-blue-600">← Caisse</RouterLink>
      <h1 class="text-xl font-bold mt-1">Journal de la caisse</h1>
      <p class="text-sm text-gray-600">Tout ce qui touche l'argent, avec qui l'a fait et quand. Rien ne s'efface.</p>
    </div>

    <div class="card p-3 flex flex-wrap items-end gap-3">
      <div>
        <label class="label" for="j-action">Action</label>
        <select id="j-action" v-model="f.action" class="input w-auto" @change="load">
          <option value="">Toutes</option>
          <option v-for="(label, key) in actions" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="j-user">Par</label>
        <select id="j-user" v-model="f.user_id" class="input w-auto" @change="load">
          <option value="">Tout le monde</option>
          <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
        </select>
      </div>
      <div><label class="label" for="j-from">Du</label><input id="j-from" v-model="f.from" type="date" class="input" @change="load"></div>
      <div><label class="label" for="j-to">au</label><input id="j-to" v-model="f.to" type="date" class="input" @change="load"></div>
      <span class="flex-1" />
      <button type="button" class="btn-secondary" :disabled="!logs.length" @click="exportCsv">Exporter</button>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm stack-table">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Quand</th><th class="p-2">Action</th><th class="p-2">Détail</th><th class="p-2 text-right">Montant</th><th class="p-2">Par</th></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="l in logs" :key="l.id">
            <td class="p-2 text-xs whitespace-nowrap">{{ dateTime(l.created_at) }}</td>
            <td data-label="Action" class="p-2"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', BADGES[l.action] || 'bg-slate-100 text-slate-700']">{{ l.action_label }}</span></td>
            <td data-label="Détail" class="p-2">{{ l.description }}<span v-if="l.reference" class="block font-mono text-xs text-gray-500">{{ l.reference }}</span></td>
            <td data-label="Montant" :class="['p-2 text-right whitespace-nowrap', signedClass(l.amount)]">{{ l.amount === null ? '—' : money(l.amount) }}</td>
            <td data-label="Par" class="p-2 text-xs">{{ l.by || 'Automatique' }}</td>
          </tr>
          <tr v-if="loaded && !logs.length"><td colspan="5" class="p-4 text-center text-gray-500">Aucune action sur cette période.</td></tr>
        </tbody>
      </table>
    </div>
    <p v-if="logs.length >= 500" class="text-xs text-gray-500">Les 500 dernières actions : resserrez les dates pour voir plus loin.</p>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http from '../../bootstrap/axios'
import { downloadCsv } from '../../components/stats/chart'
import { dateTime, money, signedClass } from '../../utils/format'

/**
 * Caisse › journal : versements reçus ou annulés, avances, ajustements, paiements, paie.
 */
const BADGES = {
  remittance: 'bg-emerald-100 text-emerald-900',
  remittance_cancelled: 'bg-red-100 text-red-900',
  advance: 'bg-amber-100 text-amber-900',
  advance_cancelled: 'bg-red-100 text-red-900',
  merchant_adjustment: 'bg-indigo-100 text-indigo-900',
  courier_adjustment: 'bg-indigo-100 text-indigo-900',
  payout_cancelled: 'bg-red-100 text-red-900',
  payslip_cancelled: 'bg-red-100 text-red-900',
}
const weekAgo = new Date(Date.now() - 6 * 86400000).toISOString().slice(0, 10)
const f = reactive({ action: '', user_id: '', from: weekAgo, to: new Date().toISOString().slice(0, 10) })
const logs = ref([])
const actions = ref({})
const users = ref([])
const loaded = ref(false)

async function load() {
  const { data } = await http.get('/finance/journal', { params: Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')) })
  logs.value = data.data
  actions.value = data.actions
  users.value = data.users
  loaded.value = true
}

function exportCsv() {
  downloadCsv(`journal-caisse-${f.from}-${f.to}.csv`, [
    ['Quand', 'Action', 'Détail', 'Référence', 'Montant (F)', 'Par'],
    ...logs.value.map((l) => [dateTime(l.created_at), l.action_label, l.description, l.reference || '', l.amount ?? '', l.by || 'Automatique']),
  ])
}

onMounted(load)
</script>
