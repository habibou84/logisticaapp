<template>
  <MobileLayout base="/mecanicien" :color="spaceColor('mecanicien')" app-name="Mes pannes" :tabs="tabs">
    <template #banner>
      <div v-if="stepQueue.pending.length" class="bg-amber-100 text-amber-900 text-center text-sm font-medium py-1.5">
        {{ stepQueue.pending.length }} étape(s) notée(s) hors connexion, envoi au retour du réseau
      </div>
    </template>
  </MobileLayout>
  <OfferAlert v-if="offer" :repair="offer" @answered="answered" />
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import MobileLayout from '../../components/mobile/MobileLayout.vue'
import OfferAlert from '../../components/mechanic/OfferAlert.vue'
import { UserIcon, VehicleIcon, WrenchIcon } from '../../components/mobile/icons'
import { loadBranding, spaceColor } from '../../composables/useBranding'
import { board, loadBoard, loadMechanicSetup, loadStepQueue, sendPosition, startStepSync, stepQueue } from '../../composables/useMechanic'
import { useAuthStore } from '../../stores/auth'
import { useNotificationStore } from '../../stores/notifications'

const auth = useAuthStore()
const route = useRoute()
const notifications = useNotificationStore()

// Panne proposée : alerte plein écran (sauf sur sa propre fiche, où l'on répond directement)
const dismissed = ref(null)
const offer = computed(() => {
  const o = board.data?.offer
  return o && o.id !== dismissed.value && route.path !== `/mecanicien/pannes/${o.id}` ? o : null
})
function answered(id) {
  dismissed.value = id
  loadBoard()
}

// Étapes notées sans réseau, envoyées dès qu'il revient
loadStepQueue()
const stopSync = startStepSync()

// Pannes rechargées régulièrement et à chaque notification (proposition, annulation…)
let boardTimer
let positionTimer
async function tick() {
  if (!document.hidden) await loadBoard()
}
// Position envoyée toutes les 30 secondes pendant le service (attribution au mécanicien le plus proche)
async function position() {
  if (!board.data?.mechanic?.is_available || document.hidden) return
  sendPosition().catch(() => {})
}

onMounted(() => {
  loadBoard()
  loadMechanicSetup().catch(() => {})
  boardTimer = setInterval(tick, 20000)
  positionTimer = setInterval(position, 30000)
  document.addEventListener('visibilitychange', tick)
})
onBeforeUnmount(() => {
  clearInterval(boardTimer)
  clearInterval(positionTimer)
  document.removeEventListener('visibilitychange', tick)
  stopSync()
})
watch(() => notifications.unread, (now, before) => { if (now > before) loadBoard() })

const tabs = computed(() => [
  { to: '/mecanicien', label: 'Pannes', icon: WrenchIcon, exact: true, badge: board.data ? (board.data.todo.length + board.data.current.length) || null : null },
  { to: '/mecanicien/vehicules', label: 'Véhicules', icon: VehicleIcon },
  { to: '/mecanicien/profil', label: 'Profil', icon: UserIcon },
])

// Couleur et logo choisis par l'entreprise (Paramètres › Apparence)
loadBranding()
if (!auth.user?.mechanic) auth.fetchUser().catch(() => {})
</script>
