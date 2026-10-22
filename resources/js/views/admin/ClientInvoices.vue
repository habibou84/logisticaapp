<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Factures clients</h1>
      <div class="flex flex-wrap items-end gap-2">
        <div>
          <label class="label" for="ci-month">Mois</label>
          <input id="ci-month" v-model="month" type="month" class="input w-auto" @change="load">
        </div>
        <div>
          <label class="label" for="ci-status">État</label>
          <select id="ci-status" v-model="status" class="input w-auto" @change="load">
            <option value="">Toutes</option>
            <option value="draft">Brouillons</option>
            <option value="issued">À payer</option>
            <option value="paid">Payées</option>
            <option value="void">Annulées</option>
          </select>
        </div>
        <button v-if="canManage" class="btn-primary" :disabled="generating || !month" @click="generate">
          {{ generating ? 'Calcul…' : `Calculer ${monthLabel}` }}
        </button>
      </div>
    </div>

    <p class="text-sm text-gray-600">
      Clients sous formule (distribution, MAD) : chaque 1er du mois, la facture du mois écoulé est calculée en brouillon.
      Vérifiez-la, ajoutez une remise si besoin, puis « Émettre » : elle reçoit son numéro et le client la voit dans son application.
    </p>

    <div v-if="totals" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
      <StatCard title="Reste à encaisser" :value="money(totals.to_collect)" icon="💰" />
      <StatCard title="En retard" :value="money(totals.overdue)" icon="⏰" />
      <StatCard title="Brouillons à vérifier" :value="String(totals.drafts)" icon="📝" />
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm stack-table">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Numéro</th><th class="p-2">Client</th><th class="p-2">Mois</th><th class="p-2 text-right">Total</th><th class="p-2 text-right">Reste</th><th class="p-2">État</th><th /></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="i in invoices" :key="i.id">
            <td class="p-2 font-mono">{{ i.number || '—' }}</td>
            <td data-label="Client" class="p-2 font-medium">{{ i.merchant?.business_name }}</td>
            <td data-label="Mois" class="p-2">{{ i.period_label }}</td>
            <td data-label="Total" class="p-2 text-right">{{ money(i.total) }}</td>
            <td data-label="Reste" class="p-2 text-right">{{ i.status === 'issued' ? money(i.balance) : '—' }}</td>
            <td data-label="État" class="p-2"><span :class="['rounded-full px-2 py-0.5 text-xs font-medium', badge(i)]">{{ i.status_label }}</span></td>
            <td class="p-2 text-right"><RouterLink :to="`/admin/factures/${i.id}`" class="text-blue-600">Ouvrir</RouterLink></td>
          </tr>
          <tr v-if="!invoices.length"><td colspan="7" class="p-6 text-center text-gray-500">{{ loading ? 'Chargement…' : 'Aucune facture.' }}</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import StatCard from '../../components/StatCard.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'

const auth = useAuthStore()
const toasts = useToastStore()
const canManage = computed(() => auth.can('finance.manage'))
const invoices = ref([])
const totals = ref(null)
const loading = ref(false)
const generating = ref(false)
const status = ref('')

// Par défaut : le mois écoulé
const previous = new Date()
previous.setDate(1)
previous.setMonth(previous.getMonth() - 1)
const month = ref(`${previous.getFullYear()}-${String(previous.getMonth() + 1).padStart(2, '0')}`)
const monthLabel = computed(() => {
  if (!month.value) return ''
  const [y, m] = month.value.split('-')
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
})

const BADGES = { draft: 'bg-slate-100 text-slate-700', issued: 'bg-amber-100 text-amber-800', paid: 'bg-emerald-100 text-emerald-800', void: 'bg-gray-100 text-gray-500' }
const badge = (i) => (i.is_overdue ? 'bg-red-100 text-red-700' : BADGES[i.status])

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/client-invoices', { params: { period: month.value || undefined, status: status.value || undefined } })
    invoices.value = data.data
    totals.value = data.totals
  } finally {
    loading.value = false
  }
}

async function generate() {
  generating.value = true
  try {
    const { data } = await http.post('/client-invoices/generate', { month: month.value })
    toasts.success(`${data.data.count} facture(s) calculée(s) pour ${monthLabel.value}.`)
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    generating.value = false
  }
}

onMounted(load)
</script>
