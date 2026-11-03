<template>
  <div class="space-y-4">
    <!-- Alertes du matin (cloche des administrateurs, WhatsApp au choix) -->
    <section v-if="canManage" class="card space-y-3 p-4">
      <div>
        <h2 class="font-semibold">🔔 Alertes du matin</h2>
        <p class="text-sm text-slate-500">Chaque matin à 7 h 35, les administrateurs sont prévenus quand un indicateur passe un seuil (une même alerte au plus une fois par semaine). Laissez vide pour ne pas être alerté.</p>
      </div>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label class="label" for="alert-zone">Zone dont la réussite passe sous (%)</label>
          <input id="alert-zone" v-model="alerts.zone_success_min" type="number" min="0" max="100" class="input" placeholder="Pas d'alerte">
        </div>
        <div>
          <label class="label" for="alert-client">Client en baisse de plus de (%)</label>
          <input id="alert-client" v-model="alerts.client_drop_percent" type="number" min="1" max="100" class="input" placeholder="Pas d'alerte">
        </div>
        <div>
          <label class="label" for="alert-volume">Seulement à partir de (courses)</label>
          <input id="alert-volume" v-model.number="alerts.min_volume" type="number" min="1" class="input">
        </div>
        <div class="space-y-2 text-sm sm:pt-6">
          <label class="flex items-center gap-2"><input v-model="alerts.courier_failures_double" type="checkbox"> Livreur dont les échecs doublent</label>
          <label class="flex items-center gap-2"><input v-model="alerts.whatsapp" type="checkbox"> Aussi sur WhatsApp</label>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button type="button" class="btn-primary" :disabled="busy" @click="saveAlerts">Enregistrer</button>
        <span v-if="alertsMessage" class="text-sm text-slate-600">{{ alertsMessage }}</span>
      </div>
    </section>

    <!-- Rapports mensuels des clients : relus puis envoyés -->
    <section v-if="auth.can('merchants.view')" class="card space-y-3 p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="font-semibold">📑 Rapports mensuels des clients sous contrat</h2>
          <p class="text-sm text-slate-500">Préparés le 1er du mois pour le mois écoulé. Relisez, ajoutez un mot si besoin, puis envoyez : le client les retrouve dans son espace.</p>
        </div>
        <div class="flex items-center gap-2">
          <input v-model="month" type="month" class="input w-auto" aria-label="Mois" @change="loadReports">
          <button v-if="canManageReports" type="button" class="btn-secondary" :disabled="busy" @click="generate">↻ Préparer</button>
        </div>
      </div>
      <p v-if="!reports.length" class="text-sm text-slate-500">Aucun rapport pour ce mois.</p>
      <ul v-else class="divide-y text-sm">
        <li v-for="r in reports" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
          <span class="min-w-[12rem] flex-1"><b>{{ r.merchant.name }}</b> <span class="text-slate-500">· {{ r.summary.delivered }} livrée(s){{ r.summary.on_time_rate === null ? '' : ` · ${r.summary.on_time_rate} % dans le délai` }}</span></span>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', r.status === 'sent' ? 'bg-emerald-100 text-emerald-900' : 'bg-amber-100 text-amber-900']">{{ r.status === 'sent' ? `Envoyé le ${date(r.sent_at)}` : 'À relire' }}</span>
          <button type="button" class="text-blue-600" @click="open(r)">{{ r.status === 'sent' ? 'Voir' : 'Relire' }}</button>
        </li>
      </ul>
    </section>

    <!-- Lien Google Sheets -->
    <section v-if="canManage" class="card space-y-3 p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="font-semibold">📊 Google Sheets</h2>
          <p class="text-sm text-slate-500">
            Collez une formule dans une cellule de Google Sheets : le tableau se remplit et se met à jour tout seul (environ toutes les heures).
            Ajoutez <code>?jours=90</code> à l'adresse pour une autre période (30 jours par défaut).
          </p>
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn-primary" @click="regenerate">{{ feed?.enabled ? '↻ Nouveau lien' : 'Créer le lien' }}</button>
          <button v-if="feed?.enabled" type="button" class="btn-secondary text-red-700" @click="disable">Couper le lien</button>
        </div>
      </div>
      <p v-if="feed?.enabled" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Ce lien donne accès aux chiffres sans mot de passe : ne le partagez qu'avec les personnes de confiance. « Nouveau lien » rend l'ancien inutilisable.</p>
      <ul v-if="feed?.enabled" class="divide-y text-sm">
        <li v-for="f in feed.feeds" :key="f.key" class="flex flex-wrap items-center gap-2 py-2">
          <span class="w-56 font-medium">{{ f.label }}</span>
          <code class="min-w-0 flex-1 truncate rounded bg-slate-50 px-2 py-1 text-xs">{{ f.formula }}</code>
          <button type="button" class="btn-secondary py-1" @click="copy(f.formula)">Copier</button>
        </li>
      </ul>
    </section>

    <!-- Point par e-mail -->
    <section v-if="canManage" class="card space-y-3 p-4">
      <h2 class="font-semibold">✉️ Point par e-mail</h2>
      <p class="text-sm text-slate-500">Chiffres clés et fichier Excel complet en pièce jointe, aux adresses choisies.</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <div>
          <label class="label" for="mail-to">Destinataires (une adresse par ligne, 10 au plus)</label>
          <textarea id="mail-to" v-model="recipients" rows="3" class="input" placeholder="gerant@exemple.ci" />
        </div>
        <div class="space-y-2 text-sm sm:pt-6">
          <label class="flex items-center gap-2"><input v-model="email.weekly" type="checkbox"> Chaque lundi : la semaine écoulée</label>
          <label class="flex items-center gap-2"><input v-model="email.monthly" type="checkbox"> Le 1er du mois : le mois écoulé</label>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn-primary" :disabled="busy" @click="saveEmail">Enregistrer</button>
        <button type="button" class="btn-secondary" :disabled="busy" @click="testEmail">Envoyer un essai maintenant</button>
        <span v-if="message" class="text-sm text-slate-600">{{ message }}</span>
      </div>
    </section>

    <!-- Relecture d'un rapport -->
    <BottomSheet :open="!!current" :title="current ? `Rapport ${current.merchant.name}` : ''" @close="current = null">
      <div v-if="current" class="space-y-3">
        <ClientReportView :report="current" show-status />
        <template v-if="current.status !== 'sent' && canManageReports">
          <label class="label" for="report-note">Mot pour le client (facultatif)</label>
          <textarea id="report-note" v-model="current.note" rows="2" class="input" placeholder="Merci pour votre confiance…" />
          <div class="flex gap-2">
            <button type="button" class="btn-primary" :disabled="busy" @click="send">Envoyer au client</button>
            <button type="button" class="btn-secondary" @click="current = null">Plus tard</button>
          </div>
        </template>
      </div>
    </BottomSheet>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date } from '../../utils/format'
