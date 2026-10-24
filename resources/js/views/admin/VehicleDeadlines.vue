<template>
  <div class="space-y-4">
    <RouterLink to="/admin/vehicules" class="text-sm text-blue-600">← Véhicules</RouterLink>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">Véhicules · Échéances</h1>
        <p class="text-sm text-gray-600">Papiers des véhicules et des livreurs, entretien, versements : tout ce qui a une date.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-for="f in FILTERS" :key="f.value" type="button" :class="['btn', filter === f.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']" @click="filter = f.value">{{ f.label }} ({{ count(f.value) }})</button>
      </div>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-left text-sm min-w-[44rem]">
        <thead class="text-xs uppercase text-gray-500"><tr><th class="p-3" /><th class="p-3">Échéance</th><th class="p-3">Pour</th><th class="p-3">Date</th><th class="p-3">Reste</th><th class="p-3">Effet</th></tr></thead>
        <tbody>
          <tr v-for="(a, i) in shown" :key="i" class="border-t border-slate-100">
            <td class="p-3 text-xl">{{ a.icon }}</td>
            <td class="p-3 font-medium">{{ a.label }}<span v-if="a.courier" class="font-normal text-gray-500"> · {{ a.courier }}</span></td>
            <td class="p-3"><RouterLink v-if="a.vehicle_id" :to="`/admin/vehicules/${a.vehicle_id}?onglet=${TAB[a.kind] || 'resume'}`" class="whitespace-nowrap font-mono text-blue-700">{{ a.title }}</RouterLink><span v-else>{{ a.title }}</span></td>
            <td class="p-3">{{ a.date ? date(a.date) : '—' }}</td>
            <td class="p-3"><span :class="['whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', STATE_STYLES[a.state]]">{{ when(a) }}</span></td>
            <td class="p-3 text-gray-500">{{ effect(a) }}</td>
          </tr>
          <tr v-if="!shown.length"><td colspan="6" class="p-8 text-center text-gray-500">Rien à surveiller ici. 👍</td></tr>
        </tbody>
      </table>
    </div>

    <section class="card p-4 space-y-2">
      <div class="flex items-center justify-between"><h2 class="font-semibold">🪪 Permis des livreurs</h2><button type="button" class="btn-secondary text-sm" @click="licenseOpen = true">+ Ajouter un permis</button></div>
      <table class="w-full text-sm">
        <tr v-for="d in licenses" :key="d.id" class="border-t border-slate-100"><td class="py-2">{{ d.courier }}</td><td>{{ d.reference || '' }}</td><td>{{ d.ends_on ? `jusqu'au ${date(d.ends_on)}` : '' }}</td>
          <td><span :class="['rounded-full px-2 py-0.5 text-xs font-medium', STATE_STYLES[d.state]]">{{ d.state === 'permanent' ? 'sans fin' : daysText(d.days) }}</span></td></tr>
        <tr v-if="!licenses.length"><td class="py-3 text-gray-500">Aucun permis enregistré.</td></tr>
      </table>
      <div v-if="licenseOpen" class="flex flex-wrap items-end gap-2 border-t pt-3">
        <div><label class="label" for="lic-courier">Livreur</label><select id="lic-courier" v-model="licenseCourier" class="input"><option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <button type="button" class="btn-primary" :disabled="!licenseCourier" @click="docOpen = true">Continuer</button>
      </div>
    </section>
    <p class="text-sm text-gray-500">Rappels {{ settings ? settings.reminder_days.map((d) => `à ${d} j`).join(' et ') : '' }} avant la date, aux responsables (cloche) et au livreur concerné.</p>
    <DocumentModal v-if="catalog" :open="docOpen" :courier-id="licenseCourier" :catalog="catalog" @close="docOpen = false" @saved="licenseOpen = false; load()" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import DocumentModal from '../../components/vehicles/DocumentModal.vue'
import { date, money } from '../../utils/format'
import { STATE_STYLES, daysText } from '../../utils/vehicles'

const FILTERS = [{ value: 'expired', label: 'Dépassées' }, { value: 'soon', label: 'Bientôt' }, { value: 'ok', label: 'À 90 jours' }, { value: 'all', label: 'Tout' }]
const TAB = { document: 'papiers', maintenance: 'entretien', lease: 'location' }
const items = ref([])
const settings = ref(null)
const catalog = ref(null)
const couriers = ref([])
const licenses = ref([])
const filter = ref('all')
const licenseOpen = ref(false)
const licenseCourier = ref(null)
const docOpen = ref(false)

const count = (f) => (f === 'all' ? items.value.length : items.value.filter((a) => a.state === f).length)
const shown = computed(() => items.value.filter((a) => filter.value === 'all' || a.state === filter.value)
  .sort((a, b) => ['expired', 'soon', 'ok'].indexOf(a.state) - ['expired', 'soon', 'ok'].indexOf(b.state) || (a.days ?? 0) - (b.days ?? 0)))

function when(a) {
  if (a.kind === 'lease') return `${money(a.amount)} en retard`
  if (a.kind === 'maintenance' && a.km !== null && a.km !== undefined) return a.km <= 0 ? `dépassé de ${-a.km} km` : `dans ${a.km} km`
  return daysText(a.days)
}
function effect(a) {
  if (a.kind === 'document' && a.state === 'expired' && settings.value?.block_expired && ['Assurance', 'Visite technique'].includes(a.label)) return 'Véhicule bloqué : plus de missions'
  if (a.kind === 'lease') return 'Rappels au livreur puis au responsable'
  if (a.state === 'soon') return 'Rappel envoyé'
  return ''
}

async function load() {
  const [d, lic] = await Promise.all([http.get('/vehicles/deadlines'), http.get('/vehicles/courier-documents')])
  items.value = d.data.data
  settings.value = d.data.settings
  licenses.value = lic.data.data.filter((x, i, all) => all.findIndex((y) => y.courier_id === x.courier_id) === i)
}

onMounted(async () => {
  const [cat, list] = await Promise.all([http.get('/vehicles/catalog'), http.get('/couriers')])
  catalog.value = cat.data.data
  couriers.value = list.data.data.map((c) => ({ id: c.id, name: c.name }))
  licenseCourier.value = couriers.value[0]?.id ?? null
  load()
})
</script>
