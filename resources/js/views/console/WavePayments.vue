<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Paiements Wave</h1>

    <div class="card p-4 text-sm">
      <h2 class="mb-1 font-semibold">Paiements à valider</h2>
      <p class="mb-3 text-gray-600">Vérifiez dans Wave que le montant est arrivé depuis ce numéro, puis confirmez : le paiement est enregistré sur la facture. Vous êtes connecté : pas besoin du code.</p>
      <p v-if="loaded && !pending.length" class="text-gray-500">Aucun paiement en attente.</p>
      <ul class="divide-y">
        <li v-for="p in pending" :key="p.id" class="flex flex-wrap items-center gap-3 py-3">
          <div class="min-w-0 flex-1">
            <p class="font-medium">
              {{ money(p.amount) }} · {{ p.payer_phone }}
              <span v-if="p.claimed_at" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">Déclaré payé {{ dateTime(p.claimed_at) }}</span>
            </p>
            <p class="text-gray-600">
              <RouterLink :to="`/console/entreprises/${p.company.id}`" class="text-blue-600 hover:underline">{{ p.company.name }}</RouterLink>
              <template v-if="p.payer_name"> · {{ p.payer_name }}</template> ·
              <RouterLink :to="`/console/factures/${p.invoice.id}`" class="text-blue-600 hover:underline">{{ p.invoice.number }}</RouterLink> ({{ p.invoice.period }})
            </p>
            <p class="text-xs text-gray-500">Annoncé {{ dateTime(p.created_at) }}<template v-if="p.alerts.length"> · alerte {{ p.alerts.map((a) => CHANNELS[a.channel] ?? 'non envoyée').join(', ') }}</template></p>
          </div>
          <button class="btn-primary" :disabled="busy === p.id" @click="decide(p, 'received')">Reçu : enregistrer</button>
          <button class="btn-secondary" :disabled="busy === p.id" @click="decide(p, 'missing')">Non reçu</button>
        </li>
      </ul>
    </div>

    <form v-if="settings" class="card space-y-3 p-4 text-sm" @submit.prevent="save">
      <h2 class="font-semibold">Lien marchand Wave</h2>
      <p class="text-gray-600">Avec un lien ou un QR code, les entreprises voient « Payer avec Wave » sur leurs factures (Mon abonnement).</p>
      <div>
        <label class="label" for="w-link">Lien de paiement marchand Wave</label>
        <input id="w-link" v-model.trim="form.link" class="input" placeholder="https://pay.wave.com/m/…">
        <p class="mt-1 text-xs text-gray-500">Le montant est ajouté au lien. Mettez {montant} dans le lien pour choisir sa place.</p>
      </div>
      <div class="space-y-2">
        <p class="label">QR code Wave</p>
        <p class="text-xs text-gray-500">Affiché aux entreprises sur ordinateur : elles le scannent avec Wave sur leur téléphone.</p>
        <div class="flex flex-wrap items-center gap-4">
          <img v-if="settings.qr_url" :src="settings.qr_url" alt="QR code Wave" class="h-32 w-32 rounded object-contain ring-1 ring-slate-200">
          <label class="btn-secondary cursor-pointer">{{ settings.qr_url ? 'Remplacer' : 'Ajouter le QR code' }}<input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="uploadQr"></label>
          <button v-if="settings.qr_url" type="button" class="text-red-600 underline" @click="removeQr">Retirer</button>
        </div>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="label" for="w-email">E-mail du validateur</label><input id="w-email" v-model.trim="form.validator_email" type="email" class="input" placeholder="paiements@…"></div>
        <div><label class="label" for="w-phone">Téléphone du validateur</label><input id="w-phone" v-model.trim="form.validator_phone" type="tel" class="input" placeholder="07 00 00 00 00"></div>
      </div>
      <div class="max-w-xs">
        <label class="label" for="w-pin">Code de validation</label>
        <input id="w-pin" v-model="form.validator_pin" type="password" inputmode="numeric" autocomplete="new-password" class="input" :placeholder="settings.pin_set ? 'Déjà défini : laisser vide pour le garder' : 'Au moins 4 chiffres'">
        <p class="mt-1 text-xs text-gray-500">Demandé quand vous validez depuis le lien reçu par e-mail ou SMS.</p>
      </div>
      <button class="btn-primary">Enregistrer</button>
    </form>

    <div v-if="settings" class="card space-y-3 p-4 text-sm">
      <h2 class="font-semibold">Envoi des alertes</h2>
      <p class="text-gray-600">À chaque paiement, un e-mail part d'abord ; s'il ne part pas, un SMS. Le paiement apparaît aussi dans la cloche et sur cette page.</p>
      <ul class="space-y-1">
        <li>
          <span :class="settings.mail.source ? 'text-emerald-700' : 'text-amber-700'">{{ settings.mail.source ? '✓' : '✗' }} E-mail :</span>
          {{ settings.mail.source === 'console' ? `par ${settings.mail.host} (réglé ci-dessous)` : settings.mail.source === 'server' ? 'par le serveur' : 'aucun serveur d\'e-mail : les e-mails ne partent pas' }}
        </li>
        <li>
          <span :class="settings.sms_ready ? 'text-emerald-700' : 'text-amber-700'">{{ settings.sms_ready ? '✓' : '✗' }} SMS :</span>
          {{ settings.sms_ready ? 'le serveur envoie les SMS' : 'pas de fournisseur de SMS sur le serveur : les SMS ne partent pas' }}
        </li>
      </ul>
      <form class="space-y-3 rounded-lg bg-slate-50 p-3" @submit.prevent="saveMail">
        <p class="font-medium">Envoi des e-mails</p>
        <p class="text-xs text-gray-600">
          Avec Gmail : activez la validation en deux étapes de votre compte Google (myaccount.google.com, Sécurité), puis créez un
          « mot de passe d'application » (myaccount.google.com/apppasswords). Collez ce code de 16 lettres ici, pas votre mot de passe habituel.
        </p>
        <button type="button" class="btn-secondary" @click="Object.assign(mail, { host: 'smtp.gmail.com', port: 587 })">Utiliser Gmail</button>
        <div class="grid gap-3 sm:grid-cols-[1fr_6rem]">
          <div><label class="label" for="m-host">Serveur</label><input id="m-host" v-model.trim="mail.host" class="input" placeholder="smtp.gmail.com"></div>
          <div><label class="label" for="m-port">Port</label><input id="m-port" v-model.number="mail.port" type="number" class="input"></div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div><label class="label" for="m-user">Adresse Gmail (identifiant)</label><input id="m-user" v-model.trim="mail.username" type="email" class="input" autocomplete="off" placeholder="vous@gmail.com"></div>
          <div><label class="label" for="m-pass">Mot de passe d'application</label><input id="m-pass" v-model="mail.password" type="password" class="input" autocomplete="new-password" :placeholder="settings.mail.password_set ? 'Déjà enregistré : laisser vide pour le garder' : '16 lettres'"></div>
        </div>
        <button class="btn-primary">Enregistrer l'envoi des e-mails</button>
      </form>
      <button class="btn-secondary" :disabled="testing" @click="testAlert">{{ testing ? 'Envoi…' : 'Envoyer une alerte d\'essai' }}</button>
      <ul v-if="testResult" class="space-y-1">
        <li :class="testResult.mail.ok ? 'text-emerald-700' : 'text-red-600'">{{ testResult.mail.ok ? '✓' : '✗' }} E-mail : {{ testResult.mail.detail }}</li>
        <li :class="testResult.sms.ok ? 'text-emerald-700' : 'text-red-600'">{{ testResult.sms.ok ? '✓' : '✗' }} SMS : {{ testResult.sms.detail }}</li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'

