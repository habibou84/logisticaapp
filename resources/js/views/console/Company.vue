<template>
  <div v-if="company" class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <RouterLink to="/console" class="text-sm text-blue-600">← Entreprises</RouterLink>
        <h1 class="text-xl font-bold">{{ company.name }}</h1>
        <a :href="company.url" target="_blank" rel="noopener" class="text-sm text-blue-600">{{ company.url.replace(/^https?:\/\//, '') }}</a>
        <p v-if="company.custom_domain_status === 'pending'" class="mt-1 max-w-xl rounded bg-amber-50 px-3 py-2 text-xs text-amber-900">
          Domaine <strong>{{ company.custom_domain }}</strong> en attente. Côté serveur : le nom d'hôte doit exister dans Cloudflare
          (Custom Hostnames, automatique si le jeton Cloudflare est configuré) et dans le site ISPConfig de l'application
          (<code>ServerAlias {{ company.custom_domain }}</code>). Voir docs/PLATEFORME.md.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <span :class="['rounded-full px-3 py-1 text-sm font-medium', active ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-700']">
          {{ active ? 'Active' : 'Suspendue' }}
        </span>
        <button :class="active ? 'btn-danger' : 'btn-success'" @click="toggleStatus">{{ active ? 'Suspendre' : 'Réactiver' }}</button>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <StatCard title="Marchands" :value="company.merchants_count" icon="🏪" />
      <StatCard title="Livreurs" :value="company.couriers_count" icon="🛵" />
      <StatCard title="Courses ce mois" :value="company.orders_month_count" icon="📦" />
      <StatCard title="Courses au total" :value="company.orders_total_count" icon="📊" />
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
      <!-- Informations -->
      <form class="card p-4 space-y-3" @submit.prevent="save">
        <h2 class="font-semibold">Informations</h2>
        <div><label class="label" for="e-name">Nom</label><input id="e-name" v-model.trim="edit.name" class="input" required></div>
        <div>
          <label class="label" for="e-slug">Adresse</label>
          <div class="flex items-center rounded-lg border border-slate-300 focus-within:ring-2 focus-within:ring-blue-500">
            <input id="e-slug" v-model.trim="edit.slug" class="flex-1 min-w-0 bg-transparent px-3 py-2 focus:outline-none" required minlength="3" maxlength="40">
            <span v-if="domain" class="pr-3 text-sm text-gray-500 whitespace-nowrap">.{{ domain }}</span>
          </div>
          <p v-if="edit.slug !== company.slug" class="text-xs text-amber-700 mt-1">
            ⚠ L'ancienne adresse cessera de fonctionner : liens de suivi déjà envoyés, boutiques partagées et applications installées.
          </p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label" for="e-phone">Téléphone</label><input id="e-phone" v-model.trim="edit.phone" class="input"></div>
          <div><label class="label" for="e-email">E-mail</label><input id="e-email" v-model.trim="edit.email" type="email" class="input"></div>
        </div>
        <div><label class="label" for="e-tz">Fuseau horaire</label><input id="e-tz" v-model.trim="edit.timezone" class="input" placeholder="Africa/Abidjan"></div>
        <p v-if="editError" class="field-error">{{ editError }}</p>
        <button class="btn-primary" :disabled="saving">Enregistrer</button>
      </form>

      <!-- Abonnement : formule, essai, consommation du mois -->
      <div class="card p-4 space-y-3 lg:col-span-2">
        <h2 class="font-semibold">🧾 Abonnement</h2>
        <div class="grid md:grid-cols-2 gap-6">
          <SubscriptionSummary :summary="company.subscription" />
          <form class="space-y-3" @submit.prevent="saveSubscription">
            <div>
              <label class="label" for="s-plan">Formule</label>
              <select id="s-plan" v-model="sub.plan_id" class="input">
                <option :value="null">Sans formule (aucune limite)</option>
                <option v-for="p in plans" :key="p.id" :value="p.id" :disabled="!p.is_active && p.id !== company.plan?.id">
                  {{ p.name }} · {{ money(p.price_monthly) }} / mois{{ p.is_active ? '' : ' (retirée)' }}
                </option>
              </select>
            </div>
            <div>
              <label class="label" for="s-trial">Fin de l'essai gratuit</label>
              <input id="s-trial" v-model="sub.trial_ends_at" type="date" class="input">
              <p class="text-xs text-gray-500 mt-1">Vide : pas d'essai. Date incluse ; la facturation commence le lendemain.</p>
            </div>
            <p v-if="subError" class="field-error">{{ subError }}</p>
            <button class="btn-primary" :disabled="subSaving">Enregistrer</button>
          </form>
        </div>
        <div class="border-t pt-3">
          <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <h3 class="font-medium">Factures</h3>
            <span v-if="company.billing.amount_due" class="text-sm">
              Reste à payer : <strong>{{ money(company.billing.amount_due) }}</strong>
              <span v-if="company.billing.state === 'restricted'" class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">courses suspendues</span>
            </span>
          </div>
          <table v-if="company.invoices.length" class="w-full text-sm">
            <tbody class="divide-y">
              <tr v-for="i in company.invoices" :key="i.id">
                <td class="py-1 font-mono"><RouterLink :to="`/console/factures/${i.id}`" class="text-blue-600">{{ i.number }}</RouterLink></td>
                <td class="py-1">{{ i.period }}</td>
                <td class="py-1 text-right">{{ money(i.total) }}</td>
                <td class="py-1 text-right">{{ i.status === 'void' ? '—' : money(i.balance) }}</td>
                <td class="py-1 text-right"><InvoiceStatus :invoice="i" /></td>
              </tr>
            </tbody>
          </table>
          <p v-else class="text-sm text-gray-500">Aucune facture. Les factures sont créées le 1er de chaque mois pour le mois écoulé (Facturation).</p>
        </div>
      </div>

      <!-- Mise en route de l'entreprise : pour l'accompagner au démarrage -->
      <div v-if="company.setup" class="card p-4 space-y-3">
        <h2 class="font-semibold">🧭 Mise en route</h2>
        <p class="text-sm">
          {{ company.setup.ready ? 'Étapes essentielles faites.' : `Étapes essentielles : ${company.setup.essentials.done} sur ${company.setup.essentials.total}.` }}
          Conseillées : {{ company.setup.recommended.done }} sur {{ company.setup.recommended.total }}.
        </p>
        <ul class="text-sm space-y-1">
          <li v-for="st in company.setup.steps" :key="st.key" :class="st.status === 'done' ? 'text-gray-500' : ''">
            {{ st.status === 'done' ? '✅' : (st.status === 'partial' ? '🟠' : '⭕') }} {{ st.title }}<span v-if="!st.essential" class="text-xs text-gray-400"> · conseillée</span>
            <span v-if="st.status !== 'done'" class="block pl-6 text-xs text-gray-600">{{ st.lines.join(' ') }}</span>
          </li>
        </ul>
        <div v-if="company.setup.health.length" class="border-t pt-3 space-y-1">
          <h3 class="text-sm font-medium">Bilan de santé</h3>
          <p v-for="h in company.setup.health" :key="h.text" :class="['text-sm', h.level === 'alert' ? 'text-red-700' : 'text-amber-800']">{{ h.level === 'alert' ? '🔴' : '🟠' }} {{ h.text }}</p>
        </div>
      </div>

      <!-- Assistance -->
      <div class="card p-4 space-y-3">
        <h2 class="font-semibold">🛟 Assistance</h2>
        <p class="text-sm text-gray-600">
          Ouvre l'espace de l'entreprise avec le compte d'un membre de son équipe, pour une heure. Un bandeau rouge le signale
          pendant toute la session, et chaque ouverture est enregistrée ci-dessous.
        </p>
        <div>
          <label class="label" for="s-user">Compte utilisé</label>
          <select id="s-user" v-model="support.user_id" class="input">
            <option :value="null">Le premier administrateur</option>
            <option v-for="u in company.staff" :key="u.id" :value="u.id" :disabled="u.status !== 'active'">{{ u.name }} · {{ ROLES[u.role] || u.role }}{{ u.status !== 'active' ? ' (suspendu)' : '' }}</option>
          </select>
        </div>
        <div><label class="label" for="s-reason">Motif</label><input id="s-reason" v-model.trim="support.reason" class="input" maxlength="255" placeholder="Ex. : réglage des tarifs à la demande du gérant"></div>
        <p v-if="supportError" class="field-error">{{ supportError }}</p>
        <button class="btn-secondary" :disabled="!active || opening" @click="openSupport">{{ opening ? 'Ouverture…' : 'Ouvrir son espace' }}</button>

        <div v-if="company.support_sessions.length" class="border-t pt-3">
          <h3 class="text-sm font-medium mb-1">Dernières sessions</h3>
          <ul class="text-xs text-gray-600 space-y-1">
            <li v-for="s in company.support_sessions" :key="s.id">
              {{ dateTime(s.created_at) }} · {{ s.opened_by }} → {{ s.user }}<span v-if="s.reason"> · {{ s.reason }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
  <p v-else class="text-gray-500">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import StatCard from '../../components/StatCard.vue'
import InvoiceStatus from '../../components/InvoiceStatus.vue'
import SubscriptionSummary from '../../components/SubscriptionSummary.vue'
import { useToastStore } from '../../stores/toasts'
import { dateTime, money } from '../../utils/format'

const ROLES = { admin: 'Administrateur', dispatcher: 'Dispatch', cashier: 'Caisse', hub_agent: 'Entrepôt' }

const route = useRoute()
const toasts = useToastStore()
const company = ref(null)
const domain = ref(null)
const edit = reactive({})
const editError = ref('')
const saving = ref(false)
const support = reactive({ user_id: null, reason: '' })
const supportError = ref('')
const opening = ref(false)
const plans = ref([])
const sub = reactive({ plan_id: null, trial_ends_at: '' })
const subError = ref('')
const subSaving = ref(false)

const active = computed(() => company.value?.status === 'active')

async function load() {
  company.value = (await http.get(`/console/companies/${route.params.id}`)).data.data
  domain.value = company.value.domain
  Object.assign(sub, { plan_id: company.value.plan?.id ?? null, trial_ends_at: company.value.trial_ends_at || '' })
  Object.assign(edit, {
    name: company.value.name, slug: company.value.slug, phone: company.value.phone || '',
    email: company.value.email || '', timezone: company.value.timezone || 'Africa/Abidjan',
  })
}

async function save() {
  saving.value = true
  editError.value = ''
  try {
    // L'adresse n'est envoyée que si elle change
    const payload = { name: edit.name, phone: edit.phone || null, email: edit.email || null, timezone: edit.timezone }
    if (edit.slug !== company.value.slug) payload.slug = edit.slug
    await http.patch(`/companies/${company.value.id}`, payload)
    await load()
    toasts.success('Entreprise enregistrée.')
  } catch (e) {
    editError.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function saveSubscription() {
  subSaving.value = true
  subError.value = ''
  try {
    await http.patch(`/companies/${company.value.id}`, { plan_id: sub.plan_id, trial_ends_at: sub.trial_ends_at || null })
    await load()
    toasts.success('Abonnement enregistré.')
  } catch (e) {
    subError.value = apiErrorMessage(e)
  } finally {
    subSaving.value = false
  }
}

async function toggleStatus() {
  const next = active.value ? 'suspended' : 'active'
  if (next === 'suspended' && !window.confirm(`Suspendre ${company.value.name} ? Ses équipes, marchands et livreurs ne pourront plus se connecter, et son adresse affichera « service suspendu ».`)) return
  try {
    await http.patch(`/companies/${company.value.id}`, { status: next })
    await load()
    toasts.success(next === 'active' ? 'Entreprise réactivée.' : 'Entreprise suspendue.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function openSupport() {
  opening.value = true
  supportError.value = ''
  // Fenêtre ouverte tout de suite (sinon bloquée comme fenêtre surgissante), puis dirigée vers l'espace
  const win = window.open('', '_blank')
  try {
    const { data } = await http.post(`/console/companies/${company.value.id}/support-session`, { user_id: support.user_id, reason: support.reason || null })
    if (win) win.location.href = data.data.url
    else window.location.href = data.data.url
    support.reason = ''
    await load()
  } catch (e) {
    win?.close()
    supportError.value = apiErrorMessage(e)
  } finally {
    opening.value = false
  }
}

onMounted(async () => {
  await load()
  plans.value = (await http.get('/console/plans')).data.data
})
</script>
