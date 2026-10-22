<template>
  <div class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 px-4 py-8">
    <div class="mx-auto w-full max-w-2xl">
      <p class="text-center text-white">
        <span class="text-2xl font-bold">{{ appName }}</span><br>
        <span class="text-sm text-slate-300">Le logiciel des entreprises de livraison</span>
      </p>

      <div class="mt-6 rounded-3xl bg-white text-slate-900 shadow-2xl p-6 md:p-8">
        <div v-if="!options" class="py-10 text-center text-slate-500">Chargement…</div>

        <div v-else-if="done" class="space-y-4 text-center py-4">
          <p class="text-5xl">🎉</p>
          <h1 class="text-2xl font-bold">Votre espace est prêt !</h1>
          <p class="text-slate-600">
            Adresse de votre entreprise : <strong class="break-all">{{ host(done.url) }}</strong><br>
            Gardez-la : c'est aussi celle de vos marchands et de vos livreurs.
          </p>
          <p v-if="done.company.trial_ends_at" class="text-sm text-slate-500">Essai gratuit jusqu'au {{ date(done.company.trial_ends_at) }}.</p>
          <a :href="done.login_url" class="inline-block rounded-xl bg-blue-600 px-6 py-3 font-semibold text-white">Ouvrir mon espace →</a>
        </div>

        <div v-else-if="!options.open" class="space-y-3 text-center">
          <h1 class="text-2xl font-bold">Créer mon entreprise</h1>
          <p class="text-slate-600">Les inscriptions sont fermées pour le moment. Contactez-nous pour ouvrir votre espace.</p>
        </div>

        <form v-else class="space-y-5" novalidate @submit.prevent="submit">
          <div class="text-center space-y-1">
            <h1 class="text-2xl font-bold">Créer mon entreprise de livraison</h1>
            <p class="text-sm text-slate-500">
              <template v-if="options.trial_days">{{ options.trial_days }} jours d'essai gratuit, sans engagement.</template>
              Votre espace est prêt en 2 minutes.
            </p>
          </div>

          <p v-if="error" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2" role="alert">{{ error }}</p>

          <Field id="cs-name" label="Nom de l'entreprise" :error="errors.name">
            <input id="cs-name" v-model.trim="form.name" :class="inputClass" autocomplete="organization" required maxlength="255" placeholder="Ex. : Rapide Express" @input="suggestSlug">
          </Field>

          <Field id="cs-slug" label="Adresse de votre espace" :error="errors.slug || (slugState && !slugState.available ? slugState.message : '')">
            <div class="flex items-center rounded-xl border border-slate-300 bg-white focus-within:ring-2 focus-within:ring-blue-500">
              <input id="cs-slug" v-model.trim="form.slug" class="flex-1 min-w-0 bg-transparent px-4 py-3 text-base focus:outline-none" required minlength="3" maxlength="40" autocapitalize="none" spellcheck="false" @input="slugTouched = true; checkSlug()">
              <span class="pr-4 text-slate-500 whitespace-nowrap">.{{ options.domain }}</span>
            </div>
            <p v-if="slugState?.available" class="mt-1 text-sm text-emerald-700">✓ Adresse libre</p>
            <p v-else-if="!slugState" class="mt-1 text-xs text-slate-500">Minuscules, chiffres et tirets.</p>
          </Field>

          <fieldset class="space-y-2">
            <legend class="block text-sm font-medium text-slate-700 mb-1">Formule (après l'essai gratuit)</legend>
            <label
              v-for="p in options.plans"
              :key="p.id"
              :class="['block cursor-pointer rounded-2xl border p-4', form.plan_id === p.id ? 'border-blue-600 ring-2 ring-blue-600 bg-blue-50/40' : 'border-slate-300']"
            >
              <div class="flex items-start gap-3">
                <input v-model="form.plan_id" type="radio" name="plan" :value="p.id" class="mt-1">
                <div class="flex-1">
                  <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-semibold">{{ p.name }}</span>
                    <span class="font-bold">{{ money(p.price_monthly) }} <span class="text-sm font-normal text-slate-500">/ mois</span></span>
                  </div>
                  <p v-if="p.description" class="text-sm text-slate-600">{{ p.description }}</p>
                  <p class="text-xs text-slate-500 mt-1">
                    {{ p.included_orders ? `${p.included_orders} courses incluses` : 'Courses illimitées' }}<template v-if="p.included_orders && p.overage_per_order">, puis {{ money(p.overage_per_order) }} la course</template>
                    · {{ p.max_couriers ? `${p.max_couriers} livreurs` : 'livreurs illimités' }}
                    <template v-if="p.features.length"> · {{ p.features.join(', ') }}</template>
                  </p>
                </div>
              </div>
            </label>
            <p v-if="errors.plan_id" class="text-sm text-red-600">{{ errors.plan_id }}</p>
          </fieldset>

          <fieldset class="space-y-4 rounded-2xl bg-slate-50 p-4">
            <legend class="text-sm font-semibold text-slate-700 px-1">Votre compte administrateur</legend>
            <Field id="cs-admin" label="Votre nom" :error="errors.admin_name">
              <input id="cs-admin" v-model.trim="form.admin_name" :class="inputClass" autocomplete="name" required maxlength="255" placeholder="Prénom et nom">
            </Field>
            <div class="grid gap-4 sm:grid-cols-2">
              <Field id="cs-phone" label="Téléphone (identifiant de connexion)" :error="errors.phone">
                <input id="cs-phone" v-model.trim="form.phone" :class="inputClass" type="tel" inputmode="tel" autocomplete="tel" required placeholder="07 00 00 00 00">
              </Field>
              <Field id="cs-email" label="E-mail (facultatif)" :error="errors.email">
                <input id="cs-email" v-model.trim="form.email" :class="inputClass" type="email" autocomplete="email" placeholder="vous@exemple.ci">
              </Field>
              <Field id="cs-password" label="Mot de passe (8 caractères min.)" :error="errors.password">
                <input id="cs-password" v-model="form.password" :class="inputClass" type="password" autocomplete="new-password" required minlength="8">
              </Field>
              <Field id="cs-password2" label="Confirmez le mot de passe">
                <input id="cs-password2" v-model="form.password_confirmation" :class="inputClass" type="password" autocomplete="new-password" required minlength="8">
              </Field>
            </div>
          </fieldset>

          <!-- Champ invisible : seuls les robots le remplissent -->
          <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">

          <label class="flex items-start gap-2 text-sm text-slate-600">
            <input v-model="form.accept_terms" type="checkbox" class="mt-0.5">
            <span>
              J'accepte les <a href="/conditions" target="_blank" rel="noopener" class="font-medium text-blue-600 underline">conditions générales</a>
              et d'être facturé selon la formule choisie à la fin de l'essai gratuit (paiement mensuel), sauf si j'arrête avant.
            </span>
          </label>
          <p v-if="errors.accept_terms" class="-mt-3 text-sm text-red-600">{{ errors.accept_terms }}</p>

          <button class="w-full rounded-xl bg-blue-600 px-6 py-3.5 text-lg font-semibold text-white disabled:opacity-60" :disabled="saving">
            {{ saving ? 'Création de votre espace…' : 'Créer mon entreprise' }}
          </button>
          <p class="text-center text-sm text-slate-500">Déjà inscrit ? Connectez-vous à l'adresse de votre entreprise.</p>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { defineComponent, h, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { date, money } from '../../utils/format'

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

const inputClass = 'w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent'

const appName = document.title.split('·')[0].trim() || 'Jibiat'
const options = ref(null)
const form = reactive({
  name: '', slug: '', plan_id: null, admin_name: '', phone: '', email: '',
  password: '', password_confirmation: '', accept_terms: false, website: '',
})
const errors = ref({})
const error = ref('')
const saving = ref(false)
const done = ref(null)
const slugTouched = ref(false)
const slugState = ref(null)
let slugTimer = null

function host(url) {
  return url.replace(/^https?:\/\//, '')
}

// « Rapide Express » → rapide-express
function slugify(text) {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 36).replace(/-+$/, '')
}

function suggestSlug() {
  if (slugTouched.value) return
  form.slug = slugify(form.name)
  checkSlug()
}

function checkSlug() {
  clearTimeout(slugTimer)
  slugState.value = null
  if (form.slug.length < 3) return
  const wanted = form.slug
  slugTimer = setTimeout(async () => {
    try {
      const { data } = await http.get('/company-signup/slug', { params: { slug: wanted } })
      if (form.slug === wanted) slugState.value = data.data
    } catch {
      // vérification indisponible : le serveur contrôlera à l'envoi
    }
  }, 400)
}

async function submit() {
  saving.value = true
  error.value = ''
  errors.value = {}
  try {
    const { data } = await http.post('/company-signup', { ...form, email: form.email || null })
    done.value = data.data
    // Ouvre directement l'espace de l'entreprise (session déjà ouverte)
    setTimeout(() => { window.location.href = data.data.login_url }, 2500)
  } catch (e) {
    errors.value = Object.fromEntries(Object.entries(e.response?.data?.errors ?? {}).map(([k, v]) => [k, v[0]]))
    error.value = Object.keys(errors.value).length ? 'Vérifiez les informations en rouge.' : apiErrorMessage(e, 'Inscription impossible pour le moment.')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    options.value = (await http.get('/company-signup')).data.data
  } catch {
    options.value = { open: false, plans: [] }
  }
  // Formule choisie sur la page des tarifs (?formule=…), sinon la première
  const chosen = Number(new URLSearchParams(window.location.search).get('formule'))
  if (options.value.plans?.length) form.plan_id = options.value.plans.find((p) => p.id === chosen)?.id ?? options.value.plans[0].id
})
</script>
