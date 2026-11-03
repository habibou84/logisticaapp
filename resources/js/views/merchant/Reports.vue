<template>
  <div class="space-y-3">
    <p v-if="loading" class="text-slate-500">Chargement…</p>
    <EmptyState v-else-if="!reports.length" color="facture" title="Aucun rapport pour le moment" text="Chaque mois, votre rapport d'activité (courses, délais, accusés de réception) arrive ici." />
    <RouterLink v-for="r in reports" :key="r.id" :to="`/marchand/rapports/${r.id}`" class="m-card flex items-center justify-between gap-3 p-4">
      <span>
        <span class="block font-semibold">{{ r.label }}</span>
        <span class="text-sm text-slate-500">{{ r.summary.delivered }} livrée(s){{ r.summary.on_time_rate === null ? '' : ` · ${r.summary.on_time_rate} % dans le délai` }}</span>
      </span>
      <span class="text-slate-400" aria-hidden="true">›</span>
    </RouterLink>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http from '../../bootstrap/axios'
import EmptyState from '../../components/mobile/EmptyState.vue'

const reports = ref([])
const loading = ref(true)
onMounted(async () => {
  try {
    reports.value = (await http.get('/client-reports')).data.data
  } finally {
    loading.value = false
  }
})
</script>
