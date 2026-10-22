<template>
  <div v-if="visible" class="m-card p-4 flex items-start gap-3 bg-sky-50 ring-1 ring-sky-200">
    <span class="text-2xl">🔔</span>
    <div class="flex-1 text-sm space-y-2">
      <p>
        <strong class="block">Soyez prévenu sur ce téléphone</strong>
        Colis livrés, incidents, reversements : recevez-les en notification, même application fermée.
      </p>
      <p v-if="push.error" class="text-red-600">{{ push.error }}</p>
      <div class="flex gap-3">
        <button type="button" class="tap rounded-full px-4 py-2 font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }" :disabled="push.busy" @click="activate">
          {{ push.busy ? 'Activation…' : 'Activer' }}
        </button>
        <button type="button" class="tap px-2 text-slate-500" @click="dismiss">Plus tard</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { enablePush, initPush, needsInstallFirst, push } from '../../composables/usePush'
import { useToastStore } from '../../stores/toasts'

// Invitation de l'e-commerçant à activer les notifications (elles remplacent WhatsApp)
const KEY = 'push-invite-dismissed-at'
const PAUSE_DAYS = 7

const toasts = useToastStore()
const dismissed = ref(false)

try {
  const at = Number(localStorage.getItem(KEY))
  dismissed.value = Boolean(at) && Date.now() - at < PAUSE_DAYS * 86400000
} catch {
  // stockage indisponible : l'invitation reste affichée
}

const visible = computed(() => !dismissed.value && push.checked && push.serverEnabled && push.merchantMessages && push.supported
  && !push.subscribed && push.permission !== 'denied' && !needsInstallFirst())

async function activate() {
  if (await enablePush()) toasts.success('Notifications activées sur ce téléphone.')
}

function dismiss() {
  dismissed.value = true
  try {
    localStorage.setItem(KEY, String(Date.now()))
  } catch {
    // tant pis : l'invitation reviendra au prochain chargement
  }
}

onMounted(initPush)
</script>
