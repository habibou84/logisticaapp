<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Revenus" kind="money" :value="data.revenue.total" />
      <KpiTile title="Dépenses" kind="money" :value="data.costs.total" />
      <KpiTile title="Marge" kind="money" :value="data.margin" :hint="data.margin_rate === null ? '' : `${data.margin_rate.toLocaleString('fr-FR')} % des revenus`" />
      <KpiTile title="Marge par course livrée" kind="money" :value="data.margin_per_order" :hint="`${data.delivered} courses livrées`" />
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card p-4 space-y-4">
        <h2 class="font-semibold">Compte de résultat de la période</h2>
        <div class="space-y-2">
          <h3 class="text-sm font-semibold text-slate-600">Revenus · {{ money(data.revenue.total) }}</h3>
          <AmountBars :items="revenueItems" :max="scale" :color="SERIES[0]" />
        </div>
        <div class="space-y-2">
          <h3 class="text-sm font-semibold text-slate-600">Dépenses · {{ money(data.costs.total) }}</h3>
          <AmountBars :items="costItems" :max="scale" :color="SERIES[1]" />
        </div>
        <p :class="['rounded-lg px-3 py-2 font-semibold', data.margin >= 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700']">
          {{ data.margin >= 0 ? 'Bénéfice' : 'Perte' }} : {{ money(Math.abs(data.margin)) }}
        </p>
        <p v-if="data.rebilled" class="text-xs text-slate-500">
          Frais avancés puis refacturés aux marchands (gare, transport…) : {{ money(data.rebilled) }}. Ni revenu ni dépense.
        </p>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Argent en circulation <span class="text-sm font-normal text-slate-500">· aujourd'hui</span></h2>
        <dl class="divide-y text-sm">
          <div class="flex justify-between py-2"><dt class="text-slate-600">Chez les livreurs (à verser à la caisse)</dt><dd class="font-semibold">{{ money(data.circulation.with_couriers) }}</dd></div>
          <div class="flex justify-between py-2"><dt class="text-slate-600">À reverser aux e-commerçants</dt><dd class="font-semibold">{{ money(data.circulation.to_merchants) }}</dd></div>
          <div class="flex justify-between py-2 pl-4"><dt class="text-slate-500">dont déjà en caisse, reversable</dt><dd>{{ money(data.circulation.to_merchants_available) }}</dd></div>
          <div class="flex justify-between py-2"><dt class="text-slate-600">Dû par des e-commerçants</dt><dd :class="['font-semibold', data.circulation.owed_by_merchants ? 'text-red-600' : '']">{{ money(data.circulation.owed_by_merchants) }}</dd></div>
        </dl>
        <RouterLink to="/admin/caisse" class="inline-block text-sm text-blue-600">Ouvrir la caisse →</RouterLink>
      </section>
    </div>

    <section v-if="data.months.length > 1" class="card overflow-x-auto">
      <h2 class="p-4 pb-2 font-semibold">Mois par mois</h2>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="p-2">Mois</th><th class="p-2 text-right">Revenus</th><th class="p-2 text-right">Dépenses</th><th class="p-2 text-right">Marge</th></tr></thead>
        <tbody class="divide-y">
          <tr v-for="m in data.months" :key="m.month">
            <td class="p-2 capitalize">{{ monthLabel(m.month) }}</td>
            <td class="p-2 text-right">{{ money(m.revenue) }}</td>
            <td class="p-2 text-right">{{ money(m.costs) }}</td>
            <td :class="['p-2 text-right font-semibold', m.margin < 0 ? 'text-red-600' : 'text-emerald-700']">{{ money(m.margin) }}</td>
          </tr>
        </tbody>
      </table>
    </section>
    <p class="text-xs text-slate-500">
      Revenus : frais de livraison, de retour et de stockage facturés sur la période. Dépenses : paie des livreurs enregistrée (retenues déduites),
      frais de course à la charge de l'entreprise, indemnités pour colis perdus. Les montants encaissés pour les marchands ne sont pas des revenus.
    </p>
  </div>
</template>

<script setup>
import { computed, defineComponent, h } from 'vue'
import { money } from '../../utils/format'
import KpiTile from './KpiTile.vue'
import { SERIES } from './chart'

const props = defineProps({
  data: { type: Object, required: true },
})

// Barres de montants sur une même échelle (revenus et dépenses comparables)
const AmountBars = defineComponent({
  props: { items: Array, max: Number, color: String },
  setup(p) {
    return () => h('ul', { class: 'space-y-2' }, p.items.map((i) => h('li', { class: 'text-sm' }, [
      h('div', { class: 'flex justify-between gap-2' }, [h('span', { class: 'text-slate-700' }, i.label), h('span', { class: 'font-semibold whitespace-nowrap' }, money(i.amount))]),
      h('div', { class: 'mt-1 h-2 rounded bg-slate-100' }, [h('div', { class: 'h-2 rounded', style: { width: `${p.max ? (Math.max(0, i.amount) / p.max) * 100 : 0}%`, background: p.color } })]),
    ])))
  },
})

const revenueItems = computed(() => [
  { label: 'Frais de livraison', amount: props.data.revenue.delivery },
  { label: 'Frais de retour', amount: props.data.revenue.returns },
  { label: 'Stockage', amount: props.data.revenue.storage },
])
const costItems = computed(() => [
  { label: 'Paie des livreurs', amount: props.data.costs.courier_pay },
  { label: 'Frais de course (entreprise)', amount: props.data.costs.expenses },
  { label: 'Indemnités colis perdus', amount: props.data.costs.lost_compensations },
])
const scale = computed(() => Math.max(1, ...revenueItems.value.map((i) => i.amount), ...costItems.value.map((i) => i.amount)))

function monthLabel(ym) {
  return new Date(`${ym}-01T00:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
}
</script>
