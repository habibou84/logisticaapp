<template>
  <div v-if="push.checked && push.serverEnabled" class="m-card p-4 space-y-3">
    <div class="flex items-center justify-between gap-3">
      <div>
        <p class="font-medium">🔔 Notifications sur ce téléphone</p>
        <p class="text-sm text-slate-500">{{ status }}</p>
      </div>
      <button
        v-if="push.supported && push.permission !== 'denied'"
        type="button"
        :class="['tap shrink-0 rounded-full px-4 py-2 text-sm font-semibold', push.subscribed ? 'bg-slate-100 text-slate-700' : 'text-white']"
        :style="push.subscribed ? {} : { backgroundColor: 'var(--app-color)' }"
        :disabled="push.busy"
        @click="push.subscribed ? disablePush() : enablePush()"
      >
        {{ push.subscribed ? 'Désactiver' : 'Activer' }}
      </button>
    </div>
    <button v-if="push.subscribed" type="button" class="text-sm font-medium text-[var(--app-color)]" :disabled="testing" @click="sendTest">
      {{ testing ? 'Envoi…' : 'Envoyer une notification d\'essai' }}
    </button>
    <p v-if="push.error" class="text-sm text-red-600">{{ push.error }}</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { disablePush, enablePush, initPush, needsInstallFirst, push, testPush } from '../../composables/usePush'
import { useToastStore } from '../../stores/toasts'
import { apiErrorMessage } from '../../bootstrap/axios'

// audience : « courier » (missions) ou « merchant » (suivi des colis, à la place de WhatsApp)
const props = defineProps({ audience: { type: String, default: 'courier' } })

const toasts = useToastStore()
const testing = ref(false)

const status = computed(() => {
  if (!push.supported) return needsInstallFirst() ? 'Installez d\'abord l\'application (Partager → Sur l\'écran d\'accueil).' : 'Ce navigateur ne gère pas les notifications.'
  if (push.permission === 'denied') return 'Bloquées : autorisez les notifications dans les réglages du navigateur.'
  if (props.audience === 'merchant') {
    return push.subscribed
      ? 'Activées : colis livrés, incidents et reversements arrivent ici, même téléphone en veille.'
      : 'Désactivées : vos messages arrivent par WhatsApp.'
  }
  return push.subscribed ? 'Activées : nouvelles missions et consignes, même téléphone en veille.' : 'Désactivées : vous ne serez prévenu que l\'application ouverte.'
})

async function sendTest() {
  testing.value = true
  try {
    await testPush()
    toasts.success('Notification envoyée : elle arrive dans quelques secondes.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    testing.value = false
  }
}

onMounted(initPush)
</script>
