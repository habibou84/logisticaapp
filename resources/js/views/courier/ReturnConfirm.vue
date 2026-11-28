<template>
  <div v-if="handover" class="space-y-4">
    <section class="m-card p-4 space-y-1">
      <p class="flex items-center gap-2 text-lg font-semibold"><Icone nom="clients" />{{ handover.merchant.business_name }}</p>
      <p class="text-sm text-slate-600">{{ handover.orders.length }} colis à rendre</p>
    </section>

    <!-- Confirmée -->
    <template v-if="handover.status === 'confirmed' || handover.status === 'resolved'">
      <section class="rounded-2xl bg-emerald-50 p-4 text-emerald-900 space-y-1">
        <p class="flex items-center gap-2 text-lg font-bold"><Icone nom="valider" />Remise confirmée</p>
        <p class="text-sm">{{ handover.merchant.business_name }} a confirmé {{ VIA[handover.confirmed_via] || '' }}<template v-if="handover.confirmed_at"> à {{ time(handover.confirmed_at) }}</template>.</p>
      </section>
      <OrderList :orders="handover.orders" />
      <RouterLink to="/livreur/itineraire" class="m-btn-primary inline-flex items-center justify-center gap-2"><Icone nom="itineraire" />Arrêt suivant de mon itinéraire</RouterLink>
    </template>

    <!-- Remise de secours faite -->
    <template v-else-if="handover.status === 'unconfirmed'">
      <section class="rounded-2xl bg-amber-50 p-4 text-amber-900 space-y-1">
        <p class="flex items-center gap-2 text-lg font-bold"><Icone nom="attente" />Remise enregistrée sans confirmation</p>
        <p class="text-sm">Colis remis à {{ handover.received_by_name }} ({{ handover.fallback_label }}). L'agence est prévenue et vérifiera.</p>
      </section>
      <OrderList :orders="handover.orders" />
      <RouterLink to="/livreur/itineraire" class="m-btn-primary inline-flex items-center justify-center gap-2"><Icone nom="itineraire" />Arrêt suivant de mon itinéraire</RouterLink>
    </template>

    <!-- Contestée -->
    <template v-else-if="handover.status === 'disputed'">
      <section class="rounded-2xl bg-red-50 p-4 text-red-900 space-y-1">
        <p class="flex items-center gap-2 text-lg font-bold"><Icone nom="urgent" />Le client signale qu'il manque des colis</p>
        <p class="text-sm">Gardez les colis avec vous. L'agence est prévenue et va vous appeler.</p>
      </section>
      <OrderList :orders="handover.orders" />
    </template>

    <p v-else-if="handover.status === 'cancelled'" class="rounded-2xl bg-slate-100 p-4 text-slate-700">Remise annulée : les colis restent à rendre.</p>

    <!-- Remise de secours : formulaire -->
    <form v-else-if="fallbackOpen" class="space-y-4" @submit.prevent="sendFallback">
      <h2 class="font-semibold">Remise sans confirmation</h2>
      <fieldset class="space-y-2">
        <legend class="m-label">Pourquoi le client ne peut pas confirmer ? *</legend>
        <label v-for="(label, key) in REASONS" :key="key" :class="['flex items-center gap-3 rounded-xl border p-3', form.reason === key ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200']">
          <input v-model="form.reason" type="radio" :value="key" class="h-5 w-5">{{ label }}
        </label>
      </fieldset>
      <div>
        <label class="m-label" for="rc-name">Nom de la personne qui reçoit *</label>
        <input id="rc-name" v-model="form.received_by_name" class="m-input" required maxlength="120" autocomplete="off">
      </div>
      <div>
        <p class="m-label">Signature</p>
        <SignaturePad v-model="form.signature" />
      </div>
      <div>
        <label class="m-label" for="rc-photo">Ou photo de la remise</label>
        <input id="rc-photo" type="file" accept="image/*" capture="environment" class="block w-full text-sm" @change="form.photo = $event.target.files[0] || null">
      </div>
      <p class="text-sm text-slate-600">L'agence sera prévenue et vérifiera cette remise.</p>
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <button class="m-btn-primary" :disabled="busy || !form.reason || !form.received_by_name || (!form.signature && !form.photo)">
        <span class="inline-flex items-center justify-center gap-2"><Icone nom="valider" />Valider la remise de {{ handover.orders.length }} colis</span>
      </button>
      <button type="button" class="m-btn-secondary" @click="fallbackOpen = false">Revenir au QR code</button>
    </form>

    <!-- En attente : QR code à faire scanner -->
    <template v-else>
      <section class="m-card p-4 space-y-3 text-center">
        <template v-if="handover.whatsapp_link && handover.status === 'pending'">
          <p class="font-semibold">Faites scanner ce QR code au client</p>
          <img v-if="qr" :src="qr" alt="QR code de confirmation de la remise" class="mx-auto h-60 w-60 rounded-xl bg-white p-2">
          <p class="text-sm text-slate-600">WhatsApp s'ouvre avec le message déjà écrit : il n'a qu'à toucher Envoyer.</p>
          <p class="text-sm">Référence : <strong>{{ handover.reference }}</strong> · valable jusqu'à {{ time(handover.expires_at) }}</p>
        </template>
        <template v-else-if="handover.status === 'expired'">
          <p class="font-semibold">Ce QR code a expiré</p>
          <button type="button" class="m-btn-primary" :disabled="busy" @click="renew">
            <span class="inline-flex items-center justify-center gap-2"><Icone nom="actualiser" />Nouveau QR code</span>
          </button>
        </template>
        <p v-else class="text-sm text-slate-700">Demandez au client d'écrire <strong>retour</strong> au WhatsApp de l'agence, ou de confirmer dans son application.</p>
        <p class="flex items-center justify-center gap-2 text-sm text-slate-600"><span class="h-2 w-2 animate-pulse rounded-full bg-emerald-500" />En attente de la confirmation du client…</p>
      </section>
      <OrderList :orders="handover.orders" />
      <p class="text-sm text-slate-600">Le client n'a pas son téléphone ? Il peut aussi écrire « retour » au WhatsApp de l'agence, ou confirmer dans son application.</p>
      <button type="button" class="m-btn-secondary" @click="fallbackOpen = true">
        <span class="inline-flex items-center justify-center gap-2"><Icone nom="ecrire" />Il ne peut pas confirmer</span>
      </button>
    </template>
  </div>
  <p v-else-if="error" class="text-center text-red-600 py-8">{{ error }}</p>
  <p v-else class="text-center text-slate-600 py-10">Chargement…</p>
