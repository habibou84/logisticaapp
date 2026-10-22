<template>
  <div class="space-y-4">
    <section v-if="summary" class="rounded-2xl p-5 text-white" :style="{ backgroundColor: 'var(--app-color)' }">
      <p class="text-sm opacity-80">{{ summary.available < 0 ? 'Vous devez à l\'agence' : 'On vous doit' }}</p>
      <p class="text-4xl font-bold mt-1">{{ money(Math.abs(summary.available)) }}</p>
      <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
        <div class="rounded-xl bg-white/15 p-3">
          <p class="opacity-80">Encore chez les livreurs</p>
          <p class="font-semibold text-lg">{{ money(summary.pending_cash) }}</p>
          <p class="text-xs opacity-80">encaissé, pas encore remis à l'agence</p>
        </div>
        <div class="rounded-xl bg-white/15 p-3">
          <p class="opacity-80">En tout, à venir</p>
          <p class="font-semibold text-lg">{{ money(summary.unpaid_total) }}</p>
          <p class="text-xs opacity-80">les deux ensemble</p>
        </div>
      </div>
    </section>

    <div class="flex rounded-xl bg-slate-200 p-1">
      <button v-for="t in tabs" :key="t.value" :class="['tap flex-1 rounded-lg py-2 text-sm font-medium', tab === t.value ? 'bg-white shadow-sm' : 'text-slate-600']" @click="tab = t.value">
        {{ t.label }}
      </button>
    </div>

    <template v-if="tab === 'payouts'">
      <RouterLink v-for="p in payouts" :key="p.id" :to="`/marchand/paiements/${p.id}`" class="block m-card p-4 active:bg-slate-50">
        <div class="flex justify-between items-center">
          <p :class="['text-lg font-bold', signedClass(p.net_amount)]">{{ money(p.net_amount) }}</p>
          <PayoutBadge :status="p.status" :label="p.status_label" />
        </div>
        <p class="text-sm text-slate-500">{{ date(p.period_start) }} → {{ date(p.period_end) }}</p>
        <p v-if="p.paid_at" class="text-sm text-slate-500">Payé le {{ date(p.paid_at) }} · {{ p.method_label }}</p>
      </RouterLink>
      <EmptyState v-if="loaded && !payouts.length" icon="💸" title="Aucun paiement pour le moment" text="Vos paiements apparaîtront ici dès que l'agence vous reversera l'argent encaissé." />
    </template>

    <template v-else>
      <div class="m-card divide-y">
        <div v-for="e in entries" :key="e.id" class="p-4 flex justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium">{{ e.type_label }}</p>
            <p class="text-xs text-slate-500 truncate">{{ e.order?.tracking_code || e.description }} · {{ dateTime(e.created_at) }}</p>
          </div>
          <p :class="['font-semibold whitespace-nowrap', signedClass(e.amount)]">{{ e.amount > 0 ? '+' : '' }}{{ money(e.amount) }}</p>
        </div>
      </div>
      <EmptyState v-if="loaded && !entries.length" icon="🧾" title="Aucune opération" />
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { cachedGet } from '../../composables/useCachedApi'
import PayoutBadge from '../../components/PayoutBadge.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { useAuthStore } from '../../stores/auth'
import { date, dateTime, money, signedClass } from '../../utils/format'

const auth = useAuthStore()
const tabs = [
  { value: 'payouts', label: 'Mes paiements' },
  { value: 'entries', label: 'Détail des mouvements' },
]
const tab = ref('payouts')
const summary = ref(null)
const entries = ref([])
const payouts = ref([])
const loaded = ref(false)

onMounted(async () => {
  try {
    const [ledger, list] = await Promise.all([
      cachedGet(`/finance/merchants/${auth.user.merchant_id}/ledger`),
      cachedGet('/finance/payouts'),
    ])
    summary.value = ledger.data.summary
    entries.value = ledger.data.data
    payouts.value = list.data.data
  } finally {
    loaded.value = true
  }
})
</script>
