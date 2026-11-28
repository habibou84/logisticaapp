<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Courses livrées" :value="k.delivered" :previous="k.previous_delivered" />
      <KpiTile title="Chiffre d'affaires" :value="k.revenue" :previous="k.previous_revenue" kind="money" />
      <KpiTile title="Marge nette (estimée)" :value="k.margin" :previous="k.previous_margin" kind="money" />
      <KpiTile title="Délai tenu" :value="k.on_time_rate" :previous="k.previous_on_time_rate" kind="percent" />
      <KpiTile title="Revenu par course livrée" :value="k.revenue_per_delivery" :previous="k.previous_revenue_per_delivery" kind="money" />
      <KpiTile title="Part de l'intérieur" :value="k.interior_share" kind="percent" hint="Des envois créés sur la période" />
      <KpiTile title="Délai moyen de paiement" :value="k.payment_days" :hint="k.payment_days === null ? 'Aucune facture payée' : 'jours, de l\'émission au paiement'" />
      <KpiTile title="Factures en retard de plus de 30 j" :value="k.overdue" kind="money" :hint="`${money(k.outstanding)} à encaisser au total`" />
    </div>

    <div class="grid gap-4 lg:grid-cols-5">
      <section class="card p-4 lg:col-span-3">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <div>
            <h2 class="font-semibold">Envois livrés par semaine</h2>
            <p class="text-xs text-slate-600">Plis, colis et chèques ; chaque semaine commence le lundi</p>
          </div>
          <ul class="flex flex-wrap gap-3 text-xs text-slate-600">
            <li v-for="(t, i) in data.types" :key="t.value" class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ background: TYPE_COLORS[i % TYPE_COLORS.length] }" />{{ t.label }}</li>
          </ul>
        </div>
        <StackedColumns v-if="data.weeks.length" class="mt-3" :labels="data.weeks.map((w) => shortDate(w.week))" :series="weekSeries" aria-label="Envois livrés par semaine et par type" />
        <p v-else class="mt-3 text-sm text-slate-600">Aucun envoi livré sur la période.</p>
      </section>
      <section class="card p-4 lg:col-span-2">
        <h2 class="font-semibold">Délai tenu par délai vendu</h2>
        <p class="text-xs text-slate-600">Part des courses livrées dans le délai promis</p>
        <SignedBars class="mt-3" :rows="delays" :color="COLORS.distribution" :format="percent" :at-least="100" label-width="5rem" value-width="4rem" empty="Aucune course livrée avec un délai vendu." />
        <template v-if="data.late_clients.length">
          <h3 class="mt-4 text-sm font-semibold">Clients les plus livrés en retard</h3>
          <ul class="mt-1 divide-y text-sm">
            <li v-for="c in data.late_clients" :key="c.key" class="flex flex-wrap justify-between gap-x-2 py-1"><span class="font-medium">{{ c.label }}</span><span class="text-slate-600">{{ c.late }} en retard sur {{ c.total }} · <strong class="text-slate-900">{{ percent(c.rate) }}</strong> à l'heure</span></li>
          </ul>
        </template>
      </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card overflow-x-auto">
        <div class="p-4 pb-2">
          <h2 class="font-semibold">Contrats au forfait</h2>
          <p class="text-xs text-slate-600">Envois par mois sur envois inclus ; trait : forfait</p>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="p-2 pl-4">Client</th><th class="p-2 text-right">Envois par mois</th><th class="p-2">Consommation</th><th class="p-2 pr-4">Situation</th></tr></thead>
          <tbody class="divide-y">
            <tr v-for="c in data.contracts" :key="c.id">
              <td class="p-2 pl-4 font-medium">{{ c.merchant }}<div class="text-xs font-normal text-slate-600">{{ c.label }}</div></td>
              <td class="p-2 text-right whitespace-nowrap">{{ number(c.used_per_month) }}{{ c.included ? ` / ${number(c.included)}` : '' }}</td>
              <td class="p-2"><Meter v-if="c.usage !== null" :ratio="c.usage / 100" :mark="1" :scale="1.5" :color="USAGE[c.verdict]?.color || COLORS.distribution" /><span v-else class="text-slate-600">—</span></td>
              <td class="p-2 pr-4"><span v-if="c.verdict" :class="['rounded-full border px-2 py-0.5 text-xs font-semibold whitespace-nowrap', USAGE[c.verdict].chip]">{{ USAGE[c.verdict].label }} · {{ c.usage }} %</span><span v-else class="text-slate-600">À l'unité</span></td>
            </tr>
            <tr v-if="!data.contracts.length"><td colspan="4" class="p-4 text-slate-600">Aucun contrat de distribution en cours sur la période.</td></tr>
          </tbody>
        </table>
      </section>
      <section class="card overflow-x-auto">
        <div class="p-4 pb-2">
          <h2 class="font-semibold">Lots ponctuels</h2>
          <p class="text-xs text-slate-600">Lots dont l'échéance tombe dans la période</p>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="p-2 pl-4">Lot</th><th class="p-2 text-right">Livrés</th><th class="p-2">Avancement</th><th class="p-2">Échéance</th><th class="p-2 pr-4">État</th></tr></thead>
          <tbody class="divide-y">
            <tr v-for="l in data.lots" :key="l.id">
              <td class="p-2 pl-4 font-medium">{{ l.name }}<div class="text-xs font-normal text-slate-600">{{ l.merchant }}</div></td>
              <td class="p-2 text-right whitespace-nowrap">{{ number(l.delivered) }} / {{ number(l.quantity) }}</td>
              <td class="p-2"><Meter :ratio="l.quantity ? l.delivered / l.quantity : 0" :color="l.status === 'late' ? TONES.bad : COLORS.distribution" /></td>
              <td class="p-2 whitespace-nowrap">{{ date(l.due_on) }}</td>
              <td class="p-2 pr-4"><span :class="['rounded-full border px-2 py-0.5 text-xs font-semibold whitespace-nowrap', LOTS[l.status].chip]">{{ LOTS[l.status].label }}</span></td>
            </tr>
            <tr v-if="!data.lots.length"><td colspan="5" class="p-4 text-slate-600">Aucun lot à échéance sur la période.</td></tr>
          </tbody>
        </table>
      </section>
    </div>

    <section class="card p-4">
      <h2 class="font-semibold">Factures à encaisser</h2>
      <p class="text-xs text-slate-600">Reste dû aujourd'hui, selon le retard sur l'échéance</p>
      <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
        <div v-for="b in buckets" :key="b.key" class="rounded-lg border p-3">
          <p class="text-xs text-slate-600">{{ b.label }}</p>
          <p class="font-bold" :class="b.late && b.amount ? 'text-amber-800' : ''">{{ money(b.amount) }}</p>
        </div>
      </div>
      <ul v-if="data.payments.clients.length" class="mt-3 divide-y text-sm">
        <li v-for="c in data.payments.clients" :key="c.id" class="flex flex-wrap justify-between gap-2 py-1.5">
          <span class="font-medium">{{ c.name }}</span>
          <span class="text-slate-600">{{ money(c.balance) }} dus<template v-if="c.overdue"> · <strong class="text-amber-800">{{ money(c.overdue) }} en retard</strong></template><template v-if="c.average_days !== null"> · paie en {{ c.average_days }} jours en moyenne</template></span>
        </li>
      </ul>
    </section>

    <DecisionList :decisions="data.decisions" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import KpiTile from '../stats/KpiTile.vue'
