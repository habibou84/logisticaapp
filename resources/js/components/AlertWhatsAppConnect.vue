<template>
  <section v-if="state" class="card space-y-3 p-4 text-sm">
    <div class="space-y-1">
      <h2 class="flex items-center gap-1.5 text-base font-semibold"><Icone nom="notifications" />WhatsApp des alertes administrateurs</h2>
      <p class="text-slate-600">
        Un second numéro WhatsApp, relié en scannant un QR code, réservé à l'administration : il envoie seulement les alertes
        destinées aux administrateurs (zones dont la réussite baisse, clients en baisse, livreurs dont les échecs doublent…).
        Il n'écrit jamais aux clients ni aux destinataires : eux reçoivent toujours les messages du numéro de l'entreprise ci-dessus.
      </p>
    </div>

    <div class="rounded-lg bg-amber-50 p-3 text-amber-900">
      <p class="font-semibold">Quel numéro utiliser ?</p>
      <ul class="list-disc space-y-0.5 pl-5">
        <li>Un numéro à part, dédié aux alertes : jamais celui de l'entreprise ni celui des clients.</li>
        <li>Un numéro déjà utilisé normalement quelques jours sur un téléphone (pas une puce toute neuve).</li>
        <li>Enregistré dans les contacts des administrateurs, qui lui répondent au moins une fois.</li>
        <li>Gardez ce téléphone allumé avec WhatsApp et Internet : resté éteint environ 14 jours, WhatsApp délie la connexion.</li>
      </ul>
      <p class="mt-1 text-xs">
        Connexion non officielle : WhatsApp peut la couper. Si c'est le cas, les administrateurs sont prévenus dans la cloche et par e-mail,
        et les alertes restent visibles dans l'application.
      </p>
    </div>

    <p v-if="!state.available" class="text-slate-600">
      Pas encore installé sur le serveur : les alertes partent par le numéro de l'entreprise.
    </p>

    <!-- Relié -->
    <template v-else-if="state.status === 'connected'">
      <p class="flex flex-wrap items-center gap-1.5">
        <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true" />
        <span>Relié<template v-if="state.phone"> : <b>{{ state.phone }}</b></template><template v-if="state.name"> ({{ state.name }})</template>. Les alertes des administrateurs partent par ce numéro.</span>
      </p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" :disabled="busy" @click="test">M'envoyer un essai</button>
        <button type="button" class="btn-secondary text-red-700" :disabled="busy" @click="disconnect">Délier ce numéro</button>
      </div>
    </template>

    <!-- QR code à scanner -->
    <template v-else-if="state.status === 'scanning'">
      <p class="font-medium">Scannez ce QR code avec le téléphone du numéro des alertes</p>
      <p class="text-slate-600">Sur ce téléphone : WhatsApp › Réglages › Appareils connectés › Connecter un appareil.</p>
      <img v-if="state.qr" :src="`data:image/png;base64,${state.qr}`" alt="QR code WhatsApp" class="h-56 w-56 rounded bg-white p-2">
      <p v-else class="text-slate-600">Préparation du QR code…</p>
      <button type="button" class="text-blue-600" :disabled="busy" @click="disconnect">Annuler</button>
    </template>

    <!-- Pas relié -->
    <template v-else>
      <p>Pas de numéro relié : les alertes des administrateurs partent par le numéro de l'entreprise.</p>
      <button type="button" class="btn-primary" :disabled="busy" @click="connect">Relier par QR code</button>
    </template>

    <p v-if="state.error" class="text-red-700">{{ state.error }}</p>
  </section>
</template>

<script setup>
import Icone from './ui/Icone.vue'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { confirmDialog } from '../composables/useDialog'

/**
 * WhatsApp › Connexion : numéro dédié aux alertes des administrateurs, relié en scannant un QR code.
 * Le QR code se renouvelle tout seul jusqu'au scan.
 */
const toasts = useToastStore()
const state = ref(null)
const busy = ref(false)
let timer = null

async function load() {
  try {
    const { data } = await http.get('/alert-whatsapp')
    state.value = data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function run(request, success) {
  busy.value = true
  try {
    const { data } = await request()
    if (data.data?.status !== undefined && data.data?.available !== undefined) state.value = data.data
    if (success) toasts.success(success)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

const connect = () => run(() => http.post('/alert-whatsapp/connect'))
const test = () => run(() => http.post('/alert-whatsapp/test'), 'Essai envoyé sur votre WhatsApp.')

async function disconnect() {
  if (state.value.status === 'connected' && !(await confirmDialog('Délier ce numéro ? Les alertes des administrateurs repartiront par le numéro de l\'entreprise.'))) return
  await run(() => http.delete('/alert-whatsapp'))
}

// Pendant le scan : état et QR code relus toutes les 3 secondes
watch(() => state.value?.status, (status) => {
  clearInterval(timer)
  timer = status === 'scanning' ? setInterval(load, 3000) : null
})

onMounted(load)
onBeforeUnmount(() => clearInterval(timer))
</script>
