<template>
  <!-- Sans l'autorisation de direction : les statistiques de toujours, telles quelles -->
  <Statistics v-if="!direction" />
  <div v-else class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Statistiques</h1>
        <p class="text-sm text-slate-600">{{ view.description || current.description }}</p>
      </div>
      <div v-if="view.period !== false" class="flex flex-wrap items-end gap-2">
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
        @click="selectTab(t.key)"
      ><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: t.color }" />{{ t.label }}</button>
    </nav>

    <!-- Dans l'onglet : la synthèse, puis les vues de détail qui lui appartiennent -->
    <div v-if="current.views.length > 1 || tab === 'tools'" class="flex flex-wrap items-center gap-1.5" role="group" :aria-label="`Vues de l'onglet ${current.label}`">
      <button
        v-for="v in current.views" :key="v.key" type="button" :aria-pressed="detail === v.key"
        :class="['rounded-full border px-3 py-1 text-sm font-medium', detail === v.key ? 'border-slate-900 bg-slate-900 text-white' : 'bg-white text-slate-700 hover:bg-slate-50']"
        @click="detail = v.key"
      >{{ v.label }}</button>
      <template v-if="tab === 'tools'">
        <span class="flex-1" />
        <button type="button" class="btn-secondary" :disabled="exporting" title="Toutes les statistiques de la période, en plusieurs feuilles" @click="exportXlsx">{{ exporting ? 'Export…' : 'Exporter en Excel' }}</button>
        <a :href="`/admin/rapport-mensuel?mois=${from.slice(0, 7)}`" target="_blank" class="btn-secondary" title="À imprimer ou enregistrer en PDF">Rapport du mois</a>
      </template>
    </div>

    <p v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</p>
    <p v-if="loading && view.endpoint && !data[shown]" class="text-slate-600">Chargement…</p>

    <!-- Synthèses -->
    <template v-if="!detail && data[shown]">
      <DirectionOverview v-if="tab === 'overview'" :board="data[shown]" />
      <DirectionEcommerce v-else-if="tab === 'ecommerce'" :data="data[shown]" />
      <DirectionMad v-else-if="tab === 'mad'" :data="data[shown]" />
      <DirectionDistribution v-else-if="tab === 'distribution'" :data="data[shown]" />
      <DirectionTeams v-else-if="tab === 'teams'" :key="`${from}-${to}`" :data="data[shown]" />
    </template>
    <!-- Objectifs : leur propre choix du mois -->
    <DirectionObjectives v-if="tab === 'objectives'" />

    <!-- Vues de détail : celles des statistiques d'origine, pour l'activité de l'onglet -->
    <template v-if="detail">
      <component :is="view.component" v-if="!view.endpoint" v-bind="view.props ? view.props() : {}" />
      <component :is="view.component" v-else-if="data[shown]" :data="data[shown]" v-bind="view.props ? view.props() : {}" @reload="load" />
    </template>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import DirectionDistribution from '../../components/direction/DirectionDistribution.vue'
import DirectionEcommerce from '../../components/direction/DirectionEcommerce.vue'
import DirectionForecast from '../../components/direction/DirectionForecast.vue'
import DirectionMad from '../../components/direction/DirectionMad.vue'
import DirectionObjectives from '../../components/direction/DirectionObjectives.vue'
import DirectionOverview from '../../components/direction/DirectionOverview.vue'
import DirectionSimulator from '../../components/direction/DirectionSimulator.vue'
import DirectionTeams from '../../components/direction/DirectionTeams.vue'
import { COLORS } from '../../components/direction/shared'
import ClientInsightsTab from '../../components/stats/ClientInsightsTab.vue'
import DailyTab from '../../components/stats/DailyTab.vue'
import DeliveryDelaysTab from '../../components/stats/DeliveryDelaysTab.vue'
import FieldTab from '../../components/stats/FieldTab.vue'
import FinancesTab from '../../components/stats/FinancesTab.vue'
import FixedCostsPanel from '../../components/stats/FixedCostsPanel.vue'
import GoalsTab from '../../components/stats/GoalsTab.vue'
import IntegrationsTab from '../../components/stats/IntegrationsTab.vue'
import MerchantsTab from '../../components/stats/MerchantsTab.vue'
import PivotTab from '../../components/stats/PivotTab.vue'
import ProfitabilityTab from '../../components/stats/ProfitabilityTab.vue'
import QualityTab from '../../components/stats/QualityTab.vue'
import VehiclesTab from '../../components/stats/VehiclesTab.vue'
import ZonesTab from '../../components/stats/ZonesTab.vue'
import { useAuthStore } from '../../stores/auth'
import { myActivities } from '../../utils/activities'
import { today } from '../../utils/format'
import Statistics from './Statistics.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const direction = computed(() => auth.can('direction.view'))
const withAmounts = computed(() => auth.can('finance.view'))

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

