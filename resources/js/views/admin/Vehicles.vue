<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">Véhicules</h1>
        <p class="text-sm text-gray-600">Le parc, qui roule avec quoi, les papiers et l'entretien.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <RouterLink to="/admin/vehicules/echeances" class="btn-secondary">📅 Échéances</RouterLink>
        <RouterLink v-if="auth.can('settings.manage')" to="/admin/reglages/vehicules" class="btn-secondary">⚙️ Réglages</RouterLink>
        <button type="button" class="btn-primary" @click="editing = null; formOpen = true">+ Ajouter un véhicule</button>
      </div>
    </div>

    <template v-if="data">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <StatCard title="Véhicules" :value="String(k.total)" :hint="typesText" />
        <StatCard title="En service" :value="String(k.active)" :hint="k.own ? `dont ${k.own} du livreur` : ''" tone="text-emerald-700" />
        <StatCard title="En panne ou à l'entretien" :value="String(k.stopped)" tone="text-red-700" />
        <StatCard title="Échéances à surveiller" :value="String(k.deadlines)" :hint="k.expired ? `${k.expired} dépassée(s)` : ''" tone="text-amber-700" />
        <StatCard title="Coût moyen par course" :value="k.cost_per_course ? money(k.cost_per_course) : '—'" hint="30 derniers jours" />
      </div>

      <div class="card overflow-x-auto">
        <table class="w-full text-left text-sm min-w-[56rem]">
          <thead class="text-xs uppercase text-gray-500">
            <tr><th class="p-3" /><th class="p-3">Véhicule</th><th class="p-3">Livreur</th><th class="p-3">État</th><th class="p-3">Propriétaire</th><th class="p-3">Compteur</th><th class="p-3">À surveiller</th><th class="p-3">Coût / course</th></tr>
          </thead>
          <tbody>
            <tr v-for="v in data.data" :key="v.id" class="border-t border-slate-100 hover:bg-slate-50 cursor-pointer" @click="router.push(`/admin/vehicules/${v.id}`)">
              <td class="p-3 text-xl">{{ VEHICLE_TYPES[v.type]?.icon }}</td>
              <td class="p-3"><p class="whitespace-nowrap font-mono font-semibold">{{ v.plate }}</p><p class="text-xs text-gray-500">{{ [v.brand, v.model, v.year].filter(Boolean).join(' ') }}</p></td>
              <td class="p-3">{{ v.courier?.name || '—' }}</td>
              <td class="p-3"><span :class="['whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', STATUS_STYLES[v.status]]">{{ v.status_label }}</span></td>
              <td class="p-3"><span :class="['whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', OWNERSHIP_STYLES[v.ownership]]">{{ v.ownership_label }}</span></td>
              <td class="p-3 whitespace-nowrap">{{ km(v.odometer) }}</td>
              <td class="p-3">
                <span v-if="v.watch" :class="['whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', STATE_STYLES[v.watch.state]]">{{ v.watch.label }} · {{ alertWhen(v.watch) }}</span>
                <span v-else class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">À jour</span>
              </td>
              <td class="p-3 font-medium">{{ v.cost_per_course ? money(v.cost_per_course) : '—' }}</td>
            </tr>
            <tr v-if="!data.data.length"><td colspan="8" class="p-8 text-center text-gray-500">Aucun véhicule. Ajoutez le premier pour suivre ses papiers, son entretien et ses coûts.</td></tr>
          </tbody>
        </table>
      </div>

      <div v-if="data.alerts.length" class="card p-4">
        <h2 class="font-semibold">⚠️ À faire</h2>
        <ul class="mt-2 grid gap-x-6 sm:grid-cols-2">
          <li v-for="(a, i) in data.alerts.slice(0, 12)" :key="i" class="flex items-center gap-2 py-1.5 text-sm">
            <span>{{ a.icon }}</span>
            <RouterLink :to="a.vehicle_id ? `/admin/vehicules/${a.vehicle_id}` : '/admin/vehicules/echeances'" class="flex-1 hover:underline"><b>{{ a.title }}</b> · {{ a.label }}</RouterLink>
            <span :class="['whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', STATE_STYLES[a.state]]">{{ alertWhen(a) }}</span>
          </li>
        </ul>
      </div>
    </template>
    <p v-else class="text-gray-500">Chargement…</p>

    <VehicleFormModal v-if="catalog" :open="formOpen" :vehicle="editing" :couriers="data?.couriers || []" :catalog="catalog" @close="formOpen = false" @saved="(v) => router.push(`/admin/vehicules/${v.id}`)" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http from '../../bootstrap/axios'
import StatCard from '../../components/vehicles/VKpi.vue'
import VehicleFormModal from '../../components/vehicles/VehicleFormModal.vue'
import { useAuthStore } from '../../stores/auth'
import { money } from '../../utils/format'
import { OWNERSHIP_STYLES, STATE_STYLES, STATUS_STYLES, VEHICLE_TYPES, daysText, km } from '../../utils/vehicles'

const auth = useAuthStore()
const router = useRouter()
const data = ref(null)
const catalog = ref(null)
const formOpen = ref(false)
const editing = ref(null)
const k = computed(() => data.value.kpis)
const typesText = computed(() => Object.entries(k.value.by_type || {}).map(([t, n]) => `${n} ${VEHICLE_TYPES[t]?.label.toLowerCase()}${n > 1 ? 's' : ''}`).join(' · '))

function alertWhen(a) {
  if (a.kind === 'lease') return money(a.amount)
  if (a.kind === 'status') return 'immobilisé'
  if (a.kind === 'maintenance') return a.km !== null && a.km !== undefined ? (a.km <= 0 ? `dépassé de ${-a.km} km` : `dans ${a.km} km`) : daysText(a.days)
  return daysText(a.days)
}

onMounted(async () => {
  const [list, cat] = await Promise.all([http.get('/vehicles'), http.get('/vehicles/catalog')])
  data.value = list.data
  catalog.value = cat.data.data
})
</script>
