<template>
  <div class="space-y-4 max-w-6xl">
    <div>
      <h1 class="text-xl font-bold">Retours marchands</h1>
      <p class="text-sm text-gray-600">
        Regroupez les colis à rendre d'un marchand sur un bon de retour confié à un livreur. Le marchand est prévenu par
        WhatsApp ; à la remise, il signe dans l'application du livreur et toutes les courses passent « Retourné ».
      </p>
    </div>

    <!-- Colis à rendre, par marchand -->
    <section class="space-y-3">
      <h2 class="font-semibold">À rendre ({{ candidateCount }} colis)</h2>
      <div v-for="g in candidates" :key="g.merchant.id" class="card">
        <header class="flex flex-wrap items-center justify-between gap-2 p-4 border-b">
          <div>
            <p class="font-semibold">🏪 {{ g.merchant.business_name }}</p>
            <p class="text-sm text-gray-600">{{ g.merchant.phone }} · {{ [g.merchant.zone, g.merchant.address].filter(Boolean).join(' · ') }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <select v-model="courierFor[g.merchant.id]" class="input w-auto" :aria-label="`Livreur pour ${g.merchant.business_name}`">
              <option :value="undefined" disabled>Livreur…</option>
              <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}{{ holderCount(g, c.id) ? ` · a ${holderCount(g, c.id)} colis` : '' }}{{ c.is_available ? '' : ' · indisponible' }}</option>
            </select>
            <button class="btn-primary" :disabled="!selectedOf(g).length || !courierFor[g.merchant.id] || busy" @click="create(g)">📮 Créer le bon ({{ selectedOf(g).length }})</button>
          </div>
        </header>
        <table class="w-full text-sm">
          <tbody class="divide-y">
            <tr v-for="o in g.orders" :key="o.id">
              <td class="p-2 w-8"><input v-model="selected" type="checkbox" :value="o.id" :aria-label="`Colis ${o.tracking_code}`"></td>
              <td class="p-2"><RouterLink :to="`/admin/courses/${o.id}`" class="font-mono text-blue-700">{{ o.tracking_code }}</RouterLink></td>
              <td class="p-2">{{ o.recipient_name || o.recipient_phone }} <span class="text-xs text-gray-500">{{ o.zone }}</span></td>
              <td class="p-2 text-xs">{{ o.status_label }}<span v-if="o.incident"> · {{ o.incident }}</span></td>
              <td class="p-2 text-xs">
                <span v-if="o.held_by" class="text-amber-700">🎒 chez {{ o.held_by.name }}</span>
                <span v-else class="text-emerald-700">Au dépôt</span>
              </td>
              <td class="p-2 text-right">{{ money(o.items_amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="loaded && !candidates.length" class="card p-6 text-center text-gray-500">🎉 Aucun colis à rendre aux marchands.</p>
    </section>

    <!-- Bons -->
    <section class="card">
      <h2 class="font-semibold p-4 border-b">Bons de retour (30 derniers jours)</h2>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Bon</th><th class="p-2">Marchand</th><th class="p-2">Livreur</th><th class="p-2">Colis</th><th class="p-2">État</th><th /></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="s in slips" :key="s.id">
            <td class="p-2 font-mono text-xs">{{ s.reference }}<div class="text-gray-500">{{ dateTime(s.created_at) }}</div></td>
            <td class="p-2">{{ s.merchant?.business_name }}</td>
            <td class="p-2">{{ s.courier?.name }}</td>
            <td class="p-2">{{ s.orders_count }}</td>
            <td class="p-2">
              <span :class="['rounded-full px-2 py-0.5 text-xs', s.status === 'handed' ? 'bg-emerald-100 text-emerald-800' : s.status === 'open' ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-600']">{{ s.status_label }}</span>
              <div v-if="s.handed_at" class="text-xs text-gray-500">{{ dateTime(s.handed_at) }} · {{ s.received_by_name }}</div>
            </td>
            <td class="p-2 text-right whitespace-nowrap space-x-1">
              <RouterLink :to="`/bon-de-retour/${s.id}`" class="btn-secondary text-xs">🖨️ Voir / imprimer</RouterLink>
              <button v-if="s.status === 'open'" class="btn-secondary text-xs" @click="cancel(s)">Annuler</button>
            </td>
          </tr>
          <tr v-if="!slips.length"><td colspan="6" class="p-4 text-center text-gray-500">Aucun bon de retour.</td></tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { dateTime, money } from '../../utils/format'

const router = useRouter()
const toasts = useToastStore()
const candidates = ref([])
const slips = ref([])
const couriers = ref([])
const selected = ref([])
const courierFor = reactive({})
const loaded = ref(false)
const busy = ref(false)

const candidateCount = computed(() => candidates.value.reduce((s, g) => s + g.orders.length, 0))
const selectedOf = (g) => g.orders.filter((o) => selected.value.includes(o.id)).map((o) => o.id)
const holderCount = (g, courierId) => g.orders.filter((o) => o.held_by?.id === courierId).length

async function load() {
  const { data } = await http.get('/return-slips')
  candidates.value = data.candidates
  slips.value = data.data
  // Par défaut : tous les colis cochés, livreur qui en a déjà le plus en main
  selected.value = data.candidates.flatMap((g) => g.orders.map((o) => o.id))
  for (const g of data.candidates) {
    if (courierFor[g.merchant.id]) continue
    const holders = g.orders.filter((o) => o.held_by).map((o) => o.held_by.id)
    if (holders.length) courierFor[g.merchant.id] = holders.sort((a, b) => holders.filter((x) => x === b).length - holders.filter((x) => x === a).length)[0]
  }
  loaded.value = true
}

async function create(g) {
  busy.value = true
  try {
    const { data } = await http.post('/return-slips', { merchant_id: g.merchant.id, courier_id: courierFor[g.merchant.id], order_ids: selectedOf(g) })
    toasts.success(`Bon ${data.data.reference} créé : ${data.data.orders_count} colis. Le marchand est prévenu.`)
    router.push(`/bon-de-retour/${data.data.id}`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function cancel(s) {
  if (!confirm(`Annuler le bon ${s.reference} ? Les missions de retour restent assignées.`)) return
  try {
    await http.post(`/return-slips/${s.id}/cancel`)
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(async () => {
  load()
  couriers.value = (await http.get('/couriers')).data.data
})
</script>
