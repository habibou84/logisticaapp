<template>
  <div v-if="wallet" class="space-y-4">
    <p v-if="stale" class="rounded-xl bg-amber-50 text-amber-800 text-sm px-4 py-3">Hors ligne : montants de la dernière connexion.</p>
    <section class="rounded-2xl p-5 text-white" :style="{ backgroundColor: 'var(--app-color)' }">
      <p class="text-sm opacity-80">{{ wallet.cash_in_hand >= 0 ? 'À verser à la caisse' : 'La caisse vous doit' }}</p>
      <p class="text-4xl font-bold mt-1">{{ money(Math.abs(wallet.cash_in_hand)) }}</p>
      <div v-if="wallet.balance && (wallet.balance.advances || wallet.balance.expenses || wallet.balance.pay_kept)" class="mt-3 space-y-1 text-sm opacity-90">
        <div class="flex justify-between"><span>Encaissé</span><span>{{ money(wallet.balance.collected) }}</span></div>
        <div v-if="wallet.balance.advances" class="flex justify-between"><span>+ Avances de la caisse</span><span>{{ money(wallet.balance.advances) }}</span></div>
        <div v-if="wallet.balance.expenses" class="flex justify-between"><span>− Frais payés pour les courses</span><span>{{ money(wallet.balance.expenses) }}</span></div>
        <div v-if="wallet.balance.pay_kept" class="flex justify-between"><span>− Paie gardée sur l'encaissé</span><span>{{ money(wallet.balance.pay_kept) }}</span></div>
      </div>
    </section>

    <!-- Colis non livrés encore en main : à rapporter au dépôt lors du point -->
    <section v-if="parcelsToReturn.length || parcelsKept.length || onTheRoad.length" class="space-y-2">
      <p v-if="onTheRoad.length" class="rounded-xl bg-sky-50 text-sky-900 text-sm px-4 py-3">
        🛵 {{ onTheRoad.length }} course{{ onTheRoad.length > 1 ? 's' : '' }} en chemin : indiquez « livré » ou « échec » avant de faire votre point à la caisse.
      </p>
      <template v-if="parcelsKept.length">
        <h2 class="font-semibold">🌙 Colis à garder pour leur livraison ({{ parcelsKept.length }})</h2>
        <div class="m-card divide-y">
          <div v-for="p in parcelsKept" :key="p.id" class="p-4 flex justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium truncate">{{ p.recipient_name || p.recipient_phone }}</p>
              <p class="text-xs text-slate-500">{{ p.tracking_code }} · {{ p.merchant }}</p>
            </div>
            <p class="text-xs text-right text-indigo-700">Livraison {{ date(p.kept_for) }}</p>
          </div>
        </div>
        <p class="text-sm text-slate-500 text-center">Gardez-les avec vous : la livraison vous sera confiée le jour prévu.</p>
      </template>
      <template v-if="parcelsToReturn.length">
        <h2 class="font-semibold">📦 Colis à rapporter au dépôt ({{ parcelsToReturn.length }})</h2>
        <div class="m-card divide-y">
          <div v-for="p in parcelsToReturn" :key="p.id" class="p-4 flex justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium truncate">{{ p.recipient_name || p.recipient_phone }}</p>
              <p class="text-xs text-slate-500">{{ p.tracking_code }} · {{ p.merchant }}</p>
            </div>
            <p class="text-xs text-right text-slate-600">{{ p.rescheduled_to ? `Reporté au ${date(p.rescheduled_to)}` : (p.incident || p.status_label) }}</p>
          </div>
        </div>
        <p class="text-sm text-slate-500 text-center">Remettez-les à la caisse ou au dépôt lors de votre point.</p>
      </template>
    </section>

    <section class="grid grid-cols-2 gap-2">
      <div class="m-card p-4">
        <p class="text-sm text-slate-500">Gagné aujourd'hui</p>
        <p class="text-2xl font-bold text-emerald-700">{{ money(wallet.earned_today) }}</p>
      </div>
      <div class="m-card p-4">
        <p class="text-sm text-slate-500">Gains à recevoir</p>
        <p :class="['text-2xl font-bold', signedClass(wallet.unpaid)]">{{ money(wallet.unpaid) }}</p>
      </div>
    </section>

    <div v-for="p in wallet.pending_payslips || []" :key="p.reference" class="m-card p-4 flex justify-between gap-3 bg-emerald-50">
      <div class="min-w-0">
        <p class="font-medium">🧾 Fiche de paie prête</p>
        <p class="text-xs text-slate-600">{{ date(p.period_start) }} → {{ date(p.period_end) }} · {{ p.reference }} · en attente de paiement</p>
      </div>
      <p :class="['font-bold whitespace-nowrap', signedClass(p.amount)]">{{ money(p.amount) }}</p>
    </div>

    <p v-if="wallet.pay" class="m-card p-4 text-sm text-slate-600">
      <template v-if="wallet.pay.base_salary">Salaire de base : <strong>{{ money(wallet.pay.base_salary) }}</strong> · </template>
      <template v-if="wallet.pay.period_label">Paie : {{ wallet.pay.period_label.toLowerCase() }}<template v-if="wallet.pay.next_payslip"> · prochaine fiche le <strong>{{ date(wallet.pay.next_payslip) }}</strong></template></template>
    </p>

    <section v-if="wallet.pay?.objectives?.length" class="m-card p-4 space-y-3">
      <h2 class="font-semibold">🎯 Mes objectifs de la période</h2>
      <div v-for="o in wallet.pay.objectives" :key="o.metric" class="space-y-1">
        <div class="flex justify-between text-sm">
          <span>{{ o.label }}</span>
          <strong>{{ o.value }}{{ o.metric === 'success_rate' ? ' %' : '' }}</strong>
        </div>
        <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
          <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(100, (o.value / o.tiers[o.tiers.length - 1].threshold) * 100)}%` }" />
        </div>
        <p class="text-xs text-slate-600">
          <span v-for="(t, i) in o.tiers" :key="t.threshold">
            <span :class="t.reached ? 'text-emerald-700 font-semibold' : ''">{{ t.reached ? '✓ ' : '' }}{{ t.threshold }}{{ o.metric === 'success_rate' ? ' %' : '' }} → {{ money(t.amount) }}</span><span v-if="i < o.tiers.length - 1"> · </span>
          </span>
        </p>
        <p v-if="o.metric === 'success_rate' && o.min_count && o.attempts < o.min_count" class="text-xs text-slate-500">
          Compte à partir de {{ o.min_count }} livraisons tentées ({{ o.attempts }} pour l'instant).
        </p>
      </div>
      <p class="text-xs text-slate-500">Seul le palier le plus haut atteint est payé, sur la fiche de fin de période.</p>
    </section>

    <details v-if="wallet.earnings?.length" class="m-card">
      <summary class="p-4 font-semibold cursor-pointer">Détail de mes gains à recevoir</summary>
      <div class="divide-y border-t">
        <div v-for="e in wallet.earnings" :key="e.id" class="p-4 flex justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ e.description }}</p>
            <p v-if="e.detail" class="text-xs text-slate-600">{{ e.detail }}</p>
            <p class="text-xs text-slate-500">{{ dateTime(e.created_at) }}</p>
          </div>
          <p :class="['font-semibold whitespace-nowrap', signedClass(e.amount)]">{{ e.amount > 0 ? '+ ' : '' }}{{ money(e.amount) }}</p>
        </div>
      </div>
    </details>

    <section v-if="cashAdvances.length" class="space-y-2">
      <h2 class="font-semibold">Avances reçues de la caisse</h2>
      <div class="m-card divide-y">
        <div v-for="a in cashAdvances" :key="a.id" class="p-4 flex justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ a.reason }}</p>
            <p class="text-xs text-slate-500">{{ dateTime(a.given_at) }}<span v-if="a.tracking_code"> · {{ a.tracking_code }}</span></p>
          </div>
          <p class="font-semibold whitespace-nowrap">+ {{ money(a.amount) }}</p>
        </div>
      </div>
    </section>

    <section v-if="wallet.expenses?.length" class="space-y-2">
      <h2 class="font-semibold">Frais que j'ai payés</h2>
      <div class="m-card divide-y">
        <div v-for="e in wallet.expenses" :key="e.id" class="p-4 flex justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ e.description }}</p>
            <p class="text-xs text-slate-500">{{ e.tracking_code }} · {{ dateTime(e.created_at) }}</p>
          </div>
          <p class="font-semibold whitespace-nowrap text-emerald-700">− {{ money(e.amount) }}</p>
        </div>
      </div>
      <p class="text-sm text-slate-500 text-center">Déduits de ce que vous versez à la caisse.</p>
    </section>

    <section v-if="wallet.collections.length" class="space-y-2">
      <h2 class="font-semibold">Argent encaissé</h2>
      <div class="m-card divide-y">
        <div v-for="c in wallet.collections" :key="c.id" class="p-4 flex justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ c.recipient_name || 'Client' }}</p>
            <p class="text-xs text-slate-500">{{ c.tracking_code }} · {{ c.method_label }} · {{ dateTime(c.collected_at) }}</p>
          </div>
          <p class="font-semibold whitespace-nowrap">{{ money(c.amount_collected) }}</p>
        </div>
      </div>
      <p class="text-sm text-slate-500 text-center">Remettez cet argent à la caisse de l'agence en fin de journée.</p>
    </section>

    <section v-if="wallet.recent_payouts.length" class="space-y-2">
      <h2 class="font-semibold">Mes derniers paiements</h2>
      <div class="m-card divide-y">
        <div v-for="p in wallet.recent_payouts" :key="p.reference" class="p-4 flex justify-between">
          <span class="text-sm">{{ date(p.paid_at) }} <span class="text-xs text-slate-400 font-mono">{{ p.reference }}</span></span>
          <span class="font-semibold">{{ money(p.amount) }}</span>
        </div>
      </div>
    </section>

    <EmptyState v-if="!wallet.parcels?.length && !wallet.collections.length && !wallet.advances?.length && !wallet.expenses?.length && !wallet.recent_payouts.length" icon="💵" title="Rien à verser" text="L'argent encaissé lors de vos livraisons apparaîtra ici." />
  </div>
  <div v-else-if="error" class="text-center py-10 space-y-3">
    <p class="text-slate-600">{{ error }}</p>
    <button class="m-btn m-btn-secondary" @click="load">Réessayer</button>
  </div>
  <p v-else class="text-center text-slate-400 py-10">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { apiErrorMessage } from '../../bootstrap/axios'
import { cachedGet } from '../../composables/useCachedApi'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { date, dateTime, money, signedClass } from '../../utils/format'

const wallet = ref(null)
const stale = ref(false)
const error = ref('')
const parcelsToReturn = computed(() => (wallet.value?.parcels || []).filter((p) => !p.on_the_road && !p.kept_for))
// Mission ramassage « garde » : colis à garder jusqu'à leur jour de livraison
const parcelsKept = computed(() => (wallet.value?.parcels || []).filter((p) => !p.on_the_road && p.kept_for))
const onTheRoad = computed(() => (wallet.value?.parcels || []).filter((p) => p.on_the_road))
// Avances réelles de la caisse (la paie gardée sur l'encaissé figure dans le solde)
const cashAdvances = computed(() => (wallet.value?.advances || []).filter((a) => !a.pay_kept))

async function load() {
  error.value = ''
  try {
    const { data, stale: offline } = await cachedGet('/courier/wallet')
    wallet.value = data.data
    stale.value = offline
  } catch (e) {
    error.value = apiErrorMessage(e, 'Impossible de charger votre caisse.')
  }
}

onMounted(load)
</script>
