<template>
  <!-- MAD : les livreurs mis à disposition du client (nom, téléphone, état ; pas de position) -->
  <div class="m-card p-4 space-y-2">
    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Mes livreurs attitrés</p>
    <div v-for="c in couriers" :key="c.id" class="flex items-center gap-3">
      <span class="h-10 w-10 shrink-0 rounded-full bg-slate-100 grid place-items-center font-semibold text-slate-700">{{ initials(c.name) }}</span>
      <span class="min-w-0 flex-1">
        <span class="block font-medium truncate">{{ c.name }}</span>
        <span :class="['inline-flex items-center gap-1 text-xs', COLORS[c.status]]"><span class="h-2 w-2 rounded-full bg-current" />{{ c.status_label }}</span>
      </span>
      <a v-if="c.phone" :href="`tel:${c.phone}`" class="tap shrink-0 grid h-10 w-10 place-items-center rounded-full bg-slate-100 text-lg" :aria-label="`Appeler ${c.name}`">📞</a>
    </div>
  </div>
</template>

<script setup>
defineProps({ couriers: { type: Array, required: true } })

const COLORS = { available: 'text-emerald-700', busy: 'text-sky-700', unavailable: 'text-slate-500', absent: 'text-amber-700' }
const initials = (name) => name.split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('')
</script>
