<template>
  <div v-if="state" class="space-y-2 rounded-lg border border-slate-200 p-3 text-sm">
    <p v-if="!state.available" class="text-slate-600">
      Le WhatsApp des alertes n'est pas installé sur le serveur : les alertes partent par le WhatsApp de l'entreprise.
    </p>

    <!-- Relié -->
    <template v-else-if="state.status === 'connected'">
      <p class="flex flex-wrap items-center gap-1.5">
        <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true" />
        <span>WhatsApp des alertes relié<template v-if="state.phone"> : <b>{{ state.phone }}</b></template><template v-if="state.name"> ({{ state.name }})</template>.</span>
      </p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" :disabled="busy" @click="test">Envoyer un essai</button>
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
      <p>
        Reliez un numéro WhatsApp réservé aux alertes en scannant un QR code. Sans lui, les alertes partent par le WhatsApp de l'entreprise.
      </p>
      <p class="text-xs text-slate-600">
        Prenez un numéro à part (jamais celui des clients), déjà utilisé normalement quelques jours, et enregistrez-le dans les contacts des administrateurs.
      </p>
      <button type="button" class="btn-primary" :disabled="busy" @click="connect">Relier par QR code</button>
    </template>

    <p v-if="state.error" class="text-red-700">{{ state.error }}</p>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { confirmDialog } from '../composables/useDialog'

/**
 * WhatsApp des alertes : numéro relié en scannant un QR code, à placer dans le réglage des alertes
 * (à côté de « Aussi sur WhatsApp »). Le QR code se renouvelle tout seul jusqu'au scan.
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
  if (state.value.status === 'connected' && !(await confirmDialog('Délier ce numéro ? Les alertes repartiront par le WhatsApp de l\'entreprise.'))) return
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