</template>

<script setup>
import QRCode from 'qrcode'
import { defineComponent, h, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Icone from '../../components/ui/Icone.vue'
import SignaturePad from '../../components/mobile/SignaturePad.vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { currentPosition } from '../../composables/useGeolocation'
import { useToastStore } from '../../stores/toasts'

const REASONS = { no_phone: 'Pas de téléphone ou pas de réseau', employee: 'Remis à un employé sans WhatsApp', other: 'Autre' }
const VIA = { whatsapp: 'par WhatsApp', whatsapp_word: 'par WhatsApp', app: 'dans son application' }

// Liste des colis de la remise
const OrderList = defineComponent({
  props: { orders: { type: Array, required: true } },
  setup: (props) => () => h('div', { class: 'm-card divide-y' }, props.orders.map((o) => h('div', { key: o.id, class: 'flex items-center gap-3 p-4' }, [
    h('span', { class: 'flex-1 min-w-0' }, [
      h('span', { class: 'block font-medium truncate' }, o.recipient_name || o.tracking_code),
      h('span', { class: 'block text-xs text-slate-600' }, `${o.tracking_code} · ${o.status_label}`),
    ]),
  ]))),
})

const route = useRoute()
const toasts = useToastStore()
const handover = ref(null)
const qr = ref('')
const error = ref('')
const busy = ref(false)
const fallbackOpen = ref(false)
const form = reactive({ reason: null, received_by_name: '', signature: null, photo: null })
let timer = null

const time = (iso) => (iso ? new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '')

watch(() => handover.value?.whatsapp_link, async (link) => {
  qr.value = link ? await QRCode.toDataURL(link, { width: 480, margin: 1, errorCorrectionLevel: 'M' }) : ''
})

async function start() {
  try {
    handover.value = (await http.post('/courier/return-handovers', { merchant_id: Number(route.params.merchant) })).data.data
    timer = setInterval(refresh, 3000)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

// Attente du message du client : l'écran passe au vert dès qu'il arrive
async function refresh() {
  if (!handover.value || document.hidden) return
  try {
    const data = (await http.get(`/courier/return-handovers/${handover.value.id}`)).data.data
    if (handover.value.status !== 'confirmed' && data.status === 'confirmed') toasts.success('Remise confirmée par le client.')
    handover.value = data
    if (!['pending', 'expired'].includes(data.status)) clearInterval(timer)
  } catch {
    // Réseau coupé : nouvel essai au prochain tour
  }
}

async function renew() {
  busy.value = true
  try {
    handover.value = (await http.post(`/courier/return-handovers/${handover.value.id}/renew`)).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function sendFallback() {
  busy.value = true
  error.value = ''
  try {
    const body = new FormData()
    body.append('reason', form.reason)
    body.append('received_by_name', form.received_by_name)
    if (form.signature) body.append('signature', form.signature)
    if (form.photo) body.append('photo', form.photo)
    const position = await currentPosition().catch(() => null)
    if (position) Object.entries(position).forEach(([k, v]) => body.append(k, v))
    handover.value = (await http.post(`/courier/return-handovers/${handover.value.id}/fallback`, body)).data.data
    fallbackOpen.value = false
    clearInterval(timer)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(start)
onBeforeUnmount(() => clearInterval(timer))
</script>
