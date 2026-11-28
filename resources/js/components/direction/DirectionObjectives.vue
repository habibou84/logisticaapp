<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <div class="flex flex-wrap items-end gap-2">
        <div>
          <label class="label" for="ob-month">Mois</label>
          <input id="ob-month" v-model="month" type="month" class="input w-auto" :max="thisMonth" @change="load">
        </div>
        <p v-if="data" class="pb-2 text-sm text-slate-600">
          <template v-if="data.current">Jour {{ data.elapsed_days }} sur {{ data.days_in_month }} · l'estimation de fin de mois suit le rythme actuel.</template>
          <template v-else>Mois terminé : résultat du mois entier.</template>
        </p>
      </div>
      <button v-if="data?.can_edit" type="button" class="btn-secondary" @click="toggle">{{ editing ? 'Fermer' : 'Définir les objectifs' }}</button>
    </div>

    <p v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</p>
    <p v-if="loading && !data" class="text-slate-600">Chargement…</p>

    <template v-if="data">
      <p v-if="!data.set && !editing" class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
        Aucun objectif fixé. {{ data.can_edit ? 'Cliquez sur « Définir les objectifs » pour en fixer par activité et pour les équipes.' : 'Une personne qui gère les réglages peut les fixer.' }}
      </p>
      <p v-if="editing" class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900">
        Un objectif vaut pour chaque mois, jusqu'à ce que vous le changiez. Laissez une case vide pour ne pas fixer d'objectif.
        Temps par mission et paie par course : la valeur à ne pas dépasser.
      </p>

      <form class="space-y-4" @submit.prevent="save">
        <section v-for="g in data.groups" :key="g.scope" class="card overflow-x-auto">
          <h2 class="flex items-center gap-2 p-4 pb-2 font-semibold"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: COLORS[g.scope] || TEAM }" />{{ g.label }}</h2>
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-600">
              <tr>
                <th class="p-2 pl-4">Indicateur</th><th class="p-2 text-right">Objectif du mois</th><th class="p-2 text-right">{{ data.current ? 'Réalisé à ce jour' : 'Réalisé' }}</th>
                <th class="p-2 w-1/5">Avancement</th><th v-if="data.current" class="p-2 text-right">Fin de mois estimée</th><th class="p-2 pr-4">État</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="r in g.rows" :key="r.key">
                <td class="p-2 pl-4 font-medium">{{ r.label }}<span v-if="!r.higher_is_better" class="font-normal text-slate-600"> (au plus)</span></td>
                <td class="p-2 text-right whitespace-nowrap">
                  <template v-if="editing">
                    <label class="sr-only" :for="`ob-${g.scope}-${r.key}`">{{ g.label }} : {{ r.label }} ({{ UNITS[r.kind] }})</label>
                    <input :id="`ob-${g.scope}-${r.key}`" v-model="form[g.scope][r.key]" type="number" :step="['percent', 'decimal'].includes(r.kind) ? '0.1' : '1'" class="input ml-auto w-36 text-right" :placeholder="UNITS[r.kind]">
                  </template>
                  <template v-else>{{ r.target === null ? '—' : fmt(r, r.target) }}</template>
                </td>
                <td class="p-2 text-right font-semibold whitespace-nowrap">{{ fmt(r, r.realized) }}</td>
                <td class="p-2">
                  <div v-if="r.percent !== null" class="flex items-center gap-2">
                    <div class="h-2 flex-1 rounded bg-slate-100" role="progressbar" :aria-valuenow="r.percent" aria-valuemin="0" aria-valuemax="100" :aria-label="`${r.label} : ${r.percent} % de l'objectif`">
                      <div :class="['h-2 rounded', r.status === 'behind' ? 'bg-amber-500' : 'bg-emerald-500']" :style="{ width: `${Math.min(100, Math.max(0, r.percent))}%` }" />
                    </div>
                    <span class="w-12 text-right text-xs text-slate-600">{{ r.percent }} %</span>
                  </div>
                </td>
                <td v-if="data.current" class="p-2 text-right whitespace-nowrap text-slate-600">{{ r.projection === null ? '—' : fmt(r, r.projection) }}</td>
                <td class="p-2 pr-4">
                  <span v-if="r.status" :class="['rounded-full border px-2 py-0.5 text-xs font-semibold whitespace-nowrap', STATUS[r.status].chip]">{{ STATUS[r.status][data.current ? 'now' : 'done'] }}</span>
                  <span v-else class="text-xs text-slate-600">{{ r.target === null ? 'Pas d\'objectif' : 'Pas encore de mesure' }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </section>
        <div v-if="editing" class="flex items-center gap-3">
          <button type="submit" class="btn-primary" :disabled="saving">Enregistrer les objectifs</button>
          <span v-if="saveError" class="text-sm text-red-700">{{ saveError }}</span>
        </div>
      </form>
    </template>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { money } from '../../utils/format'
import { COLORS } from './shared'

const TEAM = '#6a4ce2'
const UNITS = { money: 'F', number: 'courses', percent: '%', minutes: 'minutes', decimal: 'par jour' }
const STATUS = {
  ok: { now: 'En bonne voie', done: 'Atteint', chip: 'border-emerald-200 bg-emerald-50 text-emerald-700' },
  behind: { now: 'En retard', done: 'Non atteint', chip: 'border-amber-200 bg-amber-50 text-amber-800' },
}

const now = new Date()
const thisMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
const month = ref(thisMonth)
const data = ref(null)
const loading = ref(false)
const error = ref('')
const editing = ref(false)
const saving = ref(false)
const saveError = ref('')
const form = reactive({})

function fmt(row, v) {
  if (v === null || v === undefined) return '—'
  if (row.kind === 'money') return money(v)
  if (row.kind === 'percent') return `${Number(v).toLocaleString('fr-FR')} %`
  if (row.kind === 'minutes') return `${v} min`
  return Number(v).toLocaleString('fr-FR')
}

async function load() {
  if (!month.value) return
  loading.value = true
  error.value = ''
  try {
    data.value = (await http.get('/stats/direction/objectives', { params: { month: month.value } })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
function toggle() {
  editing.value = !editing.value
  if (!editing.value) return
  saveError.value = ''
  data.value.groups.forEach((g) => { form[g.scope] = Object.fromEntries(g.rows.map((r) => [r.key, r.target])) })
}
async function save() {
  saving.value = true
  saveError.value = ''
  try {
    const objectives = Object.fromEntries(Object.entries(form).map(([scope, values]) => [scope, Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v === '' || v === null || v === undefined ? null : Number(v)]))]))
    await http.put('/stats/direction/objectives', { objectives })
    editing.value = false
    await load()
  } catch (e) {
    saveError.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

load()
</script>
