<template>
  <div class="space-y-4">
    <!-- Choix de la période -->
    <div class="-mx-4 px-4 flex gap-2 overflow-x-auto no-scrollbar" role="radiogroup" aria-label="Période">
      <button
        v-for="p in presets"
        :key="p.key"
        type="button"
        role="radio"
        :aria-checked="period === p.key"
        :class="['tap shrink-0 rounded-full px-4 py-2 text-sm font-medium ring-1', period === p.key ? 'text-white ring-transparent' : 'bg-white ring-slate-200 text-slate-700']"
        :style="period === p.key ? { backgroundColor: 'var(--app-color)' } : {}"
        @click="choose(p.key)"
      >{{ p.label }}</button>
    </div>

    <p v-if="error" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
    <p v-if="!data && !error" class="py-10 text-center text-slate-500">Chargement…</p>

    <template v-if="data">
      <!-- Taux de livraison : le chiffre qui compte -->
      <section class="rounded-2xl p-5 text-white" :style="{ backgroundColor: 'var(--app-color)' }">
        <p class="text-sm opacity-80">Taux de livraison</p>
        <p class="text-4xl font-bold mt-1">{{ percent(data.kpis.success_rate) }}</p>
        <p class="text-sm opacity-80">
          {{ data.kpis.delivered }} livrée{{ data.kpis.delivered > 1 ? 's' : '' }} parmi vos courses terminées
          <template v-if="trend(data.kpis.success_rate, data.previous_kpis.success_rate, true)"> · {{ trend(data.kpis.success_rate, data.previous_kpis.success_rate, true) }}</template>
        </p>
      </section>

      <div class="grid grid-cols-2 gap-3">
        <div v-for="t in tiles" :key="t.label" class="m-card p-4">
          <p class="text-xs text-slate-500">{{ t.label }}</p>
          <p class="text-xl font-bold mt-0.5">{{ t.value }}</p>
          <p v-if="t.hint" :class="['text-xs mt-0.5', t.hintClass || 'text-slate-400']">{{ t.hint }}</p>
        </div>
      </div>

      <section class="m-card p-4 space-y-2">
        <h2 class="font-semibold">Mes courses jour par jour</h2>
        <LineChart
          :labels="data.series.map((d) => d.date)"
          :series="[
            { label: 'Créées', color: SERIES[0], values: data.series.map((d) => d.created) },
            { label: 'Livrées', color: SERIES[1], values: data.series.map((d) => d.delivered) },
          ]"
          :height="180"
          aria-label="Mes courses créées et livrées par jour"
        />
      </section>

      <section class="m-card p-4 space-y-3">
        <h2 class="font-semibold">Pourquoi des livraisons échouent</h2>
        <BarList :items="data.failure_reasons" :color="SERIES[1]" empty="Aucun échec sur la période. 👏" />
        <p v-if="data.failure_reasons.length" class="text-xs text-slate-500">
          Astuce : un client injoignable ou absent revient souvent à un numéro mal noté. Vérifiez-le avant de créer la course.
        </p>
      </section>

      <section class="m-card p-4 space-y-3">
        <h2 class="font-semibold">Où vos clients sont livrés</h2>
        <ul v-if="data.zones.length" class="divide-y text-sm">
          <li v-for="z in data.zones" :key="z.id" class="flex items-center justify-between py-2">
            <span>{{ z.name }}</span>
            <span class="text-right">
              <strong>{{ z.delivered }}</strong> livrée{{ z.delivered > 1 ? 's' : '' }}
              <span v-if="z.success_rate !== null" :class="['ml-1 text-xs', z.success_rate < 75 ? 'text-red-600' : 'text-slate-400']">· {{ percent(z.success_rate) }}</span>
            </span>
          </li>
        </ul>
        <p v-else class="text-sm text-slate-500">Aucune livraison sur la période.</p>
      </section>

      <section class="m-card p-4 space-y-2 text-sm">
        <h2 class="font-semibold">Délais habituels</h2>
        <div class="flex justify-between"><span class="text-slate-600">De la création au ramassage</span><strong>{{ duration(data.delays.to_pickup) }}</strong></div>
        <div class="flex justify-between"><span class="text-slate-600">Du ramassage à la livraison</span><strong>{{ duration(data.delays.pickup_to_delivery) }}</strong></div>
        <div class="flex justify-between"><span class="text-slate-600">Livrées le jour même</span><strong>{{ percent(data.same_day_rate) }}</strong></div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BarList from '../../components/stats/BarList.vue'
import LineChart from '../../components/stats/LineChart.vue'
import { SERIES } from '../../components/stats/chart'
import { money, today, waitDuration } from '../../utils/format'

const route = useRoute()
const router = useRouter()

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
const presets = [
  { key: 'week', label: '7 derniers jours', range: () => [daysAgo(6), today()] },
  { key: 'month', label: 'Ce mois-ci', range: () => [`${today().slice(0, 7)}-01`, today()] },
  {
    key: 'lastMonth',
    label: 'Mois dernier',
    range: () => {
      const d = new Date()
      return [iso(new Date(d.getFullYear(), d.getMonth() - 1, 1)), iso(new Date(d.getFullYear(), d.getMonth(), 0))]
    },
  },
  { key: 'days90', label: '3 derniers mois', range: () => [daysAgo(89), today()] },
]

const period = ref(presets.some((p) => p.key === route.query.periode) ? route.query.periode : 'month')
const data = ref(null)
const error = ref('')

async function load() {
  error.value = ''
  const [from, to] = presets.find((p) => p.key === period.value).range()
  try {
    data.value = (await http.get('/merchant/stats', { params: { from, to } })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function choose(key) {
  period.value = key
  data.value = null
  router.replace({ query: key === 'month' ? {} : { periode: key } })
  load()
}

const percent = (v) => (v === null || v === undefined ? '—' : `${v.toLocaleString('fr-FR')} %`)
const duration = (m) => (m === null || m === undefined ? '—' : waitDuration(m))

// Évolution par rapport à la période précédente de même durée
function trend(value, previous, points = false) {
  if (value === null || previous === null || previous === undefined) return ''
  if (points) {
    const diff = Math.round((value - previous) * 10) / 10
    return diff ? `${diff > 0 ? '▲' : '▼'} ${Math.abs(diff).toLocaleString('fr-FR')} pt` : ''
  }
  if (!previous) return ''
  const change = Math.round(((value - previous) / previous) * 100)
  return change ? `${change > 0 ? '▲' : '▼'} ${Math.abs(change)} % vs avant` : 'stable'
}

const tiles = computed(() => {
  const k = data.value.kpis
  const p = data.value.previous_kpis
  return [
    { label: 'Courses créées', value: k.created, hint: trend(k.created, p.created) },
    { label: 'Réussies au 1er passage', value: percent(data.value.first_attempt_rate) },
    { label: 'Passages ratés', value: k.failed_attempts, hint: k.failed_attempts ? 'client absent, injoignable…' : '' },
    { label: 'Retournées · annulées', value: `${k.returned} · ${k.cancelled}` },
    ...(k.collected !== null
      ? [
          { label: 'Encaissé pour vous', value: money(k.collected), hint: trend(k.collected, p.collected) },
          { label: 'Frais de livraison', value: money(k.revenue), hint: k.average_fee ? `${money(k.average_fee)} par course en moyenne` : '' },
        ]
      : []),
  ]
})

load()
</script>
