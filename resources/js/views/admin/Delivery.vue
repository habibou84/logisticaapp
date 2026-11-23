<template>
  <div class="max-w-5xl space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">Zones et tarifs</h1>
        <p class="text-sm text-slate-600">Où vous livrez et à quel prix, au même endroit.</p>
      </div>
      <!-- Grille dont on règle les prix : la grille normale, ou une grille négociée -->
      <div v-if="grids.length > 1 && ['zones', 'supplements'].includes(tab)" class="flex items-center gap-2">
        <label for="grid" class="text-sm font-semibold text-slate-700">Prix de la grille</label>
        <select id="grid" v-model="gridId" class="input w-auto" @change="chooseGrid(gridId)">
          <option v-for="g in grids" :key="g.id" :value="g.id">{{ g.name }}{{ g.is_default ? ' (normale)' : '' }}</option>
        </select>
      </div>
    </div>

    <p v-if="grid && !grid.is_default && ['zones', 'supplements'].includes(tab)" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-900">
      Vous réglez la grille négociée « {{ grid.name }} » ({{ grid.merchants_count ?? 0 }} client{{ (grid.merchants_count ?? 0) > 1 ? 's' : '' }}).
      Un trajet sans prix ici reprend celui de la grille normale.
    </p>

    <div v-if="tabs.length > 1" role="tablist" aria-label="Zones et tarifs" class="flex flex-wrap gap-1 border-b border-slate-300">
      <button
        v-for="t in tabs" :key="t.value" type="button" role="tab" :aria-selected="tab === t.value"
        :class="['-mb-px min-h-11 border-b-2 px-4 text-sm font-semibold', tab === t.value ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-600 hover:text-slate-900']"
        @click="setTab(t.value)"
      >
        {{ t.label }}
      </button>
    </div>

    <template v-if="tab === 'zones'">
      <ZonePrices v-if="gridId || !canPrices" :key="`z${gridId}`" :grid-id="gridId ?? 0" :is-default="!grid || !!grid.is_default" />
    </template>
    <template v-else-if="tab === 'supplements'">
      <SurchargesEditor v-if="gridId" :key="`s${gridId}`" :grid-id="gridId" />
      <ReturnFeeSettings />
    </template>
    <PricingGridsManager v-else-if="tab === 'grilles'" :grids="grids" @changed="loadGrids" @edit="(id) => { chooseGrid(id); setTab('zones') }" />
    <template v-else-if="tab === 'distribution'">
      <p class="text-sm text-slate-600">
        Grille commune des villes de l'intérieur et de la distribution à l'unité, et prix négociés de certains clients, qui la remplacent
        pour eux. Le contrat de chaque client (forfait du mois, zones couvertes) reste dans Contrats.
      </p>
      <ClientRates :initial-merchant-id="route.query.client || null" />
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '../../bootstrap/axios'
import ClientRates from '../../components/ClientRates.vue'
import PricingGridsManager from '../../components/delivery/PricingGridsManager.vue'
import SurchargesEditor from '../../components/delivery/SurchargesEditor.vue'
import ZonePrices from '../../components/delivery/ZonePrices.vue'
import ReturnFeeSettings from '../../components/settings/ReturnFeeSettings.vue'
import { useAuthStore } from '../../stores/auth'
import { myActivities } from '../../utils/activities'

/**
 * Zones et tarifs (repris de Jibiat) : les communes (on collecte, on livre, forfait) et leurs prix au départ, les
 * suppléments et les retours, les grilles négociées, et les tarifs de la distribution et de la mise à disposition.
 * Remplace les pages Zones, Tarifs de livraison et Tarifs distribution. Chaque onglet suit les droits du compte.
 */
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const canPrices = computed(() => auth.can('pricing.manage'))
const formulas = computed(() => myActivities(auth.user).some((a) => ['distribution', 'mad'].includes(a.value)))
const tabs = computed(() => [
  { value: 'zones', label: 'Zones et prix', show: auth.can('zones.manage') || canPrices.value },
  { value: 'supplements', label: 'Suppléments et retours', show: canPrices.value },
  { value: 'grilles', label: 'Grilles négociées', show: canPrices.value },
  { value: 'distribution', label: 'Distribution et MAD', show: formulas.value && (canPrices.value || auth.can('rates.manage')) },
].filter((t) => t.show))
const tab = ref(tabs.value.some((t) => t.value === route.query.onglet) ? route.query.onglet : tabs.value[0]?.value)
const grids = ref([])
const gridId = ref(Number(route.query.grille) || null)
const grid = computed(() => grids.value.find((g) => g.id === gridId.value) ?? null)

function setTab(value) {
  tab.value = value
  router.replace({ query: { ...route.query, onglet: value === tabs.value[0]?.value ? undefined : value } })
}

function chooseGrid(id) {
  gridId.value = id
  const isDefault = grids.value.find((g) => g.id === id)?.is_default
  router.replace({ query: { ...route.query, grille: isDefault ? undefined : id } })
}

async function loadGrids() {
  if (!canPrices.value) return
  grids.value = (await http.get('/pricing-grids')).data.data
  if (!grids.value.some((g) => g.id === gridId.value)) gridId.value = grids.value.find((g) => g.is_default)?.id ?? grids.value[0]?.id ?? null
}

onMounted(loadGrids)
</script>
