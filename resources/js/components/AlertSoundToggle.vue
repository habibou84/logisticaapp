<template>
  <div class="relative">
    <button
      type="button"
      :class="['relative p-2 rounded hover:bg-black/5', needsUnlock ? 'animate-pulse' : '']"
      :aria-label="alertSound.enabled ? 'Alertes sonores activées' : 'Alertes sonores coupées'"
      :title="needsUnlock ? 'Cliquez pour activer le son des alertes' : 'Alertes sonores'"
      @click="open = !open"
    >
      <span class="text-xl">{{ alertSound.enabled ? '🔊' : '🔇' }}</span>
    </button>

    <div v-if="open" class="absolute right-0 mt-2 w-72 bg-white text-slate-800 rounded-lg shadow-xl border z-50 p-3 space-y-3 text-sm">
      <p class="font-semibold">Alertes des livreurs</p>
      <label class="flex items-center justify-between gap-2">
        <span>Son pour les notes et problèmes</span>
        <input type="checkbox" :checked="alertSound.enabled" @change="setAlertSound({ enabled: $event.target.checked })">
      </label>
      <div v-if="alertSound.enabled">
        <label class="label" for="alert-volume">Volume</label>
        <input id="alert-volume" type="range" min="0.1" max="1" step="0.1" :value="alertSound.volume" class="w-full" @change="setAlertSound({ volume: Number($event.target.value) }); playAlertSound('note')">
      </div>
      <div class="flex gap-2">
        <button type="button" class="btn-secondary flex-1 text-xs" @click="playAlertSound('note', true)">▶︎ Note</button>
        <button type="button" class="btn-secondary flex-1 text-xs" @click="playAlertSound('alert', true)">▶︎ Problème</button>
      </div>
      <div class="border-t pt-3">
        <p v-if="alertSound.desktop === 'granted'" class="text-emerald-700">✓ Notifications du bureau activées (onglet en arrière-plan).</p>
        <p v-else-if="alertSound.desktop === 'denied'" class="text-gray-500">Notifications du bureau bloquées : autorisez-les dans les réglages du navigateur.</p>
        <button v-else-if="alertSound.desktop !== 'unsupported'" type="button" class="btn-primary w-full text-xs" @click="enableDesktopNotifications">
          Afficher aussi les alertes quand l'onglet est caché
        </button>
      </div>
      <p class="text-xs text-gray-500">Gardez cet onglet ouvert pendant le service pour entendre les alertes.</p>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { alertSound, enableDesktopNotifications, playAlertSound, setAlertSound } from '../composables/useAlertSound'

const open = ref(false)
const needsUnlock = computed(() => alertSound.enabled && !alertSound.unlocked)
</script>
