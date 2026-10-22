<template>
  <Transition
    enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0"
  >
    <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-3 sm:items-center" @click.self="later">
      <div
        role="dialog" aria-modal="true" aria-labelledby="install-title"
        class="w-full max-w-sm rounded-3xl bg-white p-6 text-center text-slate-900 shadow-2xl"
        style="padding-bottom: max(1.5rem, env(safe-area-inset-bottom))"
      >
        <img :src="`/app-icons/${app}-192.png`" alt="" class="mx-auto h-20 w-20 rounded-2xl shadow">
        <h2 id="install-title" class="mt-4 text-xl font-bold">Installer l'application {{ label }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ branding.name }}, directement depuis l'écran d'accueil de votre téléphone.</p>
        <ul class="mt-4 space-y-2 text-left text-sm text-slate-700">
          <li v-for="b in benefits" :key="b" class="flex gap-2"><span aria-hidden="true">✓</span>{{ b }}</li>
        </ul>
        <button type="button" class="mt-6 w-full rounded-xl py-3.5 text-base font-semibold text-white shadow-sm" :style="{ backgroundColor: color }" @click="install">
          Installer
        </button>
        <button type="button" class="mt-2 w-full rounded-xl py-3 text-sm font-medium text-slate-600" @click="later">Plus tard</button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { branding } from '../composables/useBranding'
import { pwa, promptInstall } from '../composables/usePwa'

// Fenêtre d'installation (Android, Chrome/Edge) : elle s'ouvre d'elle-même dès que le
// navigateur autorise l'installation ; le bouton ouvre la confirmation du système.
const DISMISS_KEY = 'install-popup-dismissed'
const DISMISS_DAYS = 3
// Pages des deux applications installables (pas le back-office, ni le suivi des destinataires)
const SKIP = [/^\/admin/, /^\/suivi/, /^\/b\//, /^\/etiquette/, /^\/bon-de-retour/, /^\/inscription/, /^\/mot-de-passe-oublie/]

const route = useRoute()
const open = ref(false)
const dismissed = ref(readDismissed())

const app = computed(() => (route.path.startsWith('/livreur') ? 'livreur' : 'marchand'))
const label = computed(() => (app.value === 'livreur' ? 'livreur' : ''))
const color = computed(() => (app.value === 'livreur' ? '#1d4ed8' : '#047857'))
const benefits = computed(() => (app.value === 'livreur'
  ? ['Vos missions en un geste, en plein écran', 'Notifications des nouvelles missions', 'Fonctionne même avec une connexion faible']
  : ['Créez et suivez vos courses en un geste', 'Vos encaissements et reversements toujours à portée', 'Fonctionne même avec une connexion faible']))

const eligible = computed(() => pwa.canInstall && !pwa.installed && !dismissed.value && !SKIP.some((r) => r.test(route.path)))

// Petit délai : la page s'affiche d'abord, la fenêtre arrive ensuite
let timer = null
watch(eligible, (yes) => {
  clearTimeout(timer)
  if (yes) timer = setTimeout(() => { open.value = eligible.value }, 1500)
  else open.value = false
}, { immediate: true })
onBeforeUnmount(() => clearTimeout(timer))

async function install() {
  open.value = false
  // Refus dans la fenêtre du système : on ne repropose pas tout de suite
  if (!(await promptInstall())) remember()
}

function later() {
  open.value = false
  remember()
}

function remember() {
  dismissed.value = true
  try {
    localStorage.setItem(DISMISS_KEY, String(Date.now() + DISMISS_DAYS * 24 * 3600 * 1000))
  } catch {
    // stockage indisponible : masquée pour cette visite seulement
  }
}

function readDismissed() {
  try {
    return Number(localStorage.getItem(DISMISS_KEY) || 0) > Date.now()
  } catch {
    return false
  }
}
</script>
