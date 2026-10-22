<template>
  <!-- Connexion en un clic d'une application (Jibiat Marchand) au compte de l'e-commerçant -->
  <div class="min-h-screen bg-slate-50 text-slate-900 px-4 py-8" :style="{ '--app-color': spaceColor('marchand') }">
    <div class="mx-auto w-full max-w-md space-y-5">
      <div class="flex justify-center"><BrandLogo size="md" /></div>

      <div class="rounded-3xl bg-white shadow-xl ring-1 ring-slate-200 p-6 space-y-5">
        <p v-if="loading" class="text-center text-slate-500">Chargement…</p>

        <template v-else-if="problem">
          <h1 class="text-xl font-bold text-center">Lien de connexion invalide</h1>
          <p class="text-center text-slate-600">{{ problem }}</p>
        </template>

        <!-- Demande envoyée : l'entreprise doit la valider -->
        <template v-else-if="sent">
          <div class="text-center space-y-3">
            <p class="text-4xl" aria-hidden="true">📨</p>
            <h1 class="text-xl font-bold">Demande envoyée à {{ info.company.name }}</h1>
            <p class="text-slate-600">L'équipe vous contacte au {{ request.phone }}. Une fois votre compte ouvert, revenez dans {{ info.client.name }} et touchez de nouveau « Connecter ».</p>
            <button class="w-full rounded-xl bg-[var(--app-color)] py-3 font-semibold text-white" @click="back('request_pending')">Revenir à {{ info.client.name }}</button>
          </div>
        </template>

        <!-- Connecté : le gérant autorise l'application -->
        <template v-else-if="auth.user">
          <div class="text-center space-y-2">
            <p class="text-4xl" aria-hidden="true">🔗</p>
            <h1 class="text-xl font-bold">Connecter {{ info.client.name }}</h1>
            <p class="text-slate-600">
              <strong>{{ info.client.name }}</strong> demande l'accès à votre compte <strong>{{ auth.user.merchant?.business_name || auth.user.name }}</strong>
              chez <strong>{{ info.company.name }}</strong>.
            </p>
          </div>
          <div v-if="auth.role === 'merchant_owner'" class="space-y-4">
            <div class="rounded-2xl bg-slate-50 p-4">
              <p class="text-sm font-semibold mb-2">L'application pourra :</p>
              <ul class="space-y-1.5 text-sm text-slate-700">
                <li v-for="s in scopeLabels" :key="s" class="flex gap-2"><span class="text-[var(--app-color)]" aria-hidden="true">✓</span>{{ s }}</li>
              </ul>
              <p class="mt-3 text-xs text-slate-500">Vous pourrez couper cet accès à tout moment dans Profil › Intégrations.</p>
            </div>
            <p v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p>
            <button class="w-full rounded-xl bg-[var(--app-color)] py-3 font-semibold text-white disabled:opacity-60" :disabled="busy" @click="allow">{{ busy ? 'Connexion…' : 'Autoriser' }}</button>
            <button class="w-full rounded-xl border border-slate-300 py-3 font-semibold text-slate-700" :disabled="busy" @click="back('access_denied')">Refuser</button>
          </div>
          <p v-else class="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Seul le gérant de la boutique peut connecter une application. Demandez-lui de le faire depuis son compte.</p>
          <button class="block mx-auto text-sm text-slate-500 underline" @click="switchAccount">Ce n'est pas mon compte</button>
        </template>

        <!-- Pas encore connecté : se connecter, ouvrir un compte ou demander à devenir client -->
        <template v-else>
          <div class="text-center space-y-2">
            <h1 class="text-xl font-bold">Livrer avec {{ info.company.name }}</h1>
            <p class="text-slate-600">{{ info.client.name }} va envoyer vos colis à {{ info.company.name }} et suivre chaque livraison pour vous.</p>
          </div>
          <RouterLink :to="{ path: '/', query: { redirect: route.fullPath } }" class="block w-full rounded-xl bg-[var(--app-color)] py-3 text-center font-semibold text-white">J'ai déjà un compte chez {{ info.company.name }}</RouterLink>
          <RouterLink v-if="info.accepts === 'signup'" :to="{ path: '/inscription', query: { redirect: route.fullPath } }" class="block w-full rounded-xl border-2 border-[var(--app-color)] py-3 text-center font-semibold text-[var(--app-color)]">Créer mon compte gratuitement</RouterLink>

          <form v-else class="space-y-3 border-t pt-4" @submit.prevent="sendRequest">
            <p class="font-semibold">Pas encore client ? Demandez l'ouverture de votre compte</p>
            <label class="block text-sm">Nom de la boutique<input v-model.trim="request.business_name" class="input mt-1" required maxlength="255"></label>
            <label class="block text-sm">Votre nom<input v-model.trim="request.contact_name" class="input mt-1" required maxlength="255"></label>
            <label class="block text-sm">Téléphone<input v-model.trim="request.phone" class="input mt-1" type="tel" inputmode="tel" required></label>
            <label v-if="info.zones.length" class="block text-sm">Commune de ramassage
              <select v-model="request.pickup_zone_id" class="input mt-1">
                <option :value="null">— Choisir —</option>
                <option v-for="z in info.zones" :key="z.id" :value="z.id">{{ z.name }}</option>
              </select>
            </label>
            <label class="block text-sm">Adresse de ramassage<input v-model.trim="request.pickup_address" class="input mt-1" maxlength="500"></label>
            <label class="block text-sm">Un mot pour l'équipe (facultatif)<textarea v-model.trim="request.message" class="input mt-1" rows="2" maxlength="500" placeholder="Ex. : environ 30 colis par semaine" /></label>
            <label class="block text-sm">Mot de passe (8 caractères au moins)<input v-model="request.password" class="input mt-1" type="password" autocomplete="new-password" required minlength="8"></label>
            <label class="block text-sm">Confirmez le mot de passe<input v-model="request.password_confirmation" class="input mt-1" type="password" autocomplete="new-password" required minlength="8"></label>
            <p v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p>
            <button class="w-full rounded-xl border-2 border-[var(--app-color)] py-3 font-semibold text-[var(--app-color)] disabled:opacity-60" :disabled="busy">{{ busy ? 'Envoi…' : 'Envoyer ma demande' }}</button>
          </form>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BrandLogo from '../../components/auth/BrandLogo.vue'
