<template>
  <div :class="['min-h-screen px-4 py-8', theme.page]">
    <div class="mx-auto w-full max-w-md">
      <RouterLink :to="theme.login" class="flex justify-center"><BrandLogo size="md" dark /></RouterLink>

      <div class="mt-6 rounded-3xl bg-white text-slate-900 shadow-2xl p-6 md:p-8 space-y-4">
        <!-- Étape 3 : nouveau mot de passe -->
        <form v-if="pending && verified" class="space-y-4" @submit.prevent="reset">
          <div class="text-center space-y-1">
            <p class="text-3xl" aria-hidden="true">✅</p>
            <h1 class="text-xl font-bold">Numéro vérifié</h1>
            <p class="text-sm text-slate-500">Choisissez votre nouveau mot de passe. Vos autres appareils seront déconnectés.</p>
          </div>
          <p v-if="error" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2" role="alert">{{ error }}</p>
          <label class="block">
            <span class="text-sm font-medium text-slate-700">Nouveau mot de passe (8 caractères minimum)</span>
            <input v-model="password" type="password" autocomplete="new-password" required minlength="8" :class="[inputClass, theme.ring]">
          </label>
          <label class="block">
            <span class="text-sm font-medium text-slate-700">Confirmez le mot de passe</span>
            <input v-model="passwordConfirmation" type="password" autocomplete="new-password" required minlength="8" :class="[inputClass, theme.ring]">
          </label>
          <button type="submit" :disabled="busy" :class="['w-full rounded-xl py-3 font-semibold text-white shadow-sm disabled:opacity-60', theme.button]">
            {{ busy ? 'Enregistrement…' : 'Enregistrer et me connecter' }}
          </button>
        </form>

        <!-- Étape 2 : vérification du numéro -->
        <PhoneVerificationStep v-else-if="pending" :token="pending.token" :initial="pending.data" :tone="tone" @update="remember" @done="onVerified" @restart="restart" />

        <!-- Étape 1 : numéro du compte -->
        <form v-else class="space-y-4" @submit.prevent="start">
          <div class="text-center space-y-1">
            <h1 class="text-xl font-bold">Mot de passe oublié</h1>
            <p class="text-sm text-slate-500">Indiquez le numéro de votre compte : vous le vérifierez par WhatsApp ou SMS, puis vous choisirez un nouveau mot de passe.</p>
          </div>
          <p v-if="error" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2" role="alert">{{ error }}</p>
          <label class="block">
            <span class="text-sm font-medium text-slate-700">Téléphone</span>
            <input v-model.trim="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="07 00 00 00 00" :class="[inputClass, theme.ring]">
          </label>
          <button type="submit" :disabled="busy" :class="['w-full rounded-xl py-3 font-semibold text-white shadow-sm disabled:opacity-60', theme.button]">
            {{ busy ? 'Un instant…' : 'Continuer' }}
          </button>
        </form>

        <p class="text-center text-sm"><RouterLink :to="theme.login" class="text-slate-600 underline">Retour à la connexion</RouterLink></p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BrandLogo from '../../components/auth/BrandLogo.vue'
import PhoneVerificationStep from '../../components/auth/PhoneVerificationStep.vue'
import { loadBranding } from '../../composables/useBranding'
import { clearPending, readPending, savePending } from '../../composables/usePendingVerification'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

const THEMES = {
  merchant: { page: 'bg-gradient-to-br from-emerald-700 via-emerald-600 to-teal-600', ring: 'focus:ring-emerald-500', button: 'bg-emerald-600 hover:bg-emerald-700', login: '/' },
  staff: { page: 'bg-slate-900', ring: 'focus:ring-slate-500', button: 'bg-slate-900 hover:bg-slate-700', login: '/admin/connexion' },
  courier: { page: 'bg-gradient-to-b from-blue-800 to-blue-600', ring: 'focus:ring-blue-500', button: 'bg-blue-700 hover:bg-blue-800', login: '/livreur/connexion' },
}
const inputClass = 'mt-1 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base focus:outline-none focus:ring-2 focus:border-transparent'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

// Espace d'origine (couleurs, retour) : ?espace=merchant|staff|courier
const tone = computed(() => (THEMES[route.query.espace] ? route.query.espace : 'merchant'))
const theme = computed(() => THEMES[tone.value])

const pending = ref(readPending('password_reset'))
const verified = computed(() => pending.value?.data?.status === 'verified')
const phone = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const busy = ref(false)
const error = ref('')

async function start() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post('/password/forgot', { phone: phone.value })
    pending.value = { token: data.token, data: data.data }
    savePending('password_reset', pending.value)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Demande impossible pour le moment.')
  } finally {
    busy.value = false
  }
}

function remember(data) {
  pending.value = { ...pending.value, data }
  savePending('password_reset', pending.value)
}

function onVerified(response) {
  remember(response.data)
}

async function reset() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post('/password/reset', {
      token: pending.value.token,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    clearPending('password_reset')
    auth.startSession(data)
    toasts.success('Mot de passe changé. Vous êtes connecté.')
    router.replace(auth.homeRoute)
  } catch (e) {
    error.value = apiErrorMessage(e, "Le mot de passe n'a pas pu être changé.")
  } finally {
    busy.value = false
  }
}

function restart() {
  clearPending('password_reset')
  pending.value = null
  error.value = ''
}

onMounted(() => loadBranding())
</script>