/**
 * Vues de détail : celles des statistiques d'origine, rangées dans l'onglet auquel elles appartiennent et limitées à
 * son activité. Chacune garde l'autorisation qu'elle demandait.
 */
const amounts = () => ({ withAmounts: withAmounts.value, exportable: true })
const DETAILS = {
  daily: { label: 'Jour par jour', endpoint: '/stats/overview', component: DailyTab, description: 'Les courses créées et livrées jour par jour, le chiffre d\'affaires par jour et les heures de pointe.' },
  trends: { label: 'Tendances et seuils', endpoint: '/stats/performance', component: GoalsTab, period: false, description: 'Le mois en cours et son estimation de fin de mois, la comparaison avec l\'an dernier, 13 mois d\'historique, et les seuils des statistiques.' },
  zones: { label: 'Zones', endpoint: '/stats/zones', component: ZonesTab, props: amounts, description: 'Volumes, réussite et délais par zone de livraison.' },
  delays: { label: 'Délais de livraison', endpoint: '/stats/deliveries', component: DeliveryDelaysTab, description: 'Réussite au premier passage, délais de ramassage et de livraison, issues des courses.' },
  quality: { label: 'Délais et échecs', endpoint: '/stats/quality', component: QualityTab, description: 'Respect des délais par client, zone et livreur, et analyse des échecs.' },
  clients: { label: 'Clients', endpoint: '/stats/merchants', component: MerchantsTab, props: amounts, permission: 'merchants.view', description: 'Courses, réussite et montants de chaque client, clients inactifs à relancer.' },
  loyalty: { label: 'Fidélité et paiements', endpoint: '/stats/clients', component: ClientInsightsTab, permission: 'merchants.view', description: 'Concentration du chiffre d\'affaires, clients en baisse, fidélité des nouveaux clients, paiement des factures.' },
  profitability: { label: 'Rentabilité détaillée', endpoint: '/stats/profitability', component: ProfitabilityTab, permission: 'finance.view', description: 'Ce que rapportent et coûtent les clients, les zones, les livreurs et les contrats.' },
  cash: { label: 'Trésorerie', endpoint: '/stats/finances', component: FinancesTab, permission: 'finance.view', activity: false, description: 'L\'argent en circulation aujourd\'hui : chez les livreurs, à reverser aux clients, dû par des clients (toutes activités).' },
  field: { label: 'Pannes et accidents', endpoint: '/field-events/stats', component: FieldTab, permission: 'field_events.manage', activity: false, description: 'Pannes, accidents et autres événements remontés par les livreurs.' },
  vehicles: { label: 'Véhicules', endpoint: '/vehicles/stats', component: VehiclesTab, permission: 'fleet.manage', activity: false, description: 'Coûts, consommation et immobilisations du parc.' },
  forecast: { label: 'Prévisions', endpoint: '/stats/direction/forecast', component: DirectionForecast, period: false, activity: false, description: 'Le chiffre d\'affaires des trois prochains mois, par activité, d\'après la tendance des derniers mois.' },
  simulator: { label: 'Simulateur « et si »', endpoint: '/stats/direction/simulator', component: DirectionSimulator, period: false, activity: false, description: 'L\'effet d\'une décision sur la marge, à partir des chiffres du dernier mois terminé.' },
  costs: { label: 'Frais fixes', component: FixedCostsPanel, permission: 'finance.view', period: false, description: 'Loyer, équipe, téléphone… : les frais fixes comptés dans la marge nette.' },
  pivot: { label: 'Analyse croisée', component: PivotTab, props: () => ({ from: from.value, to: to.value }), description: 'Croisez deux axes (client, zone, livreur, activité, jour, mois, heure) avec l\'indicateur de votre choix.' },
  share: { label: 'Alertes et partage', component: IntegrationsTab, permission: ['settings.manage', 'merchants.view'], period: false, description: 'Alertes du matin, point par e-mail, lien Google Sheets et rapports mensuels des clients sous contrat.' },
}
const SUMMARY = { key: '', label: 'Synthèse' }
const details = (keys) => keys.filter((k) => !DETAILS[k].permission || [DETAILS[k].permission].flat().some((p) => auth.can(p))).map((k) => ({ key: k, ...DETAILS[k] }))

