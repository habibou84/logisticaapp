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
          <p class="font-mono text-sm text-slate-600">{{ tracking.tracking_code }}</p>
          <StatusBadge :status="tracking.status" :label="tracking.status_label" class="text-base px-3 py-1" />
          <p v-if="tracking.merchant_name" class="text-sm">Envoyé par <strong>{{ tracking.merchant_name }}</strong></p>
          <p v-if="tracking.delivery_zone" class="text-sm text-slate-600">Livraison à {{ tracking.delivery_zone }}</p>
          <p v-if="tracking.courier_first_name" class="text-sm">Votre livreur : <strong>{{ tracking.courier_first_name }}</strong></p>
          <p v-if="tracking.scheduled_date && !['delivered', 'returned', 'cancelled'].includes(tracking.status)" class="text-sm">
            Livraison prévue le <strong>{{ date(tracking.scheduled_date) }}</strong>
          </p>
        </div>

        <!-- Le destinataire envoie sa position GPS pour guider le livreur -->
        <div v-if="tracking.can_share_location" class="rounded-xl bg-emerald-50 p-4 ring-1 ring-emerald-200 space-y-2.5">
          <template v-if="!tracking.location_shared">
            <p class="font-semibold text-emerald-900">📍 Aidez le livreur à vous trouver</p>
            <p class="text-sm text-emerald-900/90">Placez-vous à l'endroit où vous voulez être livré, puis touchez le bouton.</p>
          </template>
          <p v-else class="text-sm font-semibold text-emerald-900">✅ Votre position est enregistrée : le livreur la voit sur sa carte.</p>
          <button
            type="button"
            :class="[tracking.location_shared ? 'btn-secondary' : 'btn-success', 'w-full']"
            :disabled="locating"
            @click="shareLocation"
          >{{ locating ? 'Localisation…' : tracking.location_shared ? 'Mettre à jour ma position' : 'Envoyer ma position' }}</button>
          <p v-if="locationNote" class="text-sm text-emerald-900" role="status">{{ locationNote }}</p>
          <div v-if="locationError" class="rounded-lg bg-rose-50 px-3 py-2.5 text-sm text-rose-900 ring-1 ring-rose-200" role="alert">
            <p class="font-semibold">{{ locationError }}</p>
            <ol v-if="locationSteps.length" class="mt-1 list-decimal space-y-0.5 pl-5">
              <li v-for="st in locationSteps" :key="st">{{ st }}</li>
            </ol>
          </div>
        </div>

        <ol class="border-l-2 border-slate-200 ml-2 space-y-3">
          <li v-for="(step, i) in tracking.timeline" :key="i" class="ml-4 relative">
            <span class="absolute -left-[23px] top-1.5 h-3 w-3 rounded-full bg-slate-400" />
            <p class="text-sm font-medium">{{ step.label }}<span v-if="step.rescheduled_to"> au {{ date(step.rescheduled_to) }}</span></p>
            <p class="text-xs text-slate-600">{{ dateTime(step.at) }}</p>
          </li>
        </ol>
      </div>

      <!-- Le destinataire note la livraison (si l'entreprise le propose) -->
      <form v-if="tracking?.rating_open && !rated" class="card p-5 space-y-3 text-center" @submit.prevent="rate">
        <p class="font-semibold">Comment s'est passée la livraison ?</p>
        <StarRating v-model="review.stars" size="h-9 w-9" gap="gap-1.5" label="Votre note" />
        <textarea v-model="review.comment" rows="2" maxlength="500" class="input" placeholder="Un mot sur la livraison (facultatif)" />
        <input v-model="review.delivery_code" class="input text-center font-mono tracking-widest" inputmode="numeric" maxlength="10" placeholder="Code de livraison reçu" aria-label="Code de livraison" required>
        <p class="text-xs text-slate-600">Le code reçu par WhatsApp ou SMS confirme que c'est bien vous.</p>
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

// Position du destinataire : GPS du téléphone, envoyée au livreur
const locating = ref(false)
const locationNote = ref('')
const locationError = ref('')
const locationSteps = ref([])

function gpsPosition() {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) return reject({ code: 0 })
    navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 })
  })
}

async function shareLocation() {
  locating.value = true
  locationNote.value = ''
  locationError.value = ''
  locationSteps.value = []
  try {
    const p = await gpsPosition()
    await axios.post(
      `${siteOrigin()}/api/v1/tracking/${encodeURIComponent(tracking.value.tracking_code)}/location`,
      { lat: Number(p.coords.latitude.toFixed(7)), lng: Number(p.coords.longitude.toFixed(7)) },
      { headers: { Accept: 'application/json' } },
    )
    tracking.value.location_shared = true
    const accuracy = Math.round(p.coords.accuracy || 0)
    locationNote.value = accuracy > 100
      ? `Position envoyée, mais approximative (à ${accuracy} m près). Si vous pouvez, sortez à découvert et touchez « Mettre à jour ma position ».`
      : 'Merci ! Votre position a bien été envoyée au livreur.'
  } catch (e) {
    if (e?.response) {
      locationError.value = e.response.data?.message || 'Envoi impossible pour le moment. Réessayez dans un instant.'
    } else if (e?.code === 1) {
      locationError.value = 'Vous avez refusé l\'accès à votre position.'
      locationSteps.value = [
        'Touchez le cadenas ou « ⓘ » à côté de l\'adresse de la page.',
        'Autorisez « Position » (ou « Localisation »).',
        'Revenez ici et touchez à nouveau le bouton.',
      ]
    } else if (e?.code === 2) {
      locationError.value = 'Position introuvable : activez la localisation (GPS) du téléphone, puis réessayez.'
    } else if (e?.code === 3) {
      locationError.value = 'La localisation prend trop de temps. Vérifiez que le GPS est activé et réessayez.'
    } else {
      locationError.value = 'Ce navigateur ne permet pas d\'envoyer votre position. Ouvrez le lien dans Chrome ou Safari.'
    }
  } finally {
    locating.value = false
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
