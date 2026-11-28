<template>
  <section class="card space-y-3 p-4">
    <div>
      <h2 class="font-semibold">Exporter en Excel</h2>
      <p class="text-sm text-slate-600">Toutes les statistiques de la période choisie ci-dessus, en plusieurs feuilles : vue d'ensemble, livraisons, livreurs, clients, zones et, pour qui voit la caisse, les montants.</p>
    </div>
    <p v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</p>
    <div class="flex flex-wrap items-end gap-2">
      <div v-if="activities.length > 1">
        <label class="label" for="ex-activity">Activités</label>
        <select id="ex-activity" v-model="activity" class="input w-auto">
          <option value="">Toutes</option>
          <option v-for="a in activities" :key="a.value" :value="a.value">{{ a.label }}</option>
        </select>
      </div>
      <button type="button" class="btn-primary" :disabled="exporting" @click="exportXlsx">{{ exporting ? 'Export…' : 'Exporter en Excel' }}</button>
    </div>
    <p class="text-xs text-slate-600">Du {{ date(from) }} au {{ date(to) }}.</p>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { myActivities } from '../../utils/activities'
import { date } from '../../utils/format'

const props = defineProps({ from: { type: String, required: true }, to: { type: String, required: true } })

const auth = useAuthStore()
const activities = computed(() => myActivities(auth.user))
const activity = ref('')
const exporting = ref(false)
const error = ref('')

async function exportXlsx() {
  exporting.value = true
  error.value = ''
  try {
    const { data: blob } = await http.get('/stats/export', { params: { from: props.from, to: props.to, activity: activity.value || undefined }, responseType: 'blob' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `statistiques-${props.from}-au-${props.to}.xlsx`
    link.click()
    URL.revokeObjectURL(link.href)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    exporting.value = false
  }
}
</script>