// Un onglet par activité du périmètre de la personne, puis les équipes, les objectifs et les outils
const SCREENS = {
  ecommerce: { label: 'E-commerce', description: 'Parcours des colis, échecs, marge par zone, argent en circulation et clients principaux.', details: ['zones', 'delays', 'quality', 'clients', 'loyalty', 'profitability', 'cash'] },
  mad: { label: 'MAD', description: 'Forfaits, consommation, présence et retards par contrat, marge et échéances.', details: ['quality', 'clients', 'loyalty', 'profitability'] },
  distribution: { label: 'Distribution', description: 'Volumes par type d\'envoi, délais tenus, forfaits, lots et paiement des factures.', details: ['quality', 'clients', 'loyalty', 'profitability'] },
}
const tabs = computed(() => [
  { key: 'overview', label: 'Vue d\'ensemble', color: '#0f172a', endpoint: '/stats/direction', description: 'Les activités côte à côte, comparées à la période précédente, et les points à décider.', views: [SUMMARY, ...details(['daily', 'trends'])] },
  ...myActivities(auth.user).map((a) => ({
    key: a.value, color: COLORS[a.value], endpoint: `/stats/direction/${a.value}`, activity: a.value, label: SCREENS[a.value].label,
    description: SCREENS[a.value].description, views: [SUMMARY, ...details(SCREENS[a.value].details)],
  })),
  { key: 'teams', label: 'Équipes', color: '#6a4ce2', endpoint: '/stats/direction/teams', description: 'Livreurs, charge par zone, temps par mission, paie, véhicules et agents du bureau.', views: [SUMMARY, ...details(['field', 'vehicles'])] },
  { key: 'objectives', label: 'Objectifs', color: '#ec1d27', description: 'Les objectifs du mois, par activité et pour les équipes, comparés au réalisé.', views: [{ key: '', period: false }] },
  { key: 'decision', label: 'Aide à la décision', color: '#c9400c', description: 'Prévisions et simulateur.', views: details(['forecast', 'simulator']) },
  { key: 'tools', label: 'Outils', color: '#94a3b8', description: 'Réglages et outils des statistiques.', views: details(['costs', 'pivot', 'share']) },
].filter((t) => t.views.length))

const preset = ref('month')
const [initialFrom, initialTo] = PRESETS.month.range()
const from = ref(initialFrom)
const to = ref(initialTo)
// « ?vue= » : l'onglet ; « ?detail= » : une de ses vues de détail (liens « À décider »)
const tab = ref(tabs.value.some((t) => t.key === route.query.vue) ? route.query.vue : 'overview')
const current = computed(() => tabs.value.find((t) => t.key === tab.value) || tabs.value[0])
const firstView = (t) => t.views[0].key
const detail = ref(current.value.views.some((v) => v.key === route.query.detail) ? route.query.detail : firstView(current.value))
// La vue affichée : la synthèse de l'onglet (son écran propre) ou une vue de détail
const view = computed(() => {
  const found = current.value.views.find((v) => v.key === detail.value) || current.value.views[0]
  return found.key === '' ? { ...found, endpoint: current.value.endpoint } : found
})
const shown = computed(() => `${tab.value}:${detail.value}`)
const data = reactive({})
const loading = ref(false)
const error = ref('')

async function load() {
  error.value = ''
  if (!direction.value || !view.value.endpoint || !from.value || !to.value) return
  const key = shown.value
  const activity = view.value.activity === false ? undefined : current.value.activity
  loading.value = true
  try {
    data[key] = (await http.get(view.value.endpoint, { params: { from: from.value, to: to.value, activity } })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
// Nouvelle période : les vues déjà chargées ne valent plus
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
  if (!from.value || !to.value || from.value > to.value) return
  preset.value = 'custom'
  reload()
}
function selectTab(key) {
  tab.value = key
  detail.value = firstView(current.value)
}

// Export Excel : toutes les statistiques de la période, en plusieurs feuilles
const exporting = ref(false)
async function exportXlsx() {
  exporting.value = true
  try {
    const { data: blob } = await http.get('/stats/export', { params: { from: from.value, to: to.value }, responseType: 'blob' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `statistiques-${from.value}-au-${to.value}.xlsx`
    link.click()
    URL.revokeObjectURL(link.href)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    exporting.value = false
  }
}

watch(shown, () => {
  if (!data[shown.value]) load()
  router.replace({ query: { vue: tab.value, detail: detail.value || undefined } })
})
// Lien « À décider » ouvert depuis cette page : l'adresse change, la vue suit
watch(() => [route.query.vue, route.query.detail], ([vue, wanted]) => {
  if (vue && vue !== tab.value && tabs.value.some((t) => t.key === vue)) tab.value = vue
  if (vue !== tab.value) return
  const target = current.value.views.some((v) => v.key === (wanted || '')) ? (wanted || '') : firstView(current.value)
  if (target !== detail.value) detail.value = target
})

load()
</script>
