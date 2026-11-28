<template>
  <div class="space-y-4">
    <p class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900">
      Point de départ : <strong>{{ data.label }}</strong>, dernier mois terminé (chiffre d'affaires {{ money(data.revenue) }}, marge nette estimée {{ money(data.margin) }}).
      Déplacez les curseurs pour voir l'effet d'une décision sur un mois comparable. Rien n'est modifié dans l'application.
    </p>

    <div class="grid gap-4 lg:grid-cols-2">
      <section class="card space-y-5 p-4">
        <h2 class="font-semibold">Décisions à essayer</h2>

        <template v-if="data.ecommerce">
          <div>
            <label class="flex justify-between gap-2 text-sm font-medium" for="sim-price"><span>Hausse des frais de livraison e-commerce</span><output>+{{ s.price }} %</output></label>
            <input id="sim-price" v-model.number="s.price" type="range" min="0" max="30" step="1" class="w-full accent-[#c9400c]">
            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-600">
              <label for="sim-elasticity">Colis perdus pour chaque point de hausse (%) :</label>
              <input id="sim-elasticity" v-model.number="s.elasticity" type="number" min="0" max="5" step="0.1" class="input w-20 py-1">
              <span>C'est une hypothèse : ajustez-la selon vos clients.</span>
            </div>
          </div>
          <div>
            <label class="flex justify-between gap-2 text-sm font-medium" for="sim-returns"><span>Retours e-commerce transformés en livraisons</span><output>{{ s.returns }} % des retours</output></label>
            <input id="sim-returns" v-model.number="s.returns" type="range" min="0" max="100" step="5" class="w-full accent-[#c9400c]">
            <p class="text-xs text-slate-600">{{ number(data.ecommerce.returned) }} retour(s) en {{ data.label }}. Un colis livré au lieu de retourné rapporte ses frais de livraison ({{ money(data.ecommerce.revenue_per_delivery) }} en moyenne).</p>
          </div>
          <div>
            <label class="flex justify-between gap-2 text-sm font-medium" for="sim-zones"><span>Supplément par course sur les zones à perte</span><output>{{ money(s.surcharge) }}</output></label>
            <input id="sim-zones" v-model.number="s.surcharge" type="range" min="0" max="2000" step="100" class="w-full accent-[#c9400c]" :disabled="!data.ecommerce.loss_zones.count">
            <p class="text-xs text-slate-600">
              <template v-if="data.ecommerce.loss_zones.count">{{ data.ecommerce.loss_zones.count }} zone(s) à perte ({{ data.ecommerce.loss_zones.names.join(', ') }}) : {{ number(data.ecommerce.loss_zones.delivered) }} course(s), {{ money(data.ecommerce.loss_zones.margin) }} de marge. Le volume est supposé inchangé.</template>
              <template v-else>Aucune zone à perte en {{ data.label }}.</template>
            </p>
          </div>
        </template>

        <div v-if="data.mad">
          <label class="flex justify-between gap-2 text-sm font-medium" for="sim-mad"><span>Livreurs MAD en plus</span><output>+{{ s.couriers }}</output></label>
          <input id="sim-mad" v-model.number="s.couriers" type="range" min="0" max="20" step="1" class="w-full accent-[#c9400c]" :disabled="!data.mad.couriers">
          <p class="text-xs text-slate-600">
            <template v-if="data.mad.couriers">{{ data.mad.couriers }} livreur(s) mis à disposition : chacun rapporte {{ money(data.mad.revenue_per_courier) }} et coûte {{ money(data.mad.cost_per_courier) }} par mois en moyenne.</template>
            <template v-else>Aucun contrat MAD en {{ data.label }} : pas de moyenne pour estimer.</template>
          </p>
        </div>

        <div>
          <label class="text-sm font-medium" for="sim-fixed">Nouveau frais fixe par mois (F)</label>
          <input id="sim-fixed" v-model.number="s.fixed" type="number" min="0" step="10000" class="input mt-1 w-48">
          <p class="text-xs text-slate-600">Un local, un superviseur, un logiciel…</p>
        </div>
      </section>

      <section class="card space-y-3 p-4" aria-live="polite">
        <h2 class="font-semibold">Effet estimé sur un mois</h2>
        <div>
          <p class="text-sm text-slate-600">Marge nette</p>
          <p class="text-3xl font-bold" :class="r.margin < 0 ? 'text-red-600' : ''">{{ money(r.margin) }}</p>
          <p class="text-sm font-semibold" :class="changeClass(r.delta)">{{ signed(r.delta) }} par mois · {{ signed(r.delta * 12) }} par an</p>
        </div>
        <dl class="divide-y text-sm">
          <div class="flex justify-between gap-3 py-2"><dt class="text-slate-600">Chiffre d'affaires</dt><dd class="font-semibold">{{ money(r.revenue) }}</dd></div>
          <div class="flex justify-between gap-3 py-2"><dt class="text-slate-600">Taux de marge nette</dt><dd class="font-semibold">{{ r.revenue > 0 ? `${(Math.round((r.margin / r.revenue) * 1000) / 10).toLocaleString('fr-FR')} %` : '—' }}</dd></div>
          <div v-for="e in r.effects" :key="e.label" class="flex justify-between gap-3 py-2"><dt class="text-slate-600">{{ e.label }}</dt><dd :class="['font-semibold whitespace-nowrap', changeClass(e.value)]">{{ signed(e.value) }}</dd></div>
        </dl>
        <button type="button" class="btn-secondary" @click="reset">Remettre à zéro</button>
        <p class="text-xs text-slate-600">Estimation à partir des moyennes de {{ data.label }}. Les effets sont additionnés sans tenir compte de leurs interactions.</p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { money } from '../../utils/format'
import { changeClass, number } from './shared'

const props = defineProps({ data: { type: Object, required: true } })

const START = { price: 0, elasticity: 0.4, returns: 0, surcharge: 0, couriers: 0, fixed: 0 }
const s = reactive({ ...START })
const reset = () => Object.assign(s, START)
const signed = (v) => `${v > 0 ? '+' : v < 0 ? '−' : ''}${money(Math.abs(Math.round(v)))}`

const r = computed(() => {
  const e = props.data.ecommerce
  const m = props.data.mad
  const effects = []
  let revenue = 0
  let margin = 0
  if (e) {
    // Hausse de prix : chaque colis rapporte plus, une part des colis est perdue (et ses coûts directs avec)
    const lost = Math.min(1, ((Number(s.elasticity) || 0) * s.price) / 100)
    const priceRevenue = e.revenue * ((1 + s.price / 100) * (1 - lost) - 1)
    const priceMargin = priceRevenue + e.delivered * lost * (e.direct_cost_per_delivery || 0)
    // Retours évités : les frais de livraison rentrent ; le coût du passage était déjà payé
    const saved = e.returned * (s.returns / 100) * (e.revenue_per_delivery || 0)
    const zones = e.loss_zones.delivered * s.surcharge
    revenue += priceRevenue + saved + zones
    margin += priceMargin + saved + zones
    effects.push({ label: 'Hausse des frais e-commerce', value: priceMargin }, { label: 'Retours évités', value: saved }, { label: 'Supplément des zones à perte', value: zones })
  }
  if (m) {
    const added = s.couriers * (m.revenue_per_courier || 0)
    const net = s.couriers * ((m.revenue_per_courier || 0) - (m.cost_per_courier || 0))
    revenue += added
    margin += net
    effects.push({ label: 'Livreurs MAD en plus', value: net })
  }
  const fixed = -(Number(s.fixed) || 0)
  margin += fixed
  effects.push({ label: 'Nouveau frais fixe', value: fixed })
  return { revenue: props.data.revenue + revenue, margin: props.data.margin + margin, delta: margin, effects }
})
</script>
