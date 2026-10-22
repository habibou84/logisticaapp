<template>
  <div class="min-h-screen bg-gradient-to-br from-emerald-700 via-emerald-600 to-teal-600 px-4 py-8">
    <div class="mx-auto w-full max-w-lg">
      <RouterLink to="/" class="flex justify-center"><BrandLogo size="md" dark /></RouterLink>

      <div class="mt-6 rounded-3xl bg-white text-slate-900 shadow-2xl p-6 md:p-8">
        <div v-if="!options" class="py-10 text-center text-slate-500">Chargement…</div>

        <div v-else-if="!options.open" class="space-y-4 text-center">
          <h1 class="text-2xl font-bold">Ouverture de compte</h1>
          <p class="text-slate-600">Les inscriptions en ligne sont fermées pour le moment. Contactez-nous pour ouvrir votre compte.</p>
          <a v-if="contactLink" :href="contactLink" target="_blank" rel="noopener" class="inline-block rounded-xl bg-emerald-600 px-6 py-3 font-semibold text-white">💬 Nous écrire sur WhatsApp</a>
        </div>

        <!-- Étape 2 : vérification du numéro -->
        <PhoneVerificationStep v-else-if="pending" :token="pending.token" :initial="pending.data" @update="remember" @done="finish" @restart="restart" />

        <!-- Étape 1 : informations de la boutique -->
        <form v-else class="space-y-4" novalidate @submit.prevent="submit">
          <div class="text-center space-y-1">
            <h1 class="text-2xl font-bold">Créer mon compte e-commerçant</h1>
            <p class="text-sm text-slate-500">Gratuit, en 2 minutes. Votre numéro sera vérifié par WhatsApp ou SMS.</p>
          </div>

          <p v-if="error" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2" role="alert">{{ error }}</p>

          <Field id="su-business" label="Nom de la boutique" :error="errors.business_name">
            <input id="su-business" v-model.trim="form.business_name" :class="inputClass" autocomplete="organization" required maxlength="255" placeholder="Ex. : Awa Mode">
          </Field>
          <Field id="su-contact" label="Votre nom" :error="errors.contact_name">
            <input id="su-contact" v-model.trim="form.contact_name" :class="inputClass" autocomplete="name" required maxlength="255" placeholder="Prénom et nom">
          </Field>
          <Field id="su-phone" label="Téléphone (WhatsApp de préférence)" :error="errors.phone">
            <input id="su-phone" v-model.trim="form.phone" :class="inputClass" type="tel" inputmode="tel" autocomplete="tel" required placeholder="07 00 00 00 00">
          </Field>
          <Field id="su-email" label="E-mail (facultatif)" :error="errors.email">
            <input id="su-email" v-model.trim="form.email" :class="inputClass" type="email" autocomplete="email" placeholder="vous@exemple.ci">
          </Field>

          <fieldset class="space-y-4 rounded-2xl bg-slate-50 p-4">
            <legend class="text-sm font-semibold text-slate-700 px-1">Où récupérer vos colis ?</legend>
            <Field id="su-zone" label="Commune ou quartier" :error="errors.pickup_zone_id">
              <select id="su-zone" v-model="form.pickup_zone_id" :class="inputClass" required>
                <option :value="null" disabled>Choisissez…</option>
                <option v-for="z in options.zones" :key="z.id" :value="z.id">{{ z.name }}</option>
              </select>
            </Field>
            <Field id="su-address" label="Adresse" :error="errors.pickup_address">
              <input id="su-address" v-model.trim="form.pickup_address" :class="inputClass" autocomplete="street-address" required maxlength="500" placeholder="Rue, immeuble, boutique…">
            </Field>
            <Field id="su-landmark" label="Repère (facultatif)" :error="errors.pickup_landmark">
              <input id="su-landmark" v-model.trim="form.pickup_landmark" :class="inputClass" maxlength="255" placeholder="Ex. : en face de la pharmacie">
            </Field>
          </fieldset>

          <Field id="su-password" label="Mot de passe (8 caractères minimum)" :error="errors.password">
            <input id="su-password" v-model="form.password" :class="inputClass" type="password" autocomplete="new-password" required minlength="8">
          </Field>
          <Field id="su-password2" label="Confirmez le mot de passe">
            <input id="su-password2" v-model="form.password_confirmation" :class="inputClass" type="password" autocomplete="new-password" required minlength="8">
          </Field>

          <button type="submit" :disabled="busy" class="w-full rounded-xl bg-emerald-600 hover:bg-emerald-700 py-3 font-semibold text-white shadow-sm disabled:opacity-60">
            {{ busy ? 'Un instant…' : 'Continuer' }}
          </button>
          <p class="text-center text-xs text-slate-500">
            En continuant, vous acceptez les <a href="/conditions" target="_blank" rel="noopener" class="underline">conditions générales</a>
            et la <a href="/confidentialite" target="_blank" rel="noopener" class="underline">politique de confidentialité</a>.
          </p>
          <p class="text-center text-sm text-slate-600">Déjà client ? <RouterLink to="/" class="font-semibold text-emerald-700 hover:underline">Se connecter</RouterLink></p>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BrandLogo from '../../components/auth/BrandLogo.vue'
