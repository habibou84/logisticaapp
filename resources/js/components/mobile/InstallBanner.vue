<template>
  <div v-if="visible" class="m-card p-4 flex items-start gap-3 text-left text-slate-900" :style="{ '--app-color': color }">
    <img :src="`/app-icons/${app}-192.png`" alt="" class="h-12 w-12 rounded-xl">
    <div class="flex-1 text-sm">
      <p class="font-semibold">Installer l'application {{ app === 'livreur' ? 'livreur' : '' }}</p>

      <p v-if="mode === 'prompt'" class="text-slate-600">Accès en un geste depuis l'écran d'accueil, même avec une connexion faible.</p>

      <template v-else-if="mode === 'ios'">
        <p class="text-slate-600">Sur iPhone, l'installation se fait depuis le menu de partage :</p>
        <ol class="mt-1 space-y-1 text-slate-700 list-decimal list-inside">
          <li>Touchez <strong>Partager</strong> <ShareIcon /> {{ inSafari ? 'en bas de l\'écran' : 'dans la barre d\'adresse' }}.</li>
          <li>Faites défiler et touchez <strong>« Sur l'écran d'accueil »</strong>.</li>
          <li>Touchez <strong>Ajouter</strong>, puis ouvrez l'application depuis son icône.</li>
        </ol>
        <p v-if="!inSafari" class="mt-1 text-xs text-slate-500">Option absente ? Ouvrez cette page dans <strong>Safari</strong>.</p>
      </template>

      <p v-else-if="mode === 'in-app'" class="text-slate-600">
        Cette page est ouverte dans une autre application. Ouvrez-la dans <strong>Chrome</strong> (menu <strong>⋮</strong> puis
        « Ouvrir dans Chrome » ou « Ouvrir dans le navigateur ») pour pouvoir l'installer.
      </p>

      <p v-else-if="mode === 'samsung'" class="text-slate-600">
        Touchez le menu <strong>≡</strong> en bas, puis <strong>« Ajouter page à »</strong> et <strong>« Écran d'accueil »</strong>.
      </p>

      <p v-else class="text-slate-600">
        Touchez le menu <strong>⋮</strong> en haut à droite, puis <strong>« Installer l'application »</strong>
        (ou « Ajouter à l'écran d'accueil »).
      </p>

      <div class="flex gap-2 mt-3">
        <button v-if="mode === 'prompt'" class="rounded-xl px-4 py-2 font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }" @click="install">Installer</button>
        <button class="rounded-xl px-4 py-2 font-medium text-slate-600 bg-slate-100" @click="dismiss">Plus tard</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, h, ref } from 'vue'
import { pwa, promptInstall } from '../../composables/usePwa'

const props = defineProps({ app: { type: String, required: true } })

const color = computed(() => (props.app === 'livreur' ? '#1d4ed8' : '#047857'))

// Icône « Partager » d'iOS : carré ouvert surmonté d'une flèche
const ShareIcon = () => h('svg', { viewBox: '0 0 24 24', class: 'inline h-4 w-4 align-text-bottom', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'aria-label': 'icône Partager' }, [
  h('path', { d: 'M12 3v12M8 7l4-4 4 4M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }),
])

const ua = window.navigator.userAgent
const isAndroid = /android/i.test(ua)
// Navigateurs intégrés (Facebook, Instagram, WebView d'une autre application) : installation impossible
const isInApp = /FBAN|FBAV|Instagram|Line\/|; wv\)/i.test(ua)
const isSamsung = /SamsungBrowser/i.test(ua)
const inSafari = pwa.isIos && !/CriOS|FxiOS|EdgiOS|OPiOS/i.test(ua)

/**
 * Comment installer sur cet appareil :
 *  - prompt : Chrome/Edge proposent l'installation (bouton « Installer ») ;
 *  - ios : pas d'invite sur iPhone, installation par le menu Partager ;
 *  - in-app, samsung, android : instructions du navigateur (Chrome n'envoie son
 *    invite qu'après un moment d'utilisation, et jamais dans certains navigateurs).
 */
const mode = computed(() => {
  if (pwa.canInstall) return 'prompt'
  if (pwa.isIos) return 'ios'
  if (!isAndroid) return null
  if (isInApp) return 'in-app'
  if (isSamsung) return 'samsung'
  return 'android'
})

const key = `install-dismissed-${props.app}`
const dismissed = ref(readDismissed())

function readDismissed() {
  try {
    return Number(localStorage.getItem(key) || 0) > Date.now()
  } catch {
    return false
  }
}

// Ordinateur sans invite d'installation : rien à proposer
const visible = computed(() => !pwa.installed && !dismissed.value && mode.value !== null)

async function install() {
  await promptInstall()
}

function dismiss() {
  dismissed.value = true
  try {
    localStorage.setItem(key, String(Date.now() + 7 * 24 * 3600 * 1000)) // reproposé dans 7 jours
  } catch {
    // stockage indisponible (navigation privée) : masqué pour cette session seulement
  }
}
</script>
