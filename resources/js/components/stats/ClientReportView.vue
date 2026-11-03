<template>
  <!-- Rapport du mois d'un client sous contrat : même présentation pour l'équipe (relecture) et le client -->
  <article class="space-y-4 rounded-2xl bg-white p-4 shadow-soft sm:p-6 print:shadow-none" aria-label="Rapport du mois">
    <header class="flex flex-wrap items-start justify-between gap-2 border-b pb-3">
      <div>
        <p class="text-xs uppercase tracking-wide text-slate-500">Rapport d'activité</p>
        <h2 class="text-xl font-bold">{{ d.merchant }} · {{ d.label }}</h2>
      </div>
      <span v-if="status" :class="['rounded-full px-2 py-0.5 text-xs font-semibold', status === 'sent' ? 'bg-emerald-100 text-emerald-900' : 'bg-amber-100 text-amber-900']">{{ status === 'sent' ? 'Envoyé' : 'Brouillon à relire' }}</span>
    </header>

    <p v-if="note" class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ note }}</p>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
      <div class="rounded-xl bg-sky-50 p-3"><p class="text-xs text-sky-800">Courses envoyées</p><p class="text-2xl font-bold text-sky-900">{{ d.created }}</p><p class="text-xs text-sky-800">{{ d.units }} pli(s) et colis</p></div>
      <div class="rounded-xl bg-emerald-50 p-3"><p class="text-xs text-emerald-800">Livrées</p><p class="text-2xl font-bold text-emerald-900">{{ d.delivered }}</p><p class="text-xs text-emerald-800">{{ d.units_delivered }} pli(s) et colis</p></div>
      <div class="rounded-xl bg-violet-50 p-3"><p class="text-xs text-violet-800">Dans le délai</p><p class="text-2xl font-bold text-violet-900">{{ d.on_time_rate === null ? '—' : `${d.on_time_rate.toLocaleString('fr-FR')} %` }}</p><p class="text-xs text-violet-800">{{ d.on_time }} sur {{ d.delivered }}</p></div>
      <div class="rounded-xl bg-amber-50 p-3"><p class="text-xs text-amber-800">Accusés de réception</p><p class="text-2xl font-bold text-amber-900">{{ d.receipts.received }} / {{ d.receipts.asked }}</p><p class="text-xs text-amber-800">photos reçues sur demandées</p></div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
      <section v-if="d.departments.length" class="space-y-1">
        <h3 class="font-semibold">Par département</h3>
        <ul class="divide-y text-sm">
          <li v-for="dep in d.departments" :key="dep.name" class="flex justify-between py-1"><span>{{ dep.name }}</span><span>{{ dep.created }} course(s) · {{ dep.units }} pli(s)</span></li>
        </ul>
      </section>
      <section class="space-y-1">
        <h3 class="font-semibold">Qualité</h3>
        <ul class="divide-y text-sm">
          <li class="flex justify-between py-1"><span>Temps médian (ramassage → remise)</span><span>{{ d.median_minutes === null ? '—' : waitDuration(d.median_minutes) }}</span></li>
          <li class="flex justify-between py-1"><span>Passages sans remise</span><span>{{ d.failures }}</span></li>
          <li v-for="(n, label) in d.failure_reasons" :key="label" class="flex justify-between py-1 pl-3 text-slate-600"><span>{{ label }}</span><span>{{ n }}</span></li>
          <li class="flex justify-between py-1"><span>Annulées</span><span>{{ d.cancelled }}</span></li>
        </ul>
      </section>
      <section v-if="d.lots.length" class="space-y-1 sm:col-span-2">
        <h3 class="font-semibold">Lots</h3>
        <ul class="divide-y text-sm">
          <li v-for="l in d.lots" :key="l.name" class="flex justify-between py-1"><span>{{ l.name }}<template v-if="l.due_on"> · échéance {{ date(l.due_on) }}</template></span><span>{{ l.delivered }} / {{ l.quantity }} livrés</span></li>
        </ul>
      </section>
    </div>
  </article>
</template>

<script setup>
import { computed } from 'vue'
import { date, waitDuration } from '../../utils/format'

const props = defineProps({
  report: { type: Object, required: true },
  // Équipe : statut du rapport (brouillon, envoyé)
  showStatus: { type: Boolean, default: false },
})
const d = computed(() => props.report.data)
const note = computed(() => props.report.note)
const status = computed(() => (props.showStatus ? props.report.status : null))
</script>
