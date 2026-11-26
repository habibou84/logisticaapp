<template>
  <div class="max-w-4xl space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">WhatsApp</h1>
        <p class="text-sm text-slate-600">Le numéro de l'entreprise, les messages qui partent tout seuls, et comment ils partent.</p>
      </div>
      <RouterLink to="/admin/messages" class="btn-secondary">Messages</RouterLink>
    </div>
    <div><WhatsAppTabs /></div>

    <p v-if="loadError" class="field-error">{{ loadError }}</p>

    <template v-if="s">
      <!-- État : envois réels ou simulation, pour cette entreprise -->
      <div :class="['rounded-lg p-4 text-sm', s.mode === 'live' ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900']">
        <template v-if="s.mode === 'live'">
          <p class="font-semibold">✅ WhatsApp connecté : les messages partent pour de vrai.</p>
          <p>
            {{ s.account.verified_name || 'Numéro' }} · {{ s.account.display_phone }}
            <span v-if="s.account.quality_rating"> · qualité <strong :class="QUALITY[s.account.quality_rating]?.[1]">{{ QUALITY[s.account.quality_rating]?.[0] || s.account.quality_rating }}</strong></span>
            · modèles approuvés : <strong>{{ approvedCount }}/{{ s.templates.length }}</strong>
            · messages reçus : <strong>{{ s.account.webhook_subscribed_at ? 'branchés' : 'non branchés' }}</strong>
          </p>
        </template>
        <template v-else-if="s.mode === 'forced_simulation'">
          <p class="font-semibold">Mode simulation imposé par le serveur.</p>
          <p>Aucun message n'est envoyé (<code>WHATSAPP_DRIVER=log</code>). Pour les envois réels, mettez <code>WHATSAPP_DRIVER=auto</code> dans le fichier <code>.env</code>.</p>
        </template>
        <template v-else>
          <p class="font-semibold">Mode simulation : votre numéro n'est pas encore connecté.</p>
          <p>
            Les messages sont seulement inscrits dans la page <RouterLink to="/admin/messages" class="underline">Messages</RouterLink>.
            Renseignez votre numéro WhatsApp Business ci-dessous : dès que Meta le confirme, les envois deviennent réels.
          </p>
        </template>
      </div>

      <!-- Numéro -->
      <section class="card p-4 space-y-4">
        <div class="flex items-center justify-between gap-2">
          <h2 class="font-semibold">Numéro WhatsApp de l'entreprise</h2>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', ACCOUNT_STATUS[s.account.status]?.[1] || 'bg-slate-100 text-slate-700']">
            {{ ACCOUNT_STATUS[s.account.status]?.[0] || 'À configurer' }}
          </span>
        </div>

        <!-- Connexion en un clic (inscription intégrée de Meta) -->
        <div v-if="s.embedded_signup" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 space-y-3">
          <p v-if="s.account.status === 'connected'" class="text-sm text-emerald-900">
            Votre numéro est connecté. Pour en utiliser un autre, connectez-le à nouveau.
          </p>
          <p v-if="s.account.token_expires_at" class="rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">
            Meta accorde cet accès jusqu'au {{ new Date(s.account.token_expires_at).toLocaleDateString('fr-FR') }}.
            Avant cette date, cliquez sur le bouton ci-dessous et reprenez le même numéro pour le renouveler.
          </p>
          <template v-else>
            <p class="font-medium text-emerald-900">Branchez votre numéro WhatsApp en quelques minutes</p>
            <ol class="list-decimal space-y-1 pl-5 text-sm text-emerald-900">
              <li>La page de connexion s'ouvre : continuez avec votre compte Facebook.</li>
              <li>Choisissez ou créez votre entreprise et son compte WhatsApp Business.</li>
              <li>Ajoutez le numéro de l'entreprise et saisissez le code reçu par SMS.</li>
            </ol>
            <p class="text-xs text-emerald-800">
              Le numéro ne doit pas être utilisé dans l'application WhatsApp d'un téléphone.
              Les messages sont facturés par Meta sur votre compte : ajoutez-y un moyen de paiement.
            </p>
          </template>
          <p v-if="signupError" class="rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ signupError }}</p>
          <button type="button" :class="s.account.status === 'connected' ? 'btn-secondary' : 'btn-success'" :disabled="signingUp" @click="oneClick">
            {{ signingUp ? 'Connexion en cours…' : (s.account.status === 'connected' ? 'Connecter un autre numéro' : 'Connecter WhatsApp') }}
          </button>
        </div>

        <p v-if="s.account.last_error" class="rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ s.account.last_error }}</p>

        <!-- Saisie manuelle : sans bouton (serveur non configuré) ou pour les experts -->
        <details :open="!s.embedded_signup" class="group">
          <summary v-if="s.embedded_signup" class="cursor-pointer text-sm text-slate-600 hover:text-slate-900">Saisie manuelle (avancé)</summary>
          <form class="mt-3 space-y-4" @submit.prevent="saveAccount">
            <p class="text-sm text-slate-600">
              Ces informations se trouvent dans Meta for Developers, rubrique WhatsApp &gt; Configuration de l'API.
              Utilisez un jeton d'accès permanent (utilisateur système), pas le jeton temporaire de 24 h.
              À l'enregistrement, nous vérifions le numéro auprès de Meta et branchons les messages reçus.
            </p>
            <div class="grid sm:grid-cols-2 gap-3">
              <div><label class="label" for="wa-phone">Numéro affiché</label><input id="wa-phone" v-model="account.display_phone" class="input" placeholder="07 00 00 00 00"></div>
              <div><label class="label" for="wa-pnid">Identifiant du numéro (Phone number ID)</label><input id="wa-pnid" v-model="account.phone_number_id" class="input" inputmode="numeric"></div>
              <div><label class="label" for="wa-waba">Identifiant du compte WhatsApp Business (WABA ID)</label><input id="wa-waba" v-model="account.waba_id" class="input" inputmode="numeric"></div>
              <div>
                <label class="label" for="wa-token">Jeton d'accès</label>
                <input id="wa-token" v-model="account.access_token" type="password" autocomplete="off" class="input" :placeholder="s.account.has_access_token ? 'Enregistré (laisser vide pour le garder)' : ''">
              </div>
            </div>
            <p v-if="accountError" class="field-error">{{ accountError }}</p>
            <div class="flex flex-wrap gap-2">
              <button class="btn-primary" :disabled="saving">{{ saving ? 'Vérification auprès de Meta…' : 'Enregistrer et vérifier' }}</button>
              <button v-if="s.account.has_access_token" type="button" class="btn-secondary" :disabled="saving" @click="reconnect">Vérifier à nouveau</button>
            </div>
          </form>
        </details>
      </section>

      <!-- Test -->
      <form class="card p-4 space-y-3" @submit.prevent="sendTest">
        <h2 class="font-semibold">Message de test</h2>
        <p class="text-sm text-slate-600">Envoie le modèle « hello_world » de Meta, disponible sans approbation.</p>
        <div class="flex flex-wrap gap-2">
          <input v-model="testPhone" class="input w-56" placeholder="07 00 00 00 00" aria-label="Numéro de test" required>
          <button class="btn-secondary" :disabled="testing">Envoyer</button>
        </div>
        <p v-if="testResult" class="text-sm flex items-center gap-2">
          <MessageStatus :message="testResult" />
          <span v-if="testResult.error" class="text-red-700">{{ testResult.error }}</span>
        </p>
      </form>

      <!-- Webhook -->
      <div class="card p-4 space-y-2 text-sm">
        <h2 class="font-semibold">Accusés de réception (webhook)</h2>
        <p class="text-slate-600">Dans l'application Meta, rubrique WhatsApp &gt; Configuration, abonnez ce lien au champ <code>messages</code> :</p>
        <div class="flex gap-2">
          <input :value="s.webhook_url" readonly class="input font-mono text-xs" aria-label="Adresse du webhook">
          <button type="button" class="btn-secondary" @click="copy(s.webhook_url)">Copier</button>
        </div>
        <p :class="s.webhook_ready ? 'text-emerald-700' : 'text-amber-700'">
          {{ s.webhook_ready ? 'Secret de l\'application et jeton de vérification configurés.' : 'À compléter dans .env : WHATSAPP_APP_SECRET et WHATSAPP_VERIFY_TOKEN.' }}
        </p>
      </div>

      <!-- Modèles -->
      <div class="card p-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-semibold">Modèles de messages</h2>
          <div class="flex flex-wrap gap-2">
            <button v-if="s.mode === 'live' && approvedCount < s.templates.length" class="btn-primary" :disabled="submitting" @click="submitTemplates">
              {{ submitting ? 'Envoi à Meta…' : 'Soumettre les modèles à Meta' }}
            </button>
            <button class="btn-secondary" :disabled="syncing" @click="sync">Vérifier l'approbation chez Meta</button>
          </div>
        </div>
        <p class="text-sm text-slate-600">
          Hors conversation ouverte par le client, WhatsApp n'accepte que des modèles approuvés par Meta.
          Une fois le numéro connecté, « Soumettre les modèles à Meta » les crée tous pour vous (catégorie Utilitaire, Français).
          L'approbation prend de quelques minutes à 48 h ; revenez ensuite vérifier leur état.
        </p>
        <ul class="divide-y">
          <li v-for="t in s.templates" :key="t.name" class="py-3 space-y-1 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-mono font-medium">{{ t.name }}</span>
              <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', TEMPLATE_STATUS[t.status]?.[1] || 'bg-slate-100 text-slate-700']">
                {{ TEMPLATE_STATUS[t.status]?.[0] || t.status }}<template v-if="s.mode !== 'live' && t.status !== 'unknown'"> (simulation)</template>
              </span>
            </div>
            <p class="text-slate-600">{{ t.description }}</p>
            <div class="flex gap-2 items-start">
              <p class="flex-1 rounded bg-slate-50 p-2 font-mono text-xs">{{ t.body }}</p>
              <button type="button" class="btn-secondary text-xs" @click="copy(t.body)">Copier</button>
            </div>
            <p class="text-xs text-slate-600">Exemples : {{ t.example.join(' · ') }}</p>
            <p v-if="t.rejected_reason" class="text-xs text-red-700">Motif du refus : {{ t.rejected_reason }}</p>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>

