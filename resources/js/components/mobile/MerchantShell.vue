<template>
  <div class="min-h-dvh bg-[#F5F5F6] tap" :style="{ '--app-color': color }">
    <!-- Ordinateur : menu blanc à gauche (logo, bouton ＋, rubriques en couleur) à la place de la barre d'onglets -->
    <aside v-if="sidebar" class="hidden lg:flex fixed inset-y-0 left-0 z-40 w-64 flex-col gap-1 border-r border-slate-200/70 bg-white p-4">
      <RouterLink :to="base" class="mb-5 block px-2 pt-1" aria-label="Accueil">
        <img :src="branding.logo_url || DEFAULT_LOGO" :alt="`Logo ${branding.name || 'Logistica'}`" class="h-12 max-w-[200px] object-contain object-left">
      </RouterLink>
      <RouterLink
        v-for="link in sidebar"
        :key="link.to"
        :to="link.to"
        :class="['flex items-center gap-3 rounded-2xl px-3 py-2.5 text-[14.5px] font-semibold transition', isActive(link) ? 'bg-brand-soft text-brand' : 'text-slate-700 hover:bg-slate-50']"
      >
        <ColorIcon :name="link.color" :size="28" :tile="false" />
        <span class="flex-1 truncate">{{ link.label }}</span>
        <span v-if="link.badge" class="min-w-5 h-5 px-1.5 rounded-full bg-[#E0245E] text-white text-[11px] font-bold grid place-items-center">{{ link.badge }}</span>
      </RouterLink>
      <button v-if="primaryTab" type="button" class="mt-auto flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-brand-grad font-bold text-white shadow-[0_10px_24px_-12px_color-mix(in_srgb,var(--app-color)_75%,transparent)] hover:brightness-105" @click="openPrimary">
        <span class="text-xl leading-none" aria-hidden="true">＋</span>{{ primaryTab.label }}
      </button>
    </aside>

    <div :class="sidebar ? 'lg:pl-64' : ''">
      <!-- En-tête : initiales du client (ou retour), titre et date, recherche, cloche -->
      <header class="sticky top-0 z-30 bg-[#F5F5F6]/90 backdrop-blur pt-safe">
        <div :class="['mx-auto max-w-lg h-[60px] px-3 flex items-center gap-2', sidebar ? 'lg:max-w-6xl lg:h-[76px] lg:px-8' : '']">
          <button v-if="route.meta.back" class="h-10 w-10 shrink-0 grid place-items-center rounded-full bg-white shadow-soft text-slate-800 active:scale-95 transition" aria-label="Retour" @click="goBack">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>
          </button>
          <RouterLink v-else :to="`${base}/profil`" :class="['h-10 w-10 shrink-0 rounded-2xl bg-brand-grad grid place-items-center text-[13px] font-extrabold text-white', sidebar ? 'lg:hidden' : '']" aria-label="Mon profil">{{ initials }}</RouterLink>
          <div class="min-w-0 flex-1 pl-1">
            <h1 :class="['truncate text-[17px] font-bold leading-tight text-slate-900', sidebar ? 'lg:text-2xl' : '']">{{ title }}</h1>
            <p v-if="subtitle" :class="['truncate text-[12.5px] leading-tight text-slate-500 first-letter:uppercase', sidebar ? 'lg:text-sm' : '']">{{ subtitle }}</p>
          </div>
          <RouterLink v-if="!route.meta.back" :to="`${base}/courses?chercher=1`" class="h-10 w-10 shrink-0 grid place-items-center rounded-full bg-white shadow-soft text-slate-600 active:scale-95 transition" aria-label="Rechercher une course">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></svg>
          </RouterLink>
          <!-- Cloche : le nombre ne compte que ce qui attend une décision ; un point pour le reste des nouveautés -->
          <RouterLink :to="`${base}/notifications`" class="relative h-10 w-10 shrink-0 grid place-items-center rounded-full bg-white shadow-soft active:scale-95 transition" :aria-label="bellLabel">
            <ColorIcon name="cloche" :size="24" :tile="false" />
            <span v-if="actionCount" class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-[#E0245E] text-white text-[10.5px] font-extrabold grid place-items-center ring-2 ring-white">{{ actionCount > 9 ? '9+' : actionCount }}</span>
            <span v-else-if="notifications.unread" class="absolute top-1.5 right-1.5 h-2.5 w-2.5 rounded-full bg-[#E0245E] ring-2 ring-white" />
          </RouterLink>
          <RouterLink v-if="sidebar" :to="`${base}/profil`" class="hidden lg:grid h-10 w-10 shrink-0 rounded-2xl bg-brand-grad place-items-center text-[13px] font-extrabold text-white" aria-label="Mon profil">{{ initials }}</RouterLink>
        </div>
        <div v-if="!pwa.online" class="bg-amber-500 text-amber-950 text-center text-sm font-medium py-1.5">
          Hors ligne : les informations peuvent ne pas être à jour
        </div>
      </header>

      <main :class="['mx-auto max-w-lg px-4 pt-2', showTabs ? 'pb-32' : 'pb-8', sidebar ? 'lg:max-w-6xl lg:px-8 lg:pt-2 lg:pb-12' : '']">
        <RouterView />
      </main>
    </div>

    <!-- Barre d'onglets (cachée pendant la saisie au clavier ; remplacée par le menu de gauche sur ordinateur) -->
    <nav v-show="showTabs" :class="['fixed bottom-0 inset-x-0 z-30 bg-white/95 backdrop-blur border-t border-slate-200/70 pb-safe', sidebar ? 'lg:hidden' : '']">
      <div class="mx-auto max-w-lg flex h-[66px] px-1">
        <template v-for="tab in tabs" :key="tab.to">
          <!-- Bouton du milieu : que voulez-vous envoyer ? -->
          <div v-if="tab.primary" class="flex-1 flex items-start justify-center">
            <button
              type="button"
              class="-mt-5 h-[58px] w-[58px] rounded-[20px] bg-brand-grad grid place-items-center text-white shadow-[0_10px_24px_-8px_color-mix(in_srgb,var(--app-color)_70%,transparent)] active:scale-95 transition"
              :aria-label="tab.label"
              @click="openPrimary"
            >
              <svg viewBox="0 0 24 24" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
            </button>
          </div>
          <!-- Onglet actif : icône en couleur sur une pastille ; inactif : simple trait -->
          <RouterLink v-else :to="tab.to" :class="['flex-1 flex flex-col items-center justify-center gap-0.5 text-[11.5px] font-semibold', isActive(tab) ? 'text-brand' : 'text-slate-500']">
            <span :class="['relative h-8 w-14 grid place-items-center rounded-full transition-colors', isActive(tab) ? 'bg-brand-soft' : '']">
              <ColorIcon v-if="isActive(tab)" :name="tab.color" :size="26" :tile="false" />
              <component :is="tab.icon" v-else class="h-6 w-6" />
              <span v-if="tab.badge" class="absolute -top-1 right-1 min-w-4 h-4 px-1 rounded-full bg-[#E0245E] text-white text-[10px] font-bold grid place-items-center">{{ tab.badge }}</span>
            </span>
            {{ tab.label }}
          </RouterLink>
        </template>
      </div>
    </nav>

    <!-- Que voulez-vous envoyer ? Les choix des activités du client -->
    <BottomSheet v-if="primaryTab?.menu" :open="menuOpen" :title="primaryTab.sheetTitle || primaryTab.label" @close="menuOpen = false">
      <div class="space-y-2.5">
        <RouterLink
          v-for="(item, i) in primaryTab.menu"
          :key="item.to"
          :to="item.to"
          :class="['tap flex items-center gap-3.5 rounded-[20px] p-3 active:scale-[.99] transition', i === 0 ? 'bg-brand-soft ring-2 ring-[color-mix(in_srgb,var(--app-color)_25%,white)]' : 'bg-[#F7F8FA] hover:bg-slate-100']"
          @click="menuOpen = false"
        >
          <ColorIcon :name="item.color" :size="52" />
          <span class="min-w-0">
            <span class="block text-[15.5px] font-bold text-slate-900">{{ item.label }}<span v-if="item.tag" :class="['ml-1.5 rounded-full px-2 py-0.5 align-[2px] text-[10.5px] font-bold', item.tagClass]">{{ item.tag }}</span></span>
            <span class="text-[13px] text-slate-500">{{ item.text }}</span>
          </span>
        </RouterLink>
      </div>
    </BottomSheet>

    <Toasts />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watchEffect } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import ColorIcon from './ColorIcon.vue'