import BottomSheet from '../mobile/BottomSheet.vue'
import ClientReportView from './ClientReportView.vue'

const auth = useAuthStore()
const toasts = useToastStore()
const canManage = computed(() => auth.can('settings.manage'))
const canManageReports = computed(() => auth.can('invoices.manage'))
const busy = ref(false)
const message = ref('')

// Mois dernier par défaut
const last = new Date()
last.setDate(1)
last.setMonth(last.getMonth() - 1)
const month = ref(`${last.getFullYear()}-${String(last.getMonth() + 1).padStart(2, '0')}`)
const reports = ref([])
const current = ref(null)

async function loadReports() {
  if (!auth.can('merchants.view')) return
  reports.value = (await http.get('/client-reports', { params: { period: month.value } })).data.data
}
async function generate() {
  busy.value = true
  try {
    const count = (await http.post('/client-reports/generate', { month: month.value })).data.data.count
    toasts.success(`${count} rapport(s) préparé(s).`)
    await loadReports()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}
async function open(r) {
  current.value = (await http.get(`/client-reports/${r.id}`)).data.data
}
async function send() {
  busy.value = true
  try {
    await http.post(`/client-reports/${current.value.id}/send`, { note: current.value.note || null })
    toasts.success('Rapport envoyé au client.')
    current.value = null
    await loadReports()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

// Google Sheets
const feed = ref(null)
const loadFeed = async () => { feed.value = (await http.get('/stats/feed-link')).data.data }
async function regenerate() {
  if (feed.value?.enabled && !window.confirm('Créer un nouveau lien ? L\'ancien ne marchera plus dans vos feuilles.')) return
  feed.value = (await http.post('/stats/feed-link')).data.data
}
async function disable() {
  if (!window.confirm('Couper le lien ? Les feuilles Google Sheets ne se mettront plus à jour.')) return
  feed.value = (await http.delete('/stats/feed-link')).data.data
}
async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Formule copiée : collez-la dans une cellule de Google Sheets.')
  } catch {
    window.prompt('Copiez la formule :', text)
  }
}

// Alertes du matin
const alerts = reactive({})
const alertsMessage = ref('')
async function saveAlerts() {
  busy.value = true
  alertsMessage.value = ''
  try {
    const blank = (v) => (v === '' || v === undefined ? null : v)
    await http.patch(`/companies/${auth.user.company_id}`, { stats_settings: { ...settings, alerts: { ...alerts, zone_success_min: blank(alerts.zone_success_min), client_drop_percent: blank(alerts.client_drop_percent) } } })
    alertsMessage.value = '✓ Enregistré'
    await loadEmail()
  } catch (e) {
    alertsMessage.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

// E-mail
const email = reactive({ weekly: false, monthly: false })
const recipients = ref('')
let settings = null
async function loadEmail() {
  settings = (await http.get(`/companies/${auth.user.company_id}`)).data.data.stats_settings
  Object.assign(email, { weekly: settings.email.weekly, monthly: settings.email.monthly })
  Object.assign(alerts, settings.alerts)
  recipients.value = settings.email.recipients.join('\n')
}
async function saveEmail() {
  busy.value = true
  message.value = ''
  try {
    const list = recipients.value.split(/[\s,;]+/).map((v) => v.trim()).filter(Boolean)
    await http.patch(`/companies/${auth.user.company_id}`, { stats_settings: { ...settings, email: { recipients: list, weekly: email.weekly, monthly: email.monthly } } })
    message.value = '✓ Enregistré'
    await loadEmail()
  } catch (e) {
    message.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
async function testEmail() {
  busy.value = true
  message.value = ''
  try {
    await http.post('/stats/test-email', { frequency: 'weekly' })
    message.value = '✓ E-mail d\'essai envoyé (semaine écoulée)'
  } catch (e) {
    message.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  loadReports().catch(() => {})
  if (canManage.value) {
    loadFeed().catch(() => {})
    loadEmail().catch(() => {})
  }
})
</script>