<script setup>
import WhatsAppTabs from '../../components/WhatsAppTabs.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import MessageStatus from '../../components/MessageStatus.vue'
import { useToastStore } from '../../stores/toasts'

const TEMPLATE_STATUS = {
  approved: ['Approuvé', 'bg-emerald-100 text-emerald-800'],
  pending: ['En cours de validation', 'bg-amber-100 text-amber-800'],
  rejected: ['Refusé', 'bg-red-100 text-red-800'],
  paused: ['En pause', 'bg-amber-100 text-amber-800'],
  disabled: ['Désactivé', 'bg-red-100 text-red-800'],
  unknown: ['À créer chez Meta', 'bg-slate-100 text-slate-700'],
}

const ACCOUNT_STATUS = {
  connected: ['Connecté', 'bg-emerald-100 text-emerald-800'],
  error: ['Erreur de connexion', 'bg-red-100 text-red-700'],
  pending: ['À configurer', 'bg-slate-100 text-slate-700'],
}

// Note de qualité du numéro chez Meta
const QUALITY = {
  green: ['bonne', 'text-emerald-700'],
  yellow: ['moyenne', 'text-amber-700'],
  red: ['faible', 'text-red-700'],
}

const toasts = useToastStore()
const s = ref(null)
const loadError = ref('')
const account = reactive({ display_phone: '', phone_number_id: '', waba_id: '', access_token: '' })
const accountError = ref('')
const signupError = ref('')
const signingUp = ref(false)
const saving = ref(false)
const syncing = ref(false)
const submitting = ref(false)
const approvedCount = computed(() => (s.value?.templates || []).filter((t) => t.status === 'approved').length)
const testing = ref(false)
const testPhone = ref('')
const testResult = ref(null)