import Toasts from '../Toasts.vue'
import BottomSheet from './BottomSheet.vue'
import { DEFAULT_LOGO, branding } from '../../composables/useBranding'
import { useNotificationStore } from '../../stores/notifications'
import { useRealtime } from '../../composables/useRealtime'
import { pwa } from '../../composables/usePwa'
import { keyboardOpen } from '../../composables/useKeyboard'

/**
 * Cadre de l'espace client (style « dégradé doux », charte Logistica) : en-tête clair avec la date sur
 * l'accueil, cloche qui ne compte que les décisions à prendre, onglets à pastille, bouton ＋ « Que voulez-vous
 * envoyer ? » selon les activités du client ; sur ordinateur, menu blanc à gauche avec le logo.
 */
const props = defineProps({
  base: { type: String, required: true },
  color: { type: String, required: true },
  appName: { type: String, required: true },
  initials: { type: String, default: '' },
  // { to, label, icon (composant au trait), color (icône en couleur de l'onglet actif), badge?, exact?, primary?, sheetTitle?, menu? }
  tabs: { type: Array, required: true },
  // Ordinateur : { to, label, color, badge?, exact? }
  sidebar: { type: Array, default: null },
  // Colis qui attendent une décision (seul compteur affiché sur la cloche)
  actionCount: { type: Number, default: 0 },
})