import StackedColumns from '../stats/StackedColumns.vue'
import { shortDate } from '../stats/chart'
import { date, money } from '../../utils/format'
import DecisionList from './DecisionList.vue'
import Meter from './Meter.vue'
import SignedBars from './SignedBars.vue'
import { COLORS, TONES, USAGE, number, percent } from './shared'

const props = defineProps({ data: { type: Object, required: true } })
const k = computed(() => props.data.kpis)

// Types d'envoi : nuances de la couleur de la distribution (du plus soutenu au plus clair)
const TYPE_COLORS = ['#eb6834', '#f5a882', '#a8431a']
const LOTS = {
  on_time: { label: 'Terminé à temps', chip: 'border-emerald-200 bg-emerald-50 text-emerald-700' },
  in_progress: { label: 'En cours', chip: 'border-blue-200 bg-blue-50 text-blue-800' },
  late: { label: 'En retard', chip: 'border-red-200 bg-red-50 text-red-700' },
}
const BUCKETS = [['not_due', 'Pas encore échu'], ['0_30', '1 à 30 jours'], ['31_60', '31 à 60 jours'], ['61_90', '61 à 90 jours'], ['over_90', 'Plus de 90 jours']]

const weekSeries = computed(() => props.data.types.map((t, i) => ({
  label: t.label, color: TYPE_COLORS[i % TYPE_COLORS.length], values: props.data.weeks.map((w) => w.counts[t.value] ?? 0),
})))
const delays = computed(() => props.data.delays.map((d) => ({
  label: d.label, value: d.rate ?? 0, color: d.rate !== null && d.rate < 80 ? TONES.bad : d.rate < 90 ? TONES.warn : COLORS.distribution,
  title: `${d.label} : ${d.on_time} à l'heure sur ${d.total}`,
})))
const buckets = computed(() => BUCKETS.map(([key, label], i) => ({ key, label, amount: props.data.payments.buckets[key], late: i >= 2 })))
</script>
