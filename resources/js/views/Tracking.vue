<template>
  <div class="min-h-screen bg-slate-100 px-4 py-8">
    <div class="max-w-md mx-auto space-y-4">
      <div class="text-center">
        <div class="text-4xl">🚚</div>
        <h1 class="text-xl font-bold mt-2">Suivi de colis</h1>
      </div>

      <form class="card p-4 flex gap-2" @submit.prevent="search">
        <input v-model="code" class="input font-mono uppercase" placeholder="LV-XXXX-XXXX" aria-label="Code de suivi" required>
        <button class="btn-primary">Suivre</button>
      </form>

      <p v-if="error" class="text-center text-red-600">{{ error }}</p>

      <div v-if="tracking" class="card p-5 space-y-4">
        <div class="text-center space-y-2">
          <p class="font-mono text-sm text-gray-500">{{ tracking.tracking_code }}</p>
          <StatusBadge :status="tracking.status" :label="tracking.status_label" class="text-base px-3 py-1" />
          <p v-if="tracking.merchant_name" class="text-sm">Envoyé par <strong>{{ tracking.merchant_name }}</strong></p>
          <p v-if="tracking.delivery_zone" class="text-sm text-gray-600">Livraison à {{ tracking.delivery_zone }}</p>
          <p v-if="tracking.courier_first_name" class="text-sm">Votre livreur : <strong>{{ tracking.courier_first_name }}</strong></p>
          <p v-if="tracking.scheduled_date && !['delivered', 'returned', 'cancelled'].includes(tracking.status)" class="text-sm">
            Livraison prévue le <strong>{{ date(tracking.scheduled_date) }}</strong>
          </p>
        </div>

        <ol class="border-l-2 border-slate-200 ml-2 space-y-3">
          <li v-for="(step, i) in tracking.timeline" :key="i" class="ml-4 relative">
            <span class="absolute -left-[23px] top-1.5 h-3 w-3 rounded-full bg-slate-400" />
            <p class="text-sm font-medium">{{ step.label }}<span v-if="step.rescheduled_to"> au {{ date(step.rescheduled_to) }}</span></p>
            <p class="text-xs text-gray-500">{{ dateTime(step.at) }}</p>
          </li>
        </ol>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import StatusBadge from '../components/StatusBadge.vue'
import { date, dateTime } from '../utils/format'
import { siteOrigin } from '../native'

const route = useRoute()
const router = useRouter()
const code = ref(route.params.code || '')
const tracking = ref(null)
const error = ref('')

async function load() {
  if (!code.value) return
  error.value = ''
  tracking.value = null
  try {
    const { data } = await axios.get(`${siteOrigin()}/api/v1/tracking/${encodeURIComponent(code.value.trim().toUpperCase())}`, { headers: { Accept: 'application/json' } })
    tracking.value = data.data
  } catch (e) {
    error.value = e.response?.status === 404 ? 'Aucun colis trouvé avec ce code.' : 'Service momentanément indisponible.'
  }
}

function search() {
  router.replace(`/suivi/${code.value.trim().toUpperCase()}`)
  load()
}

onMounted(load)
</script>