const route = useRoute()
const router = useRouter()
const notifications = useNotificationStore()

useRealtime()
if (!notifications.loaded) notifications.fetch().catch(() => {})

const title = computed(() => route.meta.title || props.appName)
// Accueil : la date du jour sous le nom du client
const subtitle = computed(() => (route.path === props.base ? new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }) : ''))
const bellLabel = computed(() => (props.actionCount ? `Notifications : ${props.actionCount} colis à régler` : 'Notifications'))
// Barre cachée pendant la saisie au clavier et sur les formulaires qui ont leur propre bouton en bas
const showTabs = computed(() => !keyboardOpen.value && !route.meta.hideTabs)
const menuOpen = ref(false)
const primaryTab = computed(() => props.tabs.find((t) => t.primary))

// Un seul choix possible : directement le formulaire
function openPrimary() {
  const menu = primaryTab.value?.menu
  if (menu?.length > 1) menuOpen.value = true
  else router.push(menu?.[0]?.to || primaryTab.value.to)
}

// Couleur de l'espace aussi sur <html> : les panneaux du bas (téléportés dans <body>) en héritent.
// Classe app-marchand : les cartes et boutons communs prennent le style doux de l'espace client.
watchEffect(() => document.documentElement.style.setProperty('--app-color', props.color))
document.documentElement.classList.add('app-marchand')
onBeforeUnmount(() => {
  document.documentElement.style.removeProperty('--app-color')
  document.documentElement.classList.remove('app-marchand')
})

function isActive(tab) {
  return tab.exact ? route.path === tab.to : route.path.startsWith(tab.to)
}

function goBack() {
  if (window.history.state?.back) router.back()
  else router.push(props.base)
}
</script>
