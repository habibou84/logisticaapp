<template>
  <form class="space-y-4" :style="{ '--app-color': color }" @submit.prevent="submit">
    <div v-if="error" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2 space-y-2" role="alert">
      <p>{{ error }}</p>
      <!-- Compte d'un autre espace : lien vers sa page de connexion (sauf dans une application limitée à un espace) -->
      <RouterLink v-if="otherSpace" :to="otherSpace.to" class="inline-block rounded-lg bg-white px-3 py-1.5 font-semibold text-red-800 ring-1 ring-red-200">
        {{ otherSpace.label }} →
      </RouterLink>
    </div>

    <label class="block">
      <span class="text-sm font-medium text-slate-700">Téléphone ou e-mail</span>
      <input
        v-model.trim="login"
        type="text"
        autocomplete="username"
        inputmode="email"
        placeholder="07 00 00 00 00"
        required
        :class="['mt-1 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base focus:outline-none focus:ring-2 focus:border-transparent', ring]"
      >
    </label>

    <label class="block">
      <span class="text-sm font-medium text-slate-700">Mot de passe</span>
      <span class="relative mt-1 block">
        <input
          v-model="password"
          :type="showPassword ? 'text' : 'password'"
          autocomplete="current-password"
          required
          :class="['w-full rounded-xl border border-slate-300 bg-white px-4 py-3 pr-12 text-base focus:outline-none focus:ring-2 focus:border-transparent', ring]"
        >
        <button type="button" class="absolute inset-y-0 right-0 px-3 text-slate-500" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPassword = !showPassword">
          {{ showPassword ? '🙈' : '👁️' }}
        </button>
      </span>
    </label>

    <p class="text-right -mt-2">
      <RouterLink :to="{ path: '/mot-de-passe-oublie', query: { espace: tone } }" class="text-sm text-slate-600 hover:underline">Mot de passe oublié ?</RouterLink>
    </p>

    <button type="submit" :disabled="loading" :class="['w-full rounded-xl py-3 font-semibold text-white shadow-sm disabled:opacity-60 transition', button]">
      {{ loading ? 'Connexion…' : 'Se connecter' }}
    </button>

    <!-- Préproduction seulement (le serveur ne les donne pas ailleurs) : comptes de test en un toucher -->
    <div v-if="demoAccounts.length" class="rounded-xl border border-dashed border-amber-300 bg-amber-50 p-3 text-sm">
      <p class="font-semibold text-amber-900">🧪 Comptes de test (préproduction)</p>
      <p class="text-xs text-amber-800 mb-2">Touchez un compte pour remplir le formulaire. Mot de passe : <code>{{ demoAccounts[0].password }}</code></p>
      <ul class="space-y-1">
        <li v-for="a in demoAccounts" :key="a.login">
          <button type="button" class="w-full flex items-center justify-between gap-2 rounded-lg bg-white px-3 py-2 text-left ring-1 ring-amber-200 hover:ring-amber-400" @click="useDemo(a)">
            <span class="min-w-0"><span class="block truncate font-medium text-slate-800">{{ a.name }}</span><span class="text-xs text-slate-500">{{ a.role_label }}</span></span>
            <span class="shrink-0 font-mono text-xs text-slate-600">{{ a.login }}</span>
          </button>
        </li>
      </ul>
    </div>
  </form>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { LOGIN_PAGES, SPACE_FROM_SERVER } from '../../bootstrap/session'
import { appLock } from '../../native'
import { spaceColor } from '../../composables/useBranding'

const props = defineProps({
  // merchant (vert), staff (ardoise), courier (bleu)
  tone: { type: String, default: 'merchant' },
})

// Couleur de l'espace choisie par l'entreprise (Paramètres › Apparence)
const TONE_SPACES = { merchant: 'marchand', staff: 'admin', courier: 'livreur' }
const color = computed(() => spaceColor(TONE_SPACES[props.tone]))
const ring = 'focus:ring-[var(--app-color)]'
const button = 'bg-[var(--app-color)] hover:brightness-110'

const login = ref('')
const password = ref('')
const showPassword = ref(false)
const error = ref('')
const otherSpace = ref(null)
const loading = ref(false)
const SPACE_LABELS = { marchand: 'Aller à l\'espace e-commerçant', livreur: 'Aller à l\'espace livreur', admin: 'Aller à l\'espace équipe' }

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

// Comptes de test de l'entreprise pour cet espace (préproduction ; ailleurs la requête échoue sans bruit)
const demoAccounts = ref([])
onMounted(async () => {
  try {
    demoAccounts.value = (await http.get('/demo-accounts')).data.data.filter((a) => a.space === props.tone)
  } catch {
    demoAccounts.value = []
  }
})
function useDemo(account) {
  login.value = account.login
  password.value = account.password
}

async function submit() {
  error.value = ''
  otherSpace.value = null
  loading.value = true
  try {
    await auth.login({ login: login.value, password: password.value })
    // Chacun arrive dans son espace, quelle que soit la page de connexion utilisée
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith(auth.homeRoute) ? route.query.redirect : null
    router.replace(redirect || auth.homeRoute)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Connexion impossible. Vérifiez votre réseau.')
    const space = SPACE_FROM_SERVER[e.response?.data?.space]
    if (space && !appLock()) otherSpace.value = { to: LOGIN_PAGES[space], label: SPACE_LABELS[space] }
  } finally {
    loading.value = false
  }
}
</script>
