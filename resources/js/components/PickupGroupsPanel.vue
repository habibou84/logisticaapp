<template>
  <div class="space-y-4">
    <p class="text-sm text-gray-600">
      Colis validés à aller chercher, regroupés par client et adresse de ramassage, par zone.
      Un colis validé plus tard chez un client déjà confié à un livreur lui est ajouté automatiquement.
    </p>

    <p v-if="loading" class="card p-6 text-center text-gray-500">Chargement…</p>
    <p v-else-if="!zones.length" class="card p-6 text-center text-gray-500">Aucun ramassage à assigner{{ day ? ' pour ce jour' : '' }}.</p>

    <section v-for="zone in zones" v-else :key="zone.name" class="space-y-2">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">📍 {{ zone.name }} · {{ zone.count }} colis</h2>

      <div v-for="g in zone.groups" :key="g.key" class="card p-3 space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <button type="button" class="text-left flex-1 min-w-0" :aria-expanded="!!open[g.key]" @click="open[g.key] = !open[g.key]">
            <span class="block font-semibold">
              {{ open[g.key] ? '▾' : '▸' }} {{ g.merchant?.business_name || g.pickup.contact_name || 'Client' }}
              <span class="ml-1 rounded-full bg-indigo-100 text-indigo-800 text-xs font-semibold px-2 py-0.5">{{ visible(g).length }} colis</span>
            </span>
            <span class="block text-sm text-gray-600 truncate">{{ g.pickup.address || '—' }}<span v-if="g.pickup.phone"> · {{ g.pickup.phone }}</span></span>
            <span class="block text-xs text-gray-500">
              En attente depuis {{ dateTime(g.waiting_since) }}<span v-if="codTotal(g)"> · {{ money(codTotal(g)) }} à encaisser</span>
            </span>
          </button>
          <div class="flex flex-wrap items-center gap-2">
            <select v-model="courier[g.key]" class="input w-auto" :aria-label="`Livreur pour ${g.merchant?.business_name}`">
              <option :value="undefined" disabled>Livreur…</option>
              <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.active_assignments_count }} en cours){{ c.is_available ? '' : ' · indisponible' }}</option>
            </select>
            <button class="btn-primary" :disabled="!courier[g.key] || !chosen(g).length || busy === g.key" @click="assign(g)">
              {{ busy === g.key ? 'Assignation…' : `Assigner ${chosen(g).length === visible(g).length ? 'les' : ''} ${chosen(g).length}` }}
            </button>
          </div>
        </div>

        <!-- Colis du même client à livrer un autre jour : à prendre en avance si le livreur passe déjà -->
        <p v-if="hiddenLater(g)" class="text-xs text-amber-800">
          + {{ hiddenLater(g) }} colis à livrer plus tard chez ce client ·
          <button type="button" class="font-medium underline" @click="withLater[g.key] = true">les inclure</button>
        </p>

        <!-- Colis du lot : décocher ceux qui ne partent pas avec ce livreur -->
        <table v-if="open[g.key]" class="w-full text-sm">
          <tbody class="divide-y">
            <tr v-for="o in visible(g)" :key="o.id" :class="excluded[o.id] ? 'opacity-50' : ''">
              <td class="p-2 w-8">
                <input type="checkbox" :checked="!excluded[o.id]" :aria-label="`Inclure ${o.tracking_code}`" @change="excluded[o.id] = !$event.target.checked">
              </td>
              <td class="p-2 font-mono text-xs">
                <RouterLink :to="`${auth.homeRoute}/courses/${o.id}`" class="text-blue-600">{{ o.tracking_code }}</RouterLink>
              </td>
              <td class="p-2">{{ o.recipient_name || '—' }}</td>
              <td class="p-2 whitespace-nowrap">
                → {{ o.delivery_zone_name }}<span v-if="o.is_express" class="ml-1 text-red-600">⚡</span>
                <span v-if="o.later" class="ml-1 rounded-full bg-amber-100 px-1.5 text-xs text-amber-800">📅 livraison le {{ date(o.delivery_day) }}</span>
              </td>
              <td class="p-2 text-right whitespace-nowrap">{{ money(o.cod_amount) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else-if="excludedCount(g)" class="text-xs text-amber-700">{{ excludedCount(g) }} colis retiré(s) du lot.</p>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toasts'
import { orderChanges } from '../composables/useRealtime'
import { date, dateTime, money } from '../utils/format'

/**
 * Ramassages par client : un lot par client et adresse, assigné d'un clic au même livreur.
 */
const props = defineProps({
  couriers: { type: Array, default: () => [] },
  // Jour choisi (AAAA-MM-JJ) : colis à livrer après ce jour masqués, sauf demande
  day: { type: String, default: '' },
  includeLater: { type: Boolean, default: false },
})
const emit = defineEmits(['assigned'])

const auth = useAuthStore()
const toasts = useToastStore()
const groups = ref([])
const loading = ref(true)
const busy = ref(null)
const open = reactive({})
const courier = reactive({})
const excluded = reactive({})
// Lots où l'on a demandé d'inclure les colis prévus plus tard
const withLater = reactive({})

const visible = (g) => g.orders.filter((o) => !o.later || props.includeLater || withLater[g.key])
const codTotal = (g) => visible(g).reduce((sum, o) => sum + (o.cod_amount || 0), 0)
const hiddenLater = (g) => (props.includeLater || withLater[g.key] ? 0 : g.orders.filter((o) => o.later).length)

// Déjà triés par zone puis par nombre de colis
const zones = computed(() => {
  const byZone = new Map()
  for (const g of groups.value) {
    // Lot entièrement prévu plus tard : affiché seulement sur demande
    if (!visible(g).length) continue
    const name = g.pickup.zone_name || 'Sans zone'
    if (!byZone.has(name)) byZone.set(name, { name, count: 0, groups: [] })
    const zone = byZone.get(name)
    zone.groups.push(g)
    zone.count += visible(g).length
  }
  return [...byZone.values()]
})

const chosen = (g) => visible(g).filter((o) => !excluded[o.id]).map((o) => o.id)
const excludedCount = (g) => visible(g).length - chosen(g).length

async function load() {
  try {
    const { data } = await http.get('/orders/pickup-groups', { params: props.day ? { day: props.day } : {} })
    groups.value = data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

async function assign(g) {
  busy.value = g.key
  try {
    const { data } = await http.post('/orders/bulk-assign', { order_ids: chosen(g), type: 'pickup', courier_id: courier[g.key] })
    if (data.data.assigned.length) toasts.success(`${data.data.assigned.length} colis confié(s) pour ${g.merchant?.business_name || 'ce client'}.`)
    Object.entries(data.data.errors).forEach(([id, message]) => {
      const code = g.orders.find((o) => o.id === Number(id))?.tracking_code || id
      toasts.error(`${code} : ${message}`)
    })
    delete courier[g.key]
    await load()
    emit('assigned')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = null
  }
}

// Temps réel : nouveaux colis validés, assignations faites ailleurs
watch(orderChanges, load)
watch(() => props.day, load)

onMounted(load)
</script>
