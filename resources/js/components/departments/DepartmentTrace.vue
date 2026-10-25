<template>
  <!-- Départements du client : qui a demandé, qui a remis, qui a livré, qui a reçu -->
  <section v-if="order.department || order.to_department" :class="mobile ? 'm-card p-4 space-y-3' : 'card p-4 space-y-3'">
    <h2 class="font-semibold">🏢 Qui a fait quoi</h2>
    <ol class="relative space-y-3 border-l-2 border-slate-200 pl-4">
      <li v-for="(s, i) in steps" :key="i" class="relative">
        <span :class="['absolute -left-[1.4rem] top-1 h-3 w-3 rounded-full ring-2 ring-white', s.done ? 'bg-emerald-500' : 'bg-slate-300']" aria-hidden="true" />
        <p class="text-sm"><span class="text-slate-500">{{ s.label }}</span> <strong>{{ s.who || '—' }}</strong><span v-if="s.role" class="text-slate-600"> · {{ s.role }}</span></p>
        <p v-if="s.where || s.at" class="text-xs text-slate-500">{{ s.where }}<template v-if="s.where && s.at"> · </template>{{ s.at ? dateTime(s.at) : '' }}</p>
        <a v-if="s.proof" :href="s.proof" target="_blank" class="text-xs text-blue-600">{{ s.proofLabel }}</a>
      </li>
    </ol>
    <p v-if="order.department?.cost_center" class="text-xs text-slate-500">Centre de coûts : <span class="font-mono">{{ order.department.cost_center }}</span></p>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import { dateTime } from '../../utils/format'

const props = defineProps({
  order: { type: Object, required: true },
  mobile: Boolean,
})

const o = computed(() => props.order)
const steps = computed(() => [
  {
    label: 'Demandée par',
    who: o.value.requested_by || o.value.department?.manager_name,
    where: o.value.department?.label,
    at: o.value.created_at,
    done: true,
  },
  {
    label: 'Remise au livreur par',
    who: o.value.handed_by_name,
    role: o.value.handed_by_role,
    where: o.value.department?.location,
    at: o.value.picked_up_at,
    done: !!o.value.picked_up_at,
    proof: o.value.handover_signature_url,
    proofLabel: 'Signature',
  },
  {
    label: 'Livrée par',
    who: o.value.delivery_courier?.name || o.value.pickup_courier?.name,
    done: !!o.value.delivered_at,
  },
  {
    label: 'Reçue par',
    who: o.value.received_by_name,
    role: o.value.received_by_role,
    where: o.value.to_department ? `${o.value.to_department.label}${o.value.to_department.location ? ` · ${o.value.to_department.location}` : ''}` : o.value.recipient?.name,
    at: o.value.delivered_at,
    done: !!o.value.delivered_at,
  },
])
</script>