function apply(data) {
  s.value = data
  Object.assign(account, {
    display_phone: data.account.display_phone || '',
    phone_number_id: data.account.phone_number_id || '',
    waba_id: data.account.waba_id || '',
    access_token: '',
  })
}

async function load() {
  try {
    apply((await http.get('/whatsapp/settings')).data.data)
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  }
}

async function saveAccount() {
  saving.value = true
  accountError.value = ''
  try {
    const payload = Object.fromEntries(Object.entries(account).map(([k, v]) => [k, v || null]))
    apply((await http.put('/whatsapp/settings', { account: payload })).data.data)
    if (s.value.account.status === 'connected') toasts.success('Numéro connecté : les envois sont réels.')
    else if (s.value.account.status !== 'error') toasts.success('Numéro enregistré.')
  } catch (e) {
    accountError.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

// Bouton « Connecter WhatsApp » : la page de connexion s'ouvre dans le même onglet (adresse déclarée chez
// Facebook) ; elle revient ici à la fin, avec le résultat dans le fragment de l'adresse.
async function oneClick() {
  signupError.value = ''
  signingUp.value = true
  try {
    const { data } = await http.post('/whatsapp/signup-ticket')
    window.location.assign(data.data.url)
  } catch (e) {
    signupError.value = apiErrorMessage(e, 'Connexion à WhatsApp impossible.')
    signingUp.value = false
  }
}

// Retour de la page de connexion : #whatsapp=connecte, ou #whatsapp=erreur&message=…
function takeSignupResult() {
  const params = new URLSearchParams(window.location.hash.slice(1))
  if (!params.has('whatsapp')) return
  window.history.replaceState(null, '', window.location.pathname + window.location.search)
  if (params.get('whatsapp') === 'connecte') toasts.success('WhatsApp connecté : les modèles de messages sont envoyés à Meta pour approbation.')
  else if (params.get('message')) signupError.value = params.get('message')
}

async function reconnect() {
  saving.value = true
  accountError.value = ''
  try {
    apply((await http.post('/whatsapp/connect')).data.data)
    if (s.value.account.status === 'connected') toasts.success('Numéro connecté.')
  } catch (e) {
    accountError.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function submitTemplates() {
  submitting.value = true
  try {
    const { data } = await http.post('/whatsapp/templates/submit')
    apply(data.data)
    const errors = Object.entries(data.meta.errors || {})
    if (errors.length) toasts.error(`${errors.length} modèle(s) refusé(s) à l'envoi : ${errors.map(([name, error]) => `${name} (${error})`).join(' ; ')}`)
    else toasts.success(`${data.meta.submitted} modèle(s) envoyé(s) à Meta pour validation.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    submitting.value = false
  }
}

async function sendTest() {
  testing.value = true
  testResult.value = null
  try {
    const { data } = await http.post('/whatsapp/test', { to: testPhone.value })
    testResult.value = data.data
    followTest(data.data.id)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    testing.value = false
  }
}

// L'envoi passe par la file d'attente : on suit le statut quelques secondes
async function followTest(id, tries = 6) {
  if (tries === 0 || testResult.value?.id !== id || testResult.value.status !== 'queued') return
  await new Promise((resolve) => setTimeout(resolve, 1500))
  try {
    const { data } = await http.get('/messages', { params: { per_page: 20 } })
    const found = data.data.find((m) => m.id === id)
    if (found && testResult.value?.id === id) testResult.value = found
  } catch {
    return
  }
  followTest(id, tries - 1)
}

async function sync() {
  syncing.value = true
  try {
    apply((await http.post('/whatsapp/templates/sync')).data.data)
    toasts.success('État des modèles mis à jour.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    syncing.value = false
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le texte à la main.')
  }
}

onMounted(() => {
  takeSignupResult()
  load()
})
</script>