import { loadBranding, spaceColor } from '../../composables/useBranding'
import { useAuthStore } from '../../stores/auth'

const SCOPES = {
  'orders:write': 'Créer vos courses et les annuler',
  'orders:read': 'Suivre vos courses et le point du jour',
  'payouts:read': 'Lire vos reversements',
  'stock:read': 'Lire vos produits et votre stock en entrepôt',
  'webhooks:write': 'Être prévenue de chaque changement de vos courses',
}

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const loading = ref(true)
const problem = ref('')
const error = ref('')
const busy = ref(false)
const sent = ref(false)
const info = ref(null)
const q = (key) => (typeof route.query[key] === 'string' ? route.query[key] : '')
const request = reactive({
  business_name: q('business_name'), contact_name: '', phone: q('phone'), pickup_zone_id: null, pickup_address: '', message: '',
  password: '', password_confirmation: '',
})
const scopeLabels = computed(() => (info.value?.scopes ?? []).map((s) => SCOPES[s]).filter(Boolean))

// Retour vers l'application (adresse vérifiée par le serveur), avec le résultat
function back(errorCode) {
  const url = new URL(info.value.redirect_uri)
  url.searchParams.set('error', errorCode)
  url.searchParams.set('state', q('state'))
  window.location.assign(url.toString())
}

async function allow() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post('/connect/authorize', {
      client_id: q('client_id'), redirect_uri: q('redirect_uri'), state: q('state'),
      code_challenge: q('code_challenge'), code_challenge_method: q('code_challenge_method') || 'S256',
    })
    window.location.assign(data.data.redirect)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Connexion impossible pour le moment.')
    busy.value = false
  }
}

async function sendRequest() {
  busy.value = true
  error.value = ''
  try {
    await http.post('/public/connect/requests', { ...request, client_id: q('client_id') }, { baseURL: '/api' })
    sent.value = true
  } catch (e) {
    const errors = e.response?.data?.errors ?? {}
    error.value = Object.values(errors)[0]?.[0] || apiErrorMessage(e, 'Envoi impossible pour le moment.')
  } finally {
    busy.value = false
  }
}

async function switchAccount() {
  await auth.logout()
  router.replace({ path: '/', query: { redirect: route.fullPath } })
}

onMounted(async () => {
  loadBranding()
  if (!q('state') || !q('code_challenge')) {
    problem.value = 'Ouvrez de nouveau la connexion depuis votre application.'
    loading.value = false
    return
  }
  try {
    const { data } = await http.get('/public/connect/client', { baseURL: '/api', params: { client_id: q('client_id'), redirect_uri: q('redirect_uri') } })
    info.value = data.data
    if (auth.isAuthenticated && !auth.user) {
      await auth.fetchUser().catch(() => auth.clear())
    }
  } catch (e) {
    problem.value = apiErrorMessage(e, 'Ce lien ne vient pas d\'une application reconnue.')
  } finally {
    loading.value = false
  }
})
</script>
