<template>
  <div class="space-y-5">
    <div class="text-center space-y-1">
      <p class="text-3xl" aria-hidden="true">📱</p>
      <h2 class="text-xl font-bold">Vérifiez votre numéro</h2>
      <p class="text-sm text-slate-600">{{ state.phone }} · <button type="button" class="font-semibold underline" @click="$emit('restart')">modifier</button></p>
    </div>

    <div v-if="state.status === 'expired'" class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800 space-y-2" role="alert">
      <p>Cette demande a expiré.</p>
      <button type="button" class="font-semibold underline" @click="$emit('restart')">Recommencer</button>
    </div>

    <div v-else-if="state.status === 'failed'" class="rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700" role="alert">
      {{ state.message }}
    </div>

    <template v-else>
      <!-- WhatsApp : la personne envoie le message prérempli depuis son téléphone -->
      <div v-if="state.whatsapp && !smsMode" class="space-y-3">
        <ol class="text-sm text-slate-700 space-y-1 list-decimal list-inside">
          <li>Appuyez sur le bouton : WhatsApp s'ouvre avec un message déjà écrit.</li>
          <li>Envoyez-le <strong>depuis le WhatsApp du {{ state.phone }}</strong>, sans le modifier.</li>
          <li>Revenez ici : la page continue toute seule.</li>
        </ol>
        <a :href="state.whatsapp.link" target="_blank" rel="noopener" class="flex items-center justify-center gap-2 w-full rounded-xl bg-[#25D366] hover:bg-[#1ebe5b] py-3 font-semibold text-white shadow-sm">
          <span aria-hidden="true">💬</span> Envoyer le message WhatsApp
        </a>
        <p class="flex items-center justify-center gap-2 text-sm text-slate-500" aria-live="polite">
          <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" aria-hidden="true"></span> En attente de votre message…
        </p>
        <p class="text-xs text-slate-500 text-center">
          Le bouton ne marche pas ? Envoyez « {{ state.whatsapp.text }} » au {{ state.whatsapp.number }} sur WhatsApp.
        </p>
        <button v-if="state.sms.available" type="button" class="block mx-auto text-sm text-slate-600 underline" @click="smsMode = true">
          Pas de WhatsApp sur ce numéro ? Recevoir un code par SMS
        </button>
      </div>

      <!-- SMS : code secret saisi sur la page -->
      <div v-else-if="state.sms.available" class="space-y-3">
        <p v-if="!state.sms.sent" class="text-sm text-slate-700 text-center">Nous allons envoyer un code à 6 chiffres par SMS au {{ state.phone }}.</p>
        <button v-if="!state.sms.sent" type="button" :disabled="busy" :class="['w-full rounded-xl py-3 font-semibold text-white shadow-sm disabled:opacity-60', buttonClass]" @click="sendSms">
          {{ busy ? 'Envoi…' : 'Recevoir le code par SMS' }}
        </button>

        <form v-else class="space-y-3" @submit.prevent="checkCode">
          <label class="block">
            <span class="text-sm font-medium text-slate-700">Code reçu par SMS</span>
            <input v-model.trim="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required placeholder="000000"
              class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 text-center text-2xl tracking-[0.5em] focus:outline-none focus:ring-2 focus:ring-emerald-500">
          </label>
          <button type="submit" :disabled="busy || code.length !== 6" :class="['w-full rounded-xl py-3 font-semibold text-white shadow-sm disabled:opacity-60', buttonClass]">
            {{ busy ? 'Vérification…' : 'Valider' }}
          </button>
          <p class="text-center text-sm text-slate-600">
            <span v-if="wait > 0">Nouveau code possible dans {{ wait }} s</span>
            <button v-else type="button" class="underline" :disabled="busy" @click="sendSms">Renvoyer le code</button>
          </p>
        </form>

        <p v-if="demoCode" class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800 text-center">
          Mode démonstration (aucun SMS réel configuré) : votre code est <strong>{{ demoCode }}</strong>.
        </p>
        <button v-if="state.whatsapp" type="button" class="block mx-auto text-sm text-slate-600 underline" @click="smsMode = false">
          Vérifier plutôt par WhatsApp
        </button>
      </div>

      <p v-else class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
        La vérification de ce numéro n'est pas disponible pour le moment. Contactez-nous.
      </p>

      <p v-if="error" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2" role="alert">{{ error }}</p>
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'

const props = defineProps({
  token: { type: String, required: true },
  initial: { type: Object, required: true },
  tone: { type: String, default: 'merchant' },
})
const emit = defineEmits(['done', 'restart', 'update'])

const state = ref(props.initial)
const smsMode = ref(!props.initial.whatsapp)
const code = ref('')
const busy = ref(false)
const error = ref('')
const demoCode = ref(null)
const now = ref(Date.now())
const smsSentAt = ref(props.initial.sms.wait_seconds ? Date.now() - (60 - props.initial.sms.wait_seconds) * 1000 : null)

const buttonClass = computed(() => (props.tone === 'merchant' ? 'bg-emerald-600 hover:bg-emerald-700' : props.tone === 'courier' ? 'bg-blue-700 hover:bg-blue-800' : 'bg-slate-900 hover:bg-slate-700'))
const wait = computed(() => (smsSentAt.value ? Math.max(0, 60 - Math.floor((now.value - smsSentAt.value) / 1000)) : 0))

function apply(response) {
  state.value = response.data
  emit('update', response.data)
  if (response.session || ['verified', 'completed'].includes(response.data.status)) {
    stop()
    emit('done', response)
  }
}

async function sendSms() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/verifications/${props.token}/sms`)
    demoCode.value = data.demo_code
    smsSentAt.value = Date.now()
    apply(data)
  } catch (e) {
    error.value = apiErrorMessage(e, "Le code n'a pas pu être envoyé.")
  } finally {
    busy.value = false
  }
}

async function checkCode() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/verifications/${props.token}/code`, { code: code.value })
    apply(data)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Code non vérifié.')
  } finally {
    busy.value = false
  }
}

// Le message WhatsApp arrive sur le serveur : la page interroge l'état régulièrement
let poller = null
let ticker = null

async function poll() {
  if (document.hidden || busy.value || state.value.status !== 'pending') return
  try {
    const { data } = await http.get(`/verifications/${props.token}`)
    apply(data)
  } catch (e) {
    if (e.response?.status === 404) {
      stop()
      emit('restart')
    }
  }
}

function stop() {
  clearInterval(poller)
  clearInterval(ticker)
  document.removeEventListener('visibilitychange', poll)
}

onMounted(() => {
  poller = setInterval(poll, 3000)
  ticker = setInterval(() => { now.value = Date.now() }, 1000)
  // Retour depuis WhatsApp : vérification immédiate
  document.addEventListener('visibilitychange', poll)
  poll()
})
onBeforeUnmount(stop)
</script>
