<template>
  <div class="space-y-3">
    <p v-if="!report" class="text-slate-500">Chargement…</p>
    <template v-else>
      <ClientReportView :report="report" />
      <button type="button" class="btn-secondary w-full" @click="print">🖨 Imprimer ou enregistrer en PDF</button>
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '../../bootstrap/axios'
import ClientReportView from '../../components/stats/ClientReportView.vue'

const route = useRoute()
const report = ref(null)
const print = () => window.print()
onMounted(async () => {
  report.value = (await http.get(`/client-reports/${route.params.id}`)).data.data
})
</script>