import PhoneVerificationStep from '../../components/auth/PhoneVerificationStep.vue'
import { branding, loadBranding, whatsappLink } from '../../composables/useBranding'
import { clearPending, readPending, savePending } from '../../composables/usePendingVerification'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

// Champ avec libellé et message d'erreur
const Field = defineComponent({
  props: { id: String, label: String, error: String },
  setup(props, { slots }) {
    return () => h('div', [
      h('label', { for: props.id, class: 'block text-sm font-medium text-slate-700 mb-1' }, props.label),
      slots.default?.(),
      props.error ? h('p', { class: 'mt-1 text-sm text-red-600' }, props.error) : null,
    ])
  },
})

const inputClass = 'w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const toasts = useToastStore()

const options = ref(null)
const pending = ref(readPending('signup'))
const busy = ref(false)
const error = ref('')
const errors = ref({})
const form = reactive({
  business_name: '', contact_name: '', phone: '', email: '',
  pickup_zone_id: null, pickup_address: '', pickup_landmark: '',
  password: '', password_confirmation: '',
})

const contactLink = computed(() => whatsappLink(branding.phone, `Bonjour, je souhaite ouvrir un compte e-commerçant chez ${branding.name}.`))

async function submit() {
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    const { data } = await http.post('/signup', { ...form, email: form.email || null, pickup_landmark: form.pickup_landmark || null })
    pending.value = { token: data.token, data: data.data }
    savePending('signup', pending.value)
  } catch (e) {
    errors.value = Object.fromEntries(Object.entries(e.response?.data?.errors ?? {}).map(([k, v]) => [k, v[0]]))
    error.value = Object.keys(errors.value).length ? 'Vérifiez les informations en rouge.' : apiErrorMessage(e, 'Inscription impossible pour le moment.')
  } finally {
    busy.value = false
  }
}

function remember(data) {
  pending.value = { ...pending.value, data }
  savePending('signup', pending.value)
}

function finish(response) {
  clearPending('signup')
  if (response.session) {
    auth.startSession(response.session)
    toasts.success(`Bienvenue chez ${branding.name} ! Votre compte est ouvert.`)
    // Venu de « Connecter » (Jibiat Marchand) : on y retourne pour autoriser l'application
    const back = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/marchand/connecter') ? route.query.redirect : null
    if (back) return router.replace(back)
    // Étape facultative : où récupérer les colis (« Plus tard » possible)
    router.replace(auth.user?.merchant_id ? { path: '/marchand/lieu-de-ramassage', query: { bienvenue: 1 } } : auth.homeRoute)
  } else {
    // Session déjà ouverte depuis un autre onglet ou appareil
    toasts.success('Votre compte est ouvert : connectez-vous.')
    router.replace('/')
  }
}

function restart() {
  clearPending('signup')
  pending.value = null
}

onMounted(async () => {
  loadBranding()
  try {
    const { data } = await http.get('/signup')
    options.value = data.data
  } catch {
    options.value = { open: false, zones: [] }
  }
})
</script>
