<template>
  <!-- Actions faites hors connexion : en attente d'envoi, ou refusées au retour du réseau -->
  <button v-if="offlineQueue.pending.length" type="button" class="block w-full bg-sky-600 text-white text-center text-sm font-medium py-1.5" @click="sendNow">
    {{ offlineQueue.syncing ? 'Envoi en cours…' : `⏳ ${offlineQueue.pending.length} action${offlineQueue.pending.length > 1 ? 's' : ''} en attente d'envoi` }}
    <span v-if="pwa.online && !offlineQueue.syncing" class="underline ml-1">Envoyer</span>
  </button>
  <button v-if="offlineQueue.rejected.length" type="button" class="block w-full bg-red-600 text-white text-center text-sm font-medium py-1.5" @click="open = true">
    ⚠️ {{ offlineQueue.rejected.length }} action{{ offlineQueue.rejected.length > 1 ? 's' : '' }} refusée{{ offlineQueue.rejected.length > 1 ? 's' : '' }} : voir
  </button>

  <BottomSheet :open="open" title="Actions refusées" @close="open = false">
    <p class="text-sm text-slate-600 mb-3">Faites hors connexion, ces actions n'ont pas pu être enregistrées. L'agence a été prévenue : appelez-la si besoin.</p>
    <ul class="space-y-3">
      <li v-for="a in offlineQueue.rejected" :key="a.id" class="m-card p-3 space-y-1">
        <p class="font-medium">{{ a.tracking_code || `Course n° ${a.order_id}` }} · {{ describe(a) }}</p>
        <p class="text-sm text-red-700">{{ a.message }}</p>
        <p class="text-xs text-slate-400">Faite le {{ new Date(a.occurred_at).toLocaleString('fr-FR') }}</p>
        <button type="button" class="text-sm font-medium text-slate-600 underline" @click="dismissRejected(a.id)">J'ai compris</button>
      </li>
    </ul>
  </BottomSheet>
</template>

<script setup>
import { ref, watch } from 'vue'
import BottomSheet from './BottomSheet.vue'
import { dismissRejected, offlineQueue, syncOfflineQueue } from '../../composables/useOfflineQueue'
import { pwa } from '../../composables/usePwa'
import { STATUS_LABELS } from '../../utils/format'

const open = ref(false)

watch(() => offlineQueue.rejected.length, (n) => { if (!n) open.value = false })

function describe(action) {
  return action.kind === 'note' ? 'note' : (STATUS_LABELS[action.payload?.status] ?? action.payload?.status)
}

async function sendNow() {
  if (await syncOfflineQueue()) window.dispatchEvent(new CustomEvent('offline-synced'))
}
</script>
