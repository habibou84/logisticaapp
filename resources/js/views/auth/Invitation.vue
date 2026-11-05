<template>
  <!-- Lien de connexion envoyé par l'administrateur (WhatsApp) : la personne ouvre sa session, mot de passe facultatif -->
  <div class="min-h-screen bg-gradient-to-b from-slate-900 to-slate-700 px-4 py-8">
    <div class="mx-auto w-full max-w-md">
      <div class="flex justify-center"><BrandLogo size="md" dark /></div>

      <div class="mt-6 space-y-4 rounded-3xl bg-white p-6 text-slate-900 shadow-2xl md:p-8">
        <p v-if="loading" class="text-center text-sm text-slate-500">Chargement…</p>

        <template v-else-if="invite">
          <div class="space-y-1 text-center">
            <p class="text-3xl" aria-hidden="true">👋</p>
            <h1 class="text-xl font-bold">Bienvenue {{ invite.name }}</h1>
            <p class="text-sm text-slate-600">
              Votre compte <strong>{{ invite.role_label || 'équipe' }}</strong> chez <strong>{{ invite.company }}</strong> est prêt.
            </p>
            <p class="text-xs text-slate-500">Identifiant : {{ invite.phone }}</p>
          </div>

          <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

          <form class="space-y-4" @submit.prevent="accept">
            <label class="flex items-start gap-2 text-sm">
              <input v-model="withPassword" type="checkbox" class="mt-1">
              <span>Choisir un mot de passe pour me reconnecter plus tard sans lien <span class="text-slate-500">(conseillé)</span></span>
            </label>
            <template v-if="withPassword">
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Mot de passe (8 caractères minimum)</span>
                <input v-model="password" type="password" autocomplete="new-password" required minlength="8" class="input mt-1">
              </label>
              <label class="block">
                <span class="text-sm font-medium text-slate-700">Confirmez le mot de passe</span>
                <input v-model="passwordConfirmation" type="password" autocomplete="new-password" required minlength="8" class="input mt-1">
              </label>
            </template>
            <p v-else class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
              Sans mot de passe, il faudra demander un nouveau lien à votre administrateur après une déconnexion.
            </p>
            <button type="submit" class="btn-primary w-full py-3" :disabled="busy">{{ busy ? 'Connexion…' : 'Ouvrir mon espace' }}</button>
          </form>
          <p class="text-center text-xs text-slate-500">Ce lien ne sert qu'une fois. Il expire le {{ expires }}.</p>
        </template>

        <div v-else class="space-y-3 text-center">
          <p class="text-3xl" aria-hidden="true">⌛</p>
          <h1 class="text-lg font-bold">Lien non valable</h1>
          <p class="text-sm text-slate-600">{{ error }}</p>
          <RouterLink to="/admin/connexion" class="btn-secondary inline-block">Page de connexion</RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BrandLogo from '../../components/auth/BrandLogo.vue'
import { useAuthStore } from '../../stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const token = route.params.token

const invite = ref(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const withPassword = ref(true)
const password = ref('')
const passwordConfirmation = ref('')
const expires = computed(() => (invite.value ? new Date(invite.value.expires_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' }) : ''))

onMounted(async () => {
  try {
    invite.value = (await http.get(`/invitations/${token}`)).data.data
  } catch (e) {
    error.value = apiErrorMessage(e, 'Ce lien n\'est plus valable. Demandez-en un nouveau à votre administrateur.')
  } finally {
    loading.value = false
  }
})

async function accept() {
  error.value = ''
  if (withPassword.value && password.value !== passwordConfirmation.value) {
    error.value = 'Les deux mots de passe ne sont pas identiques.'
    return
  }
  busy.value = true
  try {
    const { data } = await http.post(`/invitations/${token}/accept`, {
      ...(withPassword.value ? { password: password.value, password_confirmation: passwordConfirmation.value } : {}),
      device_name: navigator.userAgent.slice(0, 100),
    })
    // Session rangée dans l'espace du compte (équipe ou livreur)
    auth.startSession(data)
    router.replace(auth.homeRoute)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>
