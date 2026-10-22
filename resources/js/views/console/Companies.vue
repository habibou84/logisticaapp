<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Entreprises de livraison</h1>
      <button class="btn-primary" @click="openCreate">+ Nouvelle entreprise</button>
    </div>

    <div v-if="meta.totals" class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <StatCard title="Entreprises" :value="meta.totals.companies" icon="🏢" />
      <StatCard title="Actives" :value="meta.totals.active" icon="✅" />
      <StatCard title="En essai gratuit" :value="meta.totals.on_trial" icon="🎁" />
      <StatCard title="Courses ce mois" :value="meta.totals.orders_month" icon="📦" />
    </div>

    <div class="card flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
      <p>
        <span class="font-medium">Inscription en ligne :</span>{{ ' ' }}
        <template v-if="signupOpen === null">…</template>
        <template v-else-if="signupOpen">
          ouverte sur <a :href="signupUrl" target="_blank" rel="noopener" class="text-blue-600">{{ host(signupUrl) }}</a>
          <span v-if="meta.totals?.signups_month" class="text-gray-500"> · {{ meta.totals.signups_month }} ce mois</span>
        </template>
        <template v-else>fermée (les entreprises sont créées ici)</template>
      </p>
      <button class="btn-secondary !py-1" :disabled="signupOpen === null" @click="toggleSignup">{{ signupOpen ? 'Fermer' : 'Ouvrir' }}</button>
    </div>

    <div class="flex flex-wrap gap-2">
      <input v-model="search" class="input w-64" placeholder="Nom ou adresse…" aria-label="Rechercher" @input="debouncedLoad">
      <select v-model="status" class="input w-auto" aria-label="Statut" @change="load">
        <option value="">Toutes</option>
        <option value="active">Actives</option>
        <option value="suspended">Suspendues</option>
      </select>
      <select v-model="source" class="input w-auto" aria-label="Origine" @change="load">
        <option value="">Toutes origines</option>
        <option value="signup">Inscrites en ligne</option>
      </select>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr>
            <th class="p-2">Entreprise</th><th class="p-2">Adresse</th><th class="p-2 text-right">Marchands</th><th class="p-2 text-right">Livreurs</th>
            <th class="p-2 text-right">Courses du mois</th><th class="p-2">Dernière course</th><th class="p-2">Mise en route</th><th class="p-2">Formule</th><th class="p-2">Statut</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="c in companies" :key="c.id" class="hover:bg-slate-50 cursor-pointer" @click="$router.push(`/console/entreprises/${c.id}`)">
            <td class="p-2 font-medium">
              {{ c.name }}
              <span v-if="c.source === 'signup'" class="ml-1 whitespace-nowrap rounded-full bg-violet-100 px-2 py-0.5 text-xs font-normal text-violet-800" :title="`Inscrite le ${date(c.created_at)}`">en ligne</span>
            </td>
            <td class="p-2">
              <a :href="c.url" target="_blank" rel="noopener" class="text-blue-600" @click.stop>{{ host(c.url) }}</a>
              <span v-if="c.custom_domain_status === 'pending'" class="ml-1 whitespace-nowrap rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800" :title="`Domaine ${c.custom_domain} en attente de vérification`">{{ c.custom_domain }} · en attente</span>
            </td>
            <td class="p-2 text-right">{{ c.merchants_count }}</td>
            <td class="p-2 text-right">{{ c.couriers_count }}</td>
            <td class="p-2 text-right font-semibold">{{ c.orders_month_count }}</td>
            <td class="p-2 text-gray-600">{{ c.last_order_at ? dateTime(c.last_order_at) : '—' }}</td>
            <td class="p-2 whitespace-nowrap">
              <span :class="c.setup?.ready ? 'text-emerald-700' : 'text-amber-800'">{{ c.setup?.ready ? '✅ prête' : `${c.setup?.done ?? 0}/${c.setup?.total ?? 0}` }}</span>
              <span v-if="c.setup?.alerts" class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700" :title="'Points à corriger au bilan de santé'">{{ c.setup.alerts }} alerte(s)</span>
            </td>
            <td class="p-2">
              <span v-if="c.plan">{{ c.plan.name }}</span><span v-else class="text-gray-400">Sans formule</span>
              <span v-if="c.on_trial" class="ml-1 rounded-full bg-sky-100 px-2 py-0.5 text-xs text-sky-800">essai → {{ date(c.trial_ends_at) }}</span>
            </td>
            <td class="p-2">
              <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', c.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-700']">
                {{ c.status === 'active' ? 'Active' : 'Suspendue' }}
              </span>
            </td>
          </tr>
          <tr v-if="loaded && !companies.length"><td colspan="9" class="p-6 text-center text-gray-500">Aucune entreprise.</td></tr>
        </tbody>
      </table>
    </div>

    <Modal :open="form.open" title="Nouvelle entreprise" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="create">
        <div>
          <label class="label" for="c-name">Nom *</label>
          <input id="c-name" v-model.trim="form.name" class="input" required maxlength="255" placeholder="Ex. : Rapide Express" @input="suggestSlug">
        </div>
        <div>
          <label class="label" for="c-slug">Adresse *</label>
          <div class="flex items-center rounded-lg border border-slate-300 focus-within:ring-2 focus-within:ring-blue-500">
            <input id="c-slug" v-model.trim="form.slug" class="flex-1 min-w-0 bg-transparent px-3 py-2 focus:outline-none" required minlength="3" maxlength="40" @input="form.slugTouched = true">
            <span class="pr-3 text-sm text-gray-500 whitespace-nowrap">.{{ meta.domain || 'votre-domaine' }}</span>
          </div>
          <p class="text-xs text-gray-500 mt-1">Minuscules, chiffres et tirets. C'est l'adresse de l'entreprise, de ses marchands et de ses livreurs.</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label" for="c-phone">Téléphone</label><input id="c-phone" v-model.trim="form.phone" class="input"></div>
          <div>
            <label class="label" for="c-tz">Fuseau horaire</label>
            <select id="c-tz" v-model="form.timezone" class="input">
              <option v-for="tz in TIMEZONES" :key="tz.value" :value="tz.value">{{ tz.label }}</option>
            </select>
          </div>
        </div>
        <div>
          <label class="label" for="c-plan">Formule</label>
          <select id="c-plan" v-model="form.plan_id" class="input">
            <option :value="null">Sans formule (aucune limite)</option>
            <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }} · {{ money(p.price_monthly) }} / mois</option>
          </select>
          <p v-if="form.plan_id && trialDays" class="text-xs text-gray-500 mt-1">Essai gratuit de {{ trialDays }} jours, puis facturation mensuelle.</p>
          <p v-if="!plans.length" class="text-xs text-gray-500 mt-1"><RouterLink to="/console/formules" class="text-blue-600">Créer les formules</RouterLink> pour les proposer ici.</p>
        </div>
        <fieldset class="border rounded p-3 space-y-2">
          <legend class="text-sm font-medium px-1">Premier administrateur</legend>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="label" for="a-name">Nom *</label><input id="a-name" v-model.trim="form.admin.name" class="input" required></div>
            <div><label class="label" for="a-phone">Téléphone *</label><input id="a-phone" v-model.trim="form.admin.phone" class="input" required></div>
            <div class="col-span-2"><label class="label" for="a-pass">Mot de passe provisoire * (8 caractères min.)</label><input id="a-pass" v-model="form.admin.password" class="input" required minlength="8" autocomplete="new-password"></div>
          </div>
          <p class="text-xs text-gray-500">Communiquez-le à l'entreprise ; elle pourra le changer (« Mot de passe oublié »).</p>
        </fieldset>
        <p v-if="form.error" class="field-error">{{ form.error }}</p>
        <button class="btn-primary w-full" :disabled="form.saving">{{ form.saving ? 'Création…' : 'Créer l\'entreprise' }}</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import StatCard from '../../components/StatCard.vue'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime, money } from '../../utils/format'

