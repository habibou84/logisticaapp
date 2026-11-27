<template>
  <div v-if="vehicle" class="space-y-4">
    <div class="m-card p-4 space-y-3">
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-xl font-bold">{{ vehicle.plate || 'Sans plaque' }}</p>
          <p class="text-sm text-slate-600">{{ vehicle.name }}{{ vehicle.year ? ` · ${vehicle.year}` : '' }}</p>
        </div>
        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', vehicle.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">{{ vehicle.status_label }}</span>
      </div>
      <div class="grid grid-cols-2 gap-3 text-sm">
        <div><p class="text-slate-600">Compteur</p><p class="font-semibold">{{ vehicle.odometer ? `${vehicle.odometer.toLocaleString('fr-FR')} km` : '—' }}</p></div>
        <div><p class="text-slate-600">Livreur</p><p class="font-semibold">{{ vehicle.courier?.name || '—' }}</p></div>
      </div>
      <a v-if="vehicle.courier?.phone" :href="telLink(vehicle.courier.phone)" class="m-btn-secondary py-3 text-[15px]"><Icone nom="appeler" />Appeler {{ vehicle.courier.name }}</a>
    </div>

    <section v-if="todo.length || vehicle.remarks.length" class="space-y-2">
      <h2 class="px-1 text-sm font-semibold uppercase tracking-wide text-slate-600">À faire</h2>
      <div v-for="m in todo" :key="m.key" :class="['m-card border-l-4 p-3.5', m.state === 'due' ? 'border-l-red-500' : 'border-l-amber-400']">
        <p class="font-semibold">{{ m.label }}</p>
        <p class="text-sm text-slate-600">{{ maintenanceText(m) }}</p>
      </div>
      <div v-for="r in vehicle.remarks" :key="r.id" class="m-card border-l-4 border-l-orange-500 p-3.5">
        <p class="font-semibold">{{ r.remark }}</p>
        <p class="text-sm text-slate-600">Noté le {{ date(r.finished_at) }} après une réparation</p>
      </div>
    </section>

    <section class="space-y-2">
      <h2 class="px-1 text-sm font-semibold uppercase tracking-wide text-slate-600">Carnet</h2>
      <div v-if="vehicle.logs.length" class="m-card divide-y">
        <div v-for="l in vehicle.logs" :key="l.id" class="flex items-start gap-3 p-3.5">
          <Icone :nom="l.icon || 'atelier'" class="mt-0.5 text-slate-600" />
          <div class="min-w-0 flex-1">
            <p class="font-semibold leading-snug">{{ l.label || l.kind_label }}</p>
            <p class="text-sm text-slate-600">{{ date(l.done_on) }}{{ l.odometer ? ` · ${l.odometer.toLocaleString('fr-FR')} km` : '' }}</p>
          </div>
          <span class="shrink-0 text-sm text-slate-600">{{ l.amount ? money(l.amount) : '' }}</span>
        </div>
      </div>
      <p v-else class="px-1 text-sm text-slate-600">Rien au carnet pour l'instant.</p>
    </section>
  </div>
  <p v-else-if="error" class="py-10 text-center text-slate-600">{{ error }}</p>
  <p v-else class="py-10 text-center text-slate-400">Chargement…</p>
</template>

<script setup>
import Icone from '../../components/ui/Icone.vue'
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { date, money, telLink } from '../../utils/format'

const route = useRoute()
const vehicle = ref(null)
const error = ref('')

// Entretiens dus ou proches (plan d'entretien du véhicule)
const todo = computed(() => (vehicle.value?.maintenance || []).filter((m) => m.state !== 'ok'))

function maintenanceText(m) {
  const parts = []
  if (m.remaining_km !== null) parts.push(m.remaining_km <= 0 ? `dépassé de ${Math.abs(m.remaining_km).toLocaleString('fr-FR')} km` : `dans ${m.remaining_km.toLocaleString('fr-FR')} km`)
  if (m.remaining_days !== null) parts.push(m.remaining_days <= 0 ? `échéance passée (${date(m.due_on)})` : `avant le ${date(m.due_on)}`)
  return parts.join(' · ') || 'À faire'
}

onMounted(async () => {
  try {
    vehicle.value = (await http.get(`/mechanic/vehicles/${route.params.id}`)).data.data
  } catch (e) {
    error.value = apiErrorMessage(e, 'Véhicule introuvable.')
  }
})
</script>
