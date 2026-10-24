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

      <!-- Le destinataire note la livraison (si l'entreprise le propose) -->
      <form v-if="tracking?.rating_open && !rated" class="card p-5 space-y-3 text-center" @submit.prevent="rate">
        <p class="font-semibold">Comment s'est passée la livraison ?</p>
        <StarRating v-model="review.stars" size="h-9 w-9" gap="gap-1.5" label="Votre note" />
        <textarea v-model="review.comment" rows="2" maxlength="500" class="input" placeholder="Un mot sur la livraison (facultatif)" />
        <input v-model="review.delivery_code" class="input text-center font-mono tracking-widest" inputmode="numeric" maxlength="10" placeholder="Code de livraison reçu" aria-label="Code de livraison" required>
        <p class="text-xs text-gray-500">Le code reçu par WhatsApp ou SMS confirme que c'est bien vous.</p>
        <p v-if="reviewError" class="text-sm text-red-600">{{ reviewError }}</p>
        <button class="btn-primary w-full" :disabled="!review.stars || sending">Envoyer mon avis</button>
      </form>
      <p v-if="rated" class="card p-4 text-center text-emerald-700 font-medium">Merci pour votre avis !</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import StatusBadge from '../components/StatusBadge.vue'
import StarRating from '../components/StarRating.vue'
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

// Note du destinataire
const review = ref({ stars: null, comment: '', delivery_code: '' })
const rated = ref(false)
const sending = ref(false)
const reviewError = ref('')
async function rate() {
  sending.value = true
  reviewError.value = ''
  try {
    await axios.post(`${siteOrigin()}/api/v1/tracking/${encodeURIComponent(tracking.value.tracking_code)}/rating`, review.value, { headers: { Accept: 'application/json' } })
    rated.value = true
  } catch (e) {
    const errors = e.response?.data?.errors
    reviewError.value = errors ? Object.values(errors)[0][0] : (e.response?.data?.message || 'Service momentanément indisponible.')
  } finally {
    sending.value = false
  }
}

function search() {
  router.replace(`/suivi/${code.value.trim().toUpperCase()}`)
  load()
}

onMounted(load)
</script>