const toasts = useToastStore()
const CHANNELS = { mail: 'e-mail', sms: 'SMS', journal: 'SMS non envoyé (pas de fournisseur)' }
const pending = ref([])
const loaded = ref(false)
const busy = ref(null)
const settings = ref(null)
const form = reactive({ link: '', validator_email: '', validator_phone: '', validator_pin: '' })
const mail = reactive({ host: '', port: 587, username: '', password: '' })
const testing = ref(false)
const testResult = ref(null)

const dateTime = (iso) => new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })

function fill(data) {
  settings.value = data
  Object.assign(form, { link: data.link ?? '', validator_email: data.validator_email ?? '', validator_phone: data.validator_phone ?? '', validator_pin: '' })
  Object.assign(mail, { host: data.mail.host ?? '', port: Number(data.mail.port ?? 587), username: data.mail.username ?? '', password: '' })
}

async function loadPending() {
  pending.value = (await http.get('/console/wave/payments')).data.data
  loaded.value = true
}

async function decide(p, decision) {
  const text = decision === 'received'
    ? `Confirmer la réception de ${money(p.amount)} depuis le ${p.payer_phone} ? Le paiement sera enregistré sur la facture ${p.invoice.number}.`
    : `Marquer le paiement de ${money(p.amount)} comme non reçu ?`
  if (!window.confirm(text)) return
  busy.value = p.id
  try {
    await http.post(`/console/wave/payments/${p.id}/decide`, { decision })
    toasts.success(decision === 'received' ? 'Paiement enregistré sur la facture.' : 'Paiement marqué non reçu.')
    await loadPending()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = null
  }
}

async function save() {
  try {
    fill((await http.put('/console/wave', form)).data.data)
    toasts.success('Réglages Wave enregistrés.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function saveMail() {
  try {
    fill((await http.put('/console/wave/mail', mail)).data.data)
    toasts.success('Envoi des e-mails enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function uploadQr(event) {
  const file = event.target.files?.[0]
  if (!file) return
  const data = new FormData()
  data.append('image', file)
  try {
    fill((await http.post('/console/wave/qr', data)).data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    event.target.value = ''
  }
}

async function removeQr() {
  if (!window.confirm('Retirer le QR code Wave ?')) return
  fill((await http.delete('/console/wave/qr')).data.data)
}

async function testAlert() {
  testing.value = true
  testResult.value = null
  try {
    testResult.value = (await http.post('/console/wave/test-alert')).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    testing.value = false
  }
}

onMounted(async () => {
  fill((await http.get('/console/wave')).data.data)
  await loadPending()
})
</script>
