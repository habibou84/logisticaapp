<template>
  <div class="space-y-4">
    <section v-if="welcome" class="rounded-2xl p-5 text-white" :style="{ backgroundColor: 'var(--app-color)' }">
      <p class="text-lg font-bold">Dernière étape : où récupérer vos colis ?</p>
      <p class="text-sm opacity-90 mt-1">Nos livreurs trouvent votre boutique du premier coup, et le bon livreur, le plus proche, est envoyé plus vite.</p>
    </section>

    <p v-if="!loaded" class="text-slate-500">Chargement…</p>

    <template v-else>
      <section class="m-card p-4 space-y-3">
        <div>
          <h2 class="font-semibold">📍 Position de votre boutique</h2>
          <p class="text-sm text-slate-600">
            Le plus simple : <strong>depuis votre boutique</strong>, touchez le bouton ci-dessous. Sinon, placez le repère sur la carte.
          </p>
        </div>

        <p v-if="current.pickup_location_source === 'courier' && !changed" class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">
          Position estimée d'après les ramassages de nos livreurs : vérifiez-la et corrigez-la si besoin.
        </p>
        <p v-else-if="current.pickup_location_source && !changed" class="rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
          ✓ Position enregistrée{{ current.pickup_located_at ? ` le ${date(current.pickup_located_at)}` : '' }}{{ current.pickup_location_source === 'staff' ? ' par l\'agence' : '' }}.
        </p>

        <LocationPicker v-model="point" button-class="m-btn-primary" gps-label="📍 Je suis à ma boutique : utiliser ma position" height="h-72" allow-link />

        <div>
          <label class="m-label" for="pl-address">Adresse</label>
          <input id="pl-address" v-model.trim="address" class="m-input" maxlength="255" placeholder="Ex. : Riviera 2, rue des Jardins">
        </div>
        <div>
          <label class="m-label" for="pl-landmark">Repère (facultatif)</label>
          <input id="pl-landmark" v-model.trim="landmark" class="m-input" maxlength="255" placeholder="Ex. : face à la pharmacie des Jardins, portail bleu">
        </div>
        <p v-if="error" class="field-error">{{ error }}</p>
        <button type="button" class="m-btn-primary" :disabled="!point || saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer ce lieu' }}</button>
        <RouterLink v-if="welcome" to="/marchand" class="block text-center text-sm text-slate-500 underline">Plus tard</RouterLink>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import LocationPicker from '../../components/LocationPicker.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date } from '../../utils/format'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

// Après l'inscription : étape facultative, avec « Plus tard »
const welcome = computed(() => !!route.query.bienvenue)
const loaded = ref(false)
const saving = ref(false)
const error = ref('')
const current = ref({})
const point = ref(null)
const address = ref('')
const landmark = ref('')

const changed = computed(() => point.value?.lat !== current.value.pickup_lat || point.value?.lng !== current.value.pickup_lng)

async function save() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await http.put('/merchant/pickup-location', {
      lat: point.value.lat,
      lng: point.value.lng,
      accuracy: point.value.accuracy ?? null,
      pickup_address: address.value || null,
      pickup_landmark: landmark.value || null,
    })
    current.value = data.data
    await auth.fetchUser()
    toasts.success('Lieu de ramassage enregistré. Merci !')
    if (welcome.value) router.replace('/marchand')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  const { data } = await http.get('/merchant/pickup-location')
  current.value = data.data
  if (data.data.pickup_lat != null) point.value = { lat: data.data.pickup_lat, lng: data.data.pickup_lng, accuracy: null }
  address.value = data.data.pickup_address || ''
  landmark.value = data.data.pickup_landmark || ''
  loaded.value = true
})
</script>
