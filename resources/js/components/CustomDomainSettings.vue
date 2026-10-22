<template>
  <section v-if="state && state.platform_url" class="card p-4 space-y-4">
    <div>
      <h2 class="font-semibold">Adresse de votre espace</h2>
      <p class="text-sm text-gray-600">
        Adresse actuelle : <a :href="state.url" target="_blank" rel="noopener" class="font-medium text-blue-600">{{ host(state.url) }}</a>
        <template v-if="state.custom_domain && state.status === 'active'"> (et toujours {{ host(state.platform_url) }})</template>
      </p>
    </div>

    <!-- Hors formule : on présente l'option -->
    <div v-if="!state.included" class="rounded-lg bg-slate-50 p-4 text-sm text-gray-700">
      🔒 <strong>Domaine personnalisé</strong> : utilisez votre propre adresse, par exemple <code>livraison.votre-entreprise.ci</code>,
      pour votre espace, vos marchands, vos livreurs et les liens de suivi envoyés à vos clients.
      Cette option n'est pas incluse dans votre formule actuelle.
      <RouterLink to="/admin/abonnement" class="ml-1 text-blue-600">Voir mon abonnement</RouterLink>
    </div>

    <p v-else-if="!state.available" class="text-sm text-gray-500">Les domaines personnalisés ne sont pas encore ouverts sur la plateforme. Contactez-nous.</p>

    <template v-else>
      <!-- Domaine choisi : état et instructions -->
      <div v-if="state.custom_domain" class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-mono">{{ state.custom_domain }}</span>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', state.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">
            {{ state.status === 'active' ? 'Actif' : 'En attente' }}
          </span>
          <span v-if="state.checked_at" class="text-xs text-gray-400">vérifié {{ dateTime(state.checked_at) }}</span>
        </div>

        <div v-if="state.status !== 'active'" class="space-y-2 text-sm">
          <p>Chez votre fournisseur de domaine (là où vous avez acheté <strong>{{ apex(state.custom_domain) }}</strong>), ajoutez cet enregistrement DNS :</p>
          <table class="w-full max-w-xl text-left text-sm">
            <thead class="text-gray-500"><tr><th class="py-1 pr-3 font-normal">Type</th><th class="py-1 pr-3 font-normal">Nom</th><th class="py-1 font-normal">Valeur</th></tr></thead>
            <tbody class="font-mono">
              <tr>
                <td class="py-1 pr-3">{{ state.dns_record.type }}</td>
                <td class="py-1 pr-3">{{ state.dns_record.name }}</td>
                <td class="py-1">
                  {{ state.dns_record.value }}
                  <button type="button" class="ml-1 font-sans text-xs text-blue-600" @click="copy(state.dns_record.value)">Copier</button>
                </td>
              </tr>
            </tbody>
          </table>
          <p class="text-xs text-gray-500">
            Certains fournisseurs demandent seulement la première partie du nom (« {{ state.custom_domain.split('.')[0] }} »).
            Utilisez un sous-domaine (ex. livraison.…) : un domaine sans sous-domaine ne peut pas recevoir de CNAME.
            La prise en compte peut prendre de quelques minutes à quelques heures ; nous vérifions automatiquement et vous prévenons dès que c'est prêt.
          </p>
          <p v-if="state.error" class="rounded bg-amber-50 px-3 py-2 text-amber-900">{{ state.error }}</p>
          <p v-if="state.dns && !state.dns.ok" class="text-xs text-gray-500">
            DNS lu actuellement : {{ state.dns.cname.length ? `CNAME → ${state.dns.cname.join(', ')}` : state.dns.a.length ? `A → ${state.dns.a.join(', ')}` : 'aucun enregistrement' }}
          </p>
        </div>
        <p v-else class="text-sm text-emerald-800">
          ✅ Votre adresse est active. Les liens envoyés par WhatsApp et SMS (suivi, boutiques, bons de retour) l'utilisent.
          Partagez-la à vos marchands et livreurs : ils s'y connectent avec les mêmes identifiants.
        </p>

        <div class="flex flex-wrap gap-2">
          <button v-if="state.status !== 'active'" class="btn-primary" :disabled="busy" @click="check">{{ busy ? 'Vérification…' : 'Vérifier maintenant' }}</button>
          <button class="btn-secondary" :disabled="busy" @click="remove">Retirer ce domaine</button>
        </div>
      </div>

      <!-- Choix du domaine -->
      <form v-else class="space-y-2" @submit.prevent="save">
        <label class="label" for="cd-domain">Votre domaine</label>
        <div class="flex flex-wrap gap-2">
          <input id="cd-domain" v-model.trim="domain" class="input max-w-sm" placeholder="livraison.votre-entreprise.ci" autocapitalize="none" spellcheck="false" required>
          <button class="btn-primary" :disabled="busy">{{ busy ? 'Enregistrement…' : 'Utiliser ce domaine' }}</button>
        </div>
        <p class="text-xs text-gray-500">Vous devez posséder ce domaine. Nous vous indiquerons ensuite l'enregistrement DNS à ajouter.</p>
      </form>
      <p v-if="error" class="field-error">{{ error }}</p>
    </template>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { dateTime } from '../utils/format'

const toasts = useToastStore()
const state = ref(null)
const domain = ref('')
const busy = ref(false)
const error = ref('')

function host(url) {
  return (url || '').replace(/^https?:\/\//, '')
}
// « livraison.rapide-express.ci » → « rapide-express.ci » (approximation pour le texte d'aide)
function apex(name) {
  return name.split('.').slice(-2).join('.')
}

async function run(request, success) {
  busy.value = true
  error.value = ''
  try {
    const { data } = await request()
    state.value = { ...state.value, ...data.data }
    if (success) toasts.success(success)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

const save = () => run(() => http.put('/company/domain', { domain: domain.value }))
const check = () => run(() => http.post('/company/domain/check'))

function remove() {
  if (!window.confirm(`Retirer ${state.value.custom_domain} ? Votre espace reste accessible à ${host(state.value.platform_url)}.`)) return
  run(() => http.delete('/company/domain'), 'Domaine retiré.')
  domain.value = ''
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Copié.')
  } catch {
    // presse-papiers indisponible
  }
}

onMounted(async () => {
  try {
    state.value = (await http.get('/company/domain')).data.data
  } catch {
    state.value = null
  }
})
</script>
