<template>
  <!-- Sans l'autorisation de direction : les statistiques de toujours, telles quelles -->
  <Statistics v-if="!direction" />
  <div v-else class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Statistiques</h1>
        <p class="text-sm text-slate-600">{{ current.description }}</p>
      </div>
      <div v-if="current.endpoint" class="flex flex-wrap items-end gap-2">
        <div>
          <label class="label" for="dr-preset">Période</label>
          <select id="dr-preset" v-model="preset" class="input w-auto" @change="applyPreset">
            <option v-for="(p, key) in PRESETS" :key="key" :value="key">{{ p.label }}</option>
            <option value="custom">Personnalisée</option>
          </select>
        </div>
        <div><label class="label" for="dr-from">Du</label><input id="dr-from" v-model="from" type="date" class="input w-auto" :max="to" @change="custom"></div>
        <div><label class="label" for="dr-to">Au</label><input id="dr-to" v-model="to" type="date" class="input w-auto" :min="from" :max="today()" @change="custom"></div>
      </div>
    </div>

    <nav class="flex gap-1 overflow-x-auto border-b" role="tablist" aria-label="Vues des statistiques">
      <button
        v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
        :class="['-mb-px inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', tab === t.key ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-600 hover:text-slate-800']"
        @click="tab = t.key"
      ><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: t.color }" />{{ t.label }}</button>
    </nav>

    <!-- Les statistiques détaillées : leurs rubriques, leur période et leur filtre d'activité -->
    <Statistics v-if="tab === 'detail'" embedded />
    <!-- Objectifs : leur propre choix du mois -->
    <DirectionObjectives v-if="tab === 'objectives'" />

    <p v-if="error && current.endpoint" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</p>
    <p v-if="loading && !data[tab] && current.endpoint" class="text-slate-600">Chargement…</p>

    <template v-if="data[tab] && current.endpoint">
      <DirectionOverview v-if="tab === 'overview'" :board="data.overview" />
      <DirectionEcommerce v-else-if="tab === 'ecommerce'" :data="data.ecommerce" />
      <DirectionMad v-else-if="tab === 'mad'" :data="data.mad" />
      <DirectionDistribution v-else-if="tab === 'distribution'" :data="data.distribution" />
      <DirectionTeams v-else-if="tab === 'teams'" :key="`${from}-${to}`" :data="data.teams" />
    </template>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import DirectionDistribution from '../../components/direction/DirectionDistribution.vue'
import DirectionEcommerce from '../../components/direction/DirectionEcommerce.vue'
import DirectionMad from '../../components/direction/DirectionMad.vue'
import DirectionObjectives from '../../components/direction/DirectionObjectives.vue'
import DirectionOverview from '../../components/direction/DirectionOverview.vue'
import DirectionTeams from '../../components/direction/DirectionTeams.vue'
import { COLORS } from '../../components/direction/shared'
import { useAuthStore } from '../../stores/auth'
import { myActivities } from '../../utils/activities'
import { today } from '../../utils/format'
import Statistics from './Statistics.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const direction = computed(() => auth.can('direction.view'))

function iso(d) {
  const copy = new Date(d)
  copy.setMinutes(copy.getMinutes() - copy.getTimezoneOffset())
  return copy.toISOString().slice(0, 10)
}
function daysAgo(n) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return iso(d)
}
const PRESETS = {
  month: { label: 'Ce mois', range: () => [`${today().slice(0, 7)}-01`, today()] },
  lastMonth: {
    label: 'Mois dernier',
    range: () => {
      const d = new Date()
      return [iso(new Date(d.getFullYear(), d.getMonth() - 1, 1)), iso(new Date(d.getFullYear(), d.getMonth(), 0))]
    },
  },
  days30: { label: '30 derniers jours', range: () => [daysAgo(29), today()] },
  days90: { label: '90 derniers jours', range: () => [daysAgo(89), today()] },
}

// Un écran par activité du périmètre de la personne, puis les équipes
const SCREENS = {
  ecommerce: { label: 'E-commerce', description: 'Parcours des colis, échecs, marge par zone, argent en circulation et clients principaux.' },
  mad: { label: 'MAD', description: 'Forfaits, consommation, présence et retards par contrat, marge et échéances.' },
  distribution: { label: 'Distribution', description: 'Volumes par type d\'envoi, délais tenus, forfaits, lots et paiement des factures.' },
}
const tabs = computed(() => [
  { key: 'overview', label: 'Vue d\'ensemble', color: '#0f172a', endpoint: '/stats/direction', description: 'Les activités côte à côte, comparées à la période précédente, et les points à décider.' },
  ...myActivities(auth.user).map((a) => ({ key: a.value, color: COLORS[a.value], endpoint: `/stats/direction/${a.value}`, ...SCREENS[a.value] })),
  { key: 'teams', label: 'Équipes', color: '#6a4ce2', endpoint: '/stats/direction/teams', description: 'Livreurs, charge par zone, temps par mission, paie, véhicules et agents du bureau.' },
  { key: 'objectives', label: 'Objectifs', color: '#ec1d27', description: 'Les objectifs du mois, par activité et pour les équipes, comparés au réalisé.' },
  { key: 'detail', label: 'Détail', color: '#94a3b8', description: 'Livraisons, délais et échecs, livreurs, zones, clients, rentabilité, trésorerie, analyse croisée, partage et alertes.' },
])

const preset = ref('month')
const [initialFrom, initialTo] = PRESETS.month.range()
const from = ref(initialFrom)
const to = ref(initialTo)
// « ?vue= » : l'onglet de cette page ; « ?onglet= » : une vue des statistiques détaillées (liens « À décider »)
const tab = ref(tabs.value.some((t) => t.key === route.query.vue) ? route.query.vue : (route.query.onglet ? 'detail' : 'overview'))
const current = computed(() => tabs.value.find((t) => t.key === tab.value) || tabs.value[0])
const data = reactive({})
const loading = ref(false)
const error = ref('')

async function load() {
  if (!direction.value || !current.value.endpoint || !from.value || !to.value) return
  const key = tab.value
  loading.value = true
  error.value = ''
  try {
    data[key] = (await http.get(current.value.endpoint, { params: { from: from.value, to: to.value } })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
// Nouvelle période : les écrans déjà chargés ne valent plus
function reload() {
  Object.keys(data).forEach((key) => delete data[key])
  load()
}
function applyPreset() {
  if (!PRESETS[preset.value]) return
  ;[from.value, to.value] = PRESETS[preset.value].range()
  reload()
}
function custom() {
  preset.value = 'custom'
  reload()
}

watch(tab, () => { if (!data[tab.value]) load() })
// L'onglet de la page dans l'adresse ; hors du détail, les paramètres des statistiques détaillées n'ont plus cours
watch(tab, () => router.replace({ query: tab.value === 'detail' ? { ...route.query, vue: 'detail' } : { vue: tab.value } }))
// Lien « À décider » vers une vue détaillée, ouvert depuis cette page
watch(() => route.query.onglet, (view) => { if (view && tab.value !== 'detail') tab.value = 'detail' })

load()
</script>
