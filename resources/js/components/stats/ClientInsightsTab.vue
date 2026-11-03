<template>
  <div class="space-y-4">
    <!-- Concentration 80/20 -->
    <section v-if="data.pareto" class="card space-y-3 p-4">
      <h2 class="font-semibold">Concentration du chiffre d'affaires (80/20)</h2>
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiTile title="Clients qui font 80 % du CA" :value="data.pareto.clients_for_80" :hint="`sur ${data.pareto.clients} client(s)`" />
        <KpiTile title="Part du premier client" kind="percent" :value="data.pareto.top_share" />
        <KpiTile title="Part des 3 premiers" kind="percent" :value="data.pareto.top3_share" />
        <KpiTile title="Chiffre d'affaires" kind="money" :value="data.pareto.total" />
      </div>
      <p v-if="data.pareto.top_share >= 30" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
        ⚠️ Un seul client fait {{ data.pareto.top_share.toLocaleString('fr-FR') }} % du chiffre d'affaires : son départ pèserait lourd.
      </p>
      <ul class="space-y-1.5 text-sm">
        <li v-for="r in data.pareto.rows.slice(0, 15)" :key="r.id">
          <div class="flex justify-between gap-2"><span>{{ r.name }}</span><span class="whitespace-nowrap font-semibold">{{ money(r.revenue) }} · {{ r.share.toLocaleString('fr-FR') }} % <span class="font-normal text-slate-500">(cumul {{ r.cumulative.toLocaleString('fr-FR') }} %)</span></span></div>
          <div class="mt-1 h-2 rounded bg-slate-100"><div :class="['h-2 rounded', r.cumulative <= 80 ? 'bg-blue-600' : 'bg-slate-300']" :style="{ width: `${r.share}%` }" /></div>
        </li>
      </ul>
    </section>

    <!-- Clients en baisse -->
    <section class="card space-y-2 p-4">
      <h2 class="font-semibold">📉 Clients en baisse <span class="text-sm font-normal text-slate-500">· 30 derniers jours contre la moyenne des 3 mois d'avant</span></h2>
      <p v-if="!data.decline.length" class="text-sm text-slate-500">Aucun client en baisse de plus de 20 %.</p>
      <ul v-else class="divide-y text-sm">
        <li v-for="c in data.decline" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
          <span class="min-w-[12rem] flex-1"><b>{{ c.name }}</b><span class="block text-xs text-slate-500">{{ c.contact_name || '' }} {{ c.phone || '' }}</span></span>
          <span>{{ c.recent }} course(s) contre {{ c.average }} par mois · <b class="text-red-700">{{ c.change }} %</b></span>
          <span v-if="c.phone" class="flex gap-2">
            <a :href="`tel:${c.phone}`" class="btn-secondary py-1">📞 Appeler</a>
            <a :href="`https://wa.me/${c.phone.replace(/\D/g, '')}?text=${encodeURIComponent(`Bonjour ${c.contact_name || ''}, nous n'avons pas eu de vos nouvelles ces dernières semaines : avez-vous des courses à nous confier ?`)}`" target="_blank" rel="noopener" class="btn-secondary py-1">💬 WhatsApp</a>
          </span>
        </li>
      </ul>
    </section>

    <!-- Fidélité par mois d'arrivée -->
    <section class="card overflow-x-auto">
      <h2 class="p-4 pb-1 font-semibold">Fidélité des nouveaux clients</h2>
      <p class="px-4 pb-2 text-xs text-slate-500">Part des clients arrivés un mois qui envoient encore des courses 1, 2, 3 et 6 mois après.</p>
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr><th class="p-2">Arrivés en</th><th class="p-2 text-right">Clients</th><th v-for="o in OFFSETS" :key="o" class="p-2 text-right">{{ o }} mois après</th></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="c in data.cohorts.filter((x) => x.size)" :key="c.month">
            <td class="p-2">{{ c.label }}</td>
            <td class="p-2 text-right">{{ c.size }}</td>
            <td v-for="o in OFFSETS" :key="o" class="p-2 text-right" :style="cellStyle(c.retention[o])">{{ c.retention[o] === null ? '' : `${c.retention[o]} %` }}</td>
          </tr>
          <tr v-if="!data.cohorts.some((x) => x.size)"><td colspan="6" class="p-6 text-center text-slate-500">Aucun nouveau client sur les 12 derniers mois.</td></tr>
        </tbody>
      </table>
    </section>

    <!-- Paiements des factures -->
    <template v-if="data.payments">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-7">
        <KpiTile title="Délai moyen de paiement" :text="data.payments.average_days === null ? '—' : `${data.payments.average_days} jours`" />
        <KpiTile title="Reste à payer" kind="money" :value="data.payments.outstanding" />
        <KpiTile title="Pas encore échu" kind="money" :value="data.payments.buckets.not_due" />
        <KpiTile title="1 à 30 jours de retard" kind="money" :value="data.payments.buckets['0_30']" />
        <KpiTile title="31 à 60 jours de retard" kind="money" :value="data.payments.buckets['31_60']" />
        <KpiTile title="61 à 90 jours de retard" kind="money" :value="data.payments.buckets['61_90']" />
        <KpiTile title="Plus de 90 jours de retard" kind="money" :value="data.payments.buckets.over_90" />
      </div>
      <SortableTable title="Paiement des factures par client" :columns="paymentColumns" :rows="data.payments.clients" initial-sort="balance" filename="paiements-clients.csv" empty="Aucune facture émise." />
    </template>
  </div>
</template>

<script setup>
import { money } from '../../utils/format'
import KpiTile from './KpiTile.vue'
import SortableTable from './SortableTable.vue'

defineProps({ data: { type: Object, required: true } })
const OFFSETS = [1, 2, 3, 6]
const cellStyle = (v) => (v === null || v === undefined ? {} : { background: `rgba(42,120,214,${0.1 + (v / 100) * 0.5})` })
const m = (v) => money(v || 0)
const paymentColumns = [
  { key: 'name', label: 'Client', asc: true },
  { key: 'invoices', label: 'Factures', align: 'right' },
  { key: 'average_days', label: 'Délai moyen (jours)', align: 'right', format: (v) => v ?? '—' },
  { key: 'balance', label: 'Reste à payer', align: 'right', format: m },
  { key: 'overdue', label: 'Dont en retard', align: 'right', format: m, class: (r) => (r.overdue ? 'font-semibold text-red-700' : '') },
]
</script>
