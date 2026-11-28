<template>
  <!-- Délais de livraison : réussite au premier passage, délais habituels, issues des courses -->
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiTile title="Réussies au 1er passage" kind="percent" :value="data.first_attempt_rate" hint="Parmi les courses livrées" />
      <KpiTile title="Passages par course livrée" :value="data.average_attempts" hint="1 = toujours du premier coup" />
      <KpiTile title="Livrées le jour même" kind="percent" :value="data.same_day_rate" hint="Hors courses programmées plus tard" />
      <KpiTile title="Échecs de livraison" :value="data.failed_attempts" hint="Passages ratés chez le destinataire" />
    </div>
    <section class="card space-y-3 p-4">
      <h2 class="font-semibold">Délais habituels <span class="text-sm font-normal text-slate-600">· médiane des courses livrées</span></h2>
      <dl class="grid grid-cols-3 gap-3 text-center">
        <div class="rounded bg-slate-50 p-3"><dt class="text-xs text-slate-600">Création → ramassage</dt><dd class="text-lg font-bold">{{ duration(data.delays.to_pickup) }}</dd></div>
        <div class="rounded bg-slate-50 p-3"><dt class="text-xs text-slate-600">Ramassage → livraison</dt><dd class="text-lg font-bold">{{ duration(data.delays.pickup_to_delivery) }}</dd></div>
        <div class="rounded bg-slate-50 p-3"><dt class="text-xs text-slate-600">Délai total</dt><dd class="text-lg font-bold">{{ duration(data.delays.total) }}</dd></div>
      </dl>
      <p class="text-xs text-slate-600">La médiane : la moitié des courses va plus vite, l'autre moitié plus lentement. Elle n'est pas faussée par quelques courses reportées.</p>

      <h3 class="pt-2 text-sm font-semibold">Issues sur la période</h3>
      <ul class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
        <li v-for="o in OUTCOMES" :key="o.key" class="flex items-center justify-between rounded border px-3 py-2">
          <span class="inline-flex items-center gap-1 text-slate-600"><Icone :nom="o.icon" taille="petite" />{{ o.label }}</span><strong>{{ data.outcomes[o.key] }}</strong>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { waitDuration } from '../../utils/format'
import Icone from '../ui/Icone.vue'
import KpiTile from './KpiTile.vue'

defineProps({ data: { type: Object, required: true } })

const OUTCOMES = [
  { key: 'delivered', label: 'Livrées', icon: 'livre' },
  { key: 'rescheduled', label: 'Reportées', icon: 'date' },
  { key: 'returned', label: 'Retournées', icon: 'retour' },
  { key: 'cancelled', label: 'Annulées', icon: 'annulation' },
  { key: 'lost', label: 'Perdues', icon: 'alerte' },
]
const duration = (minutes) => (minutes === null || minutes === undefined ? '—' : waitDuration(minutes))
</script>