const TIMEZONES = [
  { value: 'Africa/Abidjan', label: 'Côte d\'Ivoire, Sénégal, Mali… (GMT)' },
  { value: 'Africa/Lagos', label: 'Bénin, Cameroun, Gabon, Niger… (GMT+1)' },
  { value: 'Africa/Kinshasa', label: 'RD Congo, Congo (GMT+1)' },
  { value: 'Africa/Casablanca', label: 'Maroc' },
  { value: 'Europe/Paris', label: 'France' },
]

const router = useRouter()
const toasts = useToastStore()
const companies = ref([])
const meta = ref({})
const loaded = ref(false)
const search = ref('')
const status = ref('')
const source = ref('')
// Inscription des entreprises en libre-service (réglage de la plateforme)
const signupOpen = ref(null)
const signupUrl = `${window.location.origin}/creer-mon-entreprise`
const form = reactive({ open: false, name: '', slug: '', slugTouched: false, phone: '', timezone: 'Africa/Abidjan', plan_id: null, admin: {}, error: '', saving: false })
// Formules proposées aux nouvelles entreprises
const plans = ref([])
const trialDays = ref(0)

function host(url) {
  return url.replace(/^https?:\/\//, '')
}

async function load() {
  const { data } = await http.get('/console/companies', { params: { search: search.value || undefined, status: status.value || undefined, source: source.value || undefined } })
  companies.value = data.data
  meta.value = data.meta
  loaded.value = true
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
}

async function openCreate() {
  Object.assign(form, { open: true, name: '', slug: '', slugTouched: false, phone: '', timezone: 'Africa/Abidjan', plan_id: null, admin: { name: '', phone: '', password: '' }, error: '' })
  const { data } = await http.get('/console/plans')
  plans.value = data.data.filter((p) => p.is_active)
  trialDays.value = data.meta.trial_days
}

// Adresse proposée d'après le nom, tant qu'elle n'a pas été modifiée
function suggestSlug() {
  if (form.slugTouched) return
  form.slug = form.name.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40)
}

async function create() {
  form.saving = true
  form.error = ''
  try {
    const { data } = await http.post('/companies', {
      name: form.name, slug: form.slug, phone: form.phone || null, timezone: form.timezone, plan_id: form.plan_id, admin: form.admin,
    })
    form.open = false
    toasts.success(`Entreprise créée : ${data.data.name}.`)
    router.push(`/console/entreprises/${data.data.id}`)
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

async function toggleSignup() {
  try {
    const { data } = await http.put('/console/settings', { company_signup: !signupOpen.value })
    signupOpen.value = data.data.company_signup
    toasts.success(signupOpen.value ? 'Inscription en ligne ouverte.' : 'Inscription en ligne fermée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(async () => {
  await load()
  signupOpen.value = (await http.get('/console/settings')).data.data.company_signup
})
</script>
