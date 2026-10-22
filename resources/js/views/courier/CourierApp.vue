<template>
  <MobileLayout base="/livreur" :color="spaceColor('livreur')" app-name="Mes missions" :tabs="tabs">
    <template #banner>
      <OfflineQueueBanner />
    </template>
  </MobileLayout>
</template>

<script setup>
import { onBeforeUnmount, onMounted } from 'vue'
import http from '../../bootstrap/axios'
import MobileLayout from '../../components/mobile/MobileLayout.vue'
import { loadBranding, spaceColor } from '../../composables/useBranding'
import OfflineQueueBanner from '../../components/mobile/OfflineQueueBanner.vue'
import { RouteIcon, UserIcon, WalletIcon } from '../../components/mobile/icons'
import { currentPosition } from '../../composables/useGeolocation'
import { loadOfflineQueue, startOfflineSync } from '../../composables/useOfflineQueue'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

// Actions faites hors connexion : relues sur le téléphone, envoyées dès que le réseau revient
loadOfflineQueue(auth.user?.id)
startOfflineSync(() => window.dispatchEvent(new CustomEvent('offline-synced')))

// Position envoyée toutes les 30 secondes pendant le service, quel que soit l'écran ouvert
// (carte des livreurs du dispatch)
let locationTimer
async function sendLocation() {
  if (!auth.user?.courier?.is_available || document.hidden) return
  const position = await currentPosition(5000, true)
  if (position) http.patch('/courier/status', position).catch(() => {})
}

onMounted(() => {
  sendLocation()
  locationTimer = setInterval(sendLocation, 30000)
})
onBeforeUnmount(() => clearInterval(locationTimer))

const tabs = [
  { to: '/livreur', label: 'Missions', icon: RouteIcon, exact: true },
  { to: '/livreur/caisse', label: 'Caisse', icon: WalletIcon },
  { to: '/livreur/profil', label: 'Profil', icon: UserIcon },
]

// Couleur et logo choisis par l'entreprise (Paramètres › Apparence)
loadBranding()
</script>
