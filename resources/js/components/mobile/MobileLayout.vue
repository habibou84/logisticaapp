<template>
  <div class="min-h-dvh bg-slate-50 tap" :style="{ '--app-color': color }">
    <!-- Barre du haut -->
    <header class="sticky top-0 z-30 text-white pt-safe" :style="{ backgroundColor: color }">
      <div class="mx-auto max-w-lg h-14 px-2 flex items-center gap-1">
        <button v-if="route.meta.back" class="h-11 w-11 grid place-items-center rounded-full active:bg-white/15" aria-label="Retour" @click="goBack">
          <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M15 5l-7 7 7 7" /></svg>
        </button>
        <!-- Initiales de la boutique (application marchand), sinon logo de l'entreprise (Paramètres › Logo) -->
        <span v-if="!route.meta.back && initials" class="ml-1 h-8 w-8 shrink-0 rounded-lg bg-white grid place-items-center text-sm font-extrabold" :style="{ color }" aria-hidden="true">{{ initials }}</span>
        <img v-else-if="!route.meta.back && branding.logo_url" :src="branding.logo_url" alt="" class="ml-1 h-8 w-8 shrink-0 rounded-lg bg-white object-contain p-0.5">
        <h1 :class="['flex-1 truncate text-lg font-semibold', route.meta.back ? '' : 'pl-2']">{{ title }}</h1>
        <RouterLink :to="`${base}/notifications`" class="relative h-11 w-11 grid place-items-center rounded-full active:bg-white/15" aria-label="Notifications">
          <span v-if="colorful" class="text-2xl leading-none" aria-hidden="true">🔔</span>
          <svg v-else viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 1112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0" stroke-linecap="round" stroke-linejoin="round" /></svg>
          <span v-if="notifications.unread" class="absolute top-1.5 right-1.5 min-w-5 h-5 px-1 rounded-full bg-red-500 text-[11px] font-bold grid place-items-center">{{ notifications.unread > 9 ? '9+' : notifications.unread }}</span>
        </RouterLink>
      </div>
      <div v-if="!pwa.online" class="bg-amber-500 text-amber-950 text-center text-sm font-medium py-1.5">
        Hors ligne : les informations peuvent ne pas être à jour
      </div>
      <slot name="banner" />
    </header>

    <main class="mx-auto max-w-lg px-4 pt-4 pb-28">
      <RouterView />
    </main>

    <!-- Barre d'onglets (cachée pendant la saisie au clavier) -->
    <nav v-show="!keyboardOpen" class="fixed bottom-0 inset-x-0 z-30 bg-white/95 backdrop-blur border-t border-slate-200 pb-safe">
      <div class="mx-auto max-w-lg flex items-end justify-around h-16">
        <template v-for="tab in tabs" :key="tab.to">
          <component
            :is="tab.menu ? 'button' : RouterLink"
            v-if="tab.primary"
            v-bind="tab.menu ? { type: 'button' } : { to: tab.to }"
            class="-mt-6 h-14 w-14 rounded-full grid place-items-center text-white shadow-lg ring-4 ring-slate-50 active:scale-95 transition"
            :style="{ backgroundColor: color }"
            :aria-label="tab.label"
            @click="tab.menu && (menuOpen = true)"
          >
            <svg viewBox="0 0 24 24" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
          </component>
          <RouterLink
            v-else
            :to="tab.to"
            class="flex-1 h-full flex flex-col items-center justify-center gap-0.5 text-[11px] font-medium"
            :style="isActive(tab) ? { color } : {}"
            :class="isActive(tab) ? '' : 'text-slate-500'"
          >
            <span class="relative">
              <!-- Icône en couleur (application marchand), plus pâle quand l'onglet n'est pas ouvert -->
              <span v-if="tab.emoji" :class="['block text-2xl leading-6 transition', isActive(tab) ? 'scale-110' : 'opacity-70 grayscale-[35%]']" aria-hidden="true">{{ tab.emoji }}</span>
              <component :is="tab.icon" v-else class="h-6 w-6" />
              <span v-if="tab.badge" class="absolute -top-1 -right-2 min-w-4 h-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-bold grid place-items-center">{{ tab.badge }}</span>
            </span>
            {{ tab.label }}
          </RouterLink>
        </template>
      </div>
    </nav>

    <!-- Bouton ＋ à plusieurs choix (créer, coller un message, importer) -->
    <BottomSheet v-if="primaryMenu" :open="menuOpen" :title="primaryTab.label" @close="menuOpen = false">
      <div class="space-y-2">
        <RouterLink
          v-for="(item, i) in primaryMenu"
          :key="item.to"
          :to="item.to"
          :class="['tap flex items-center gap-3 rounded-2xl p-4 ring-1', i === 0 ? 'text-white ring-transparent' : 'bg-white ring-slate-200']"
          :style="i === 0 ? { backgroundColor: color } : {}"
          @click="menuOpen = false"
        >
          <span class="text-2xl" aria-hidden="true">{{ item.icon }}</span>
          <span><span class="block font-semibold">{{ item.label }}</span><span :class="['text-sm', i === 0 ? 'opacity-85' : 'text-slate-500']">{{ item.text }}</span></span>
        </RouterLink>
      </div>
    </BottomSheet>

    <Toasts />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watchEffect } from 'vue'
import { branding } from '../../composables/useBranding'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import Toasts from '../Toasts.vue'
import BottomSheet from './BottomSheet.vue'
import { useNotificationStore } from '../../stores/notifications'
import { useRealtime } from '../../composables/useRealtime'
import { pwa } from '../../composables/usePwa'
import { keyboardOpen } from '../../composables/useKeyboard'

const props = defineProps({
  base: { type: String, required: true }, // '/marchand' ou '/livreur'
  color: { type: String, required: true },
  appName: { type: String, required: true },
  tabs: { type: Array, required: true }, // { to, label, icon | emoji, primary?, menu?, badge?, exact? }
  // Application marchand : initiales de la boutique dans l'en-tête, cloche en couleur
  initials: { type: String, default: '' },
  colorful: { type: Boolean, default: false },
})

const route = useRoute()
const router = useRouter()
const notifications = useNotificationStore()

useRealtime()
if (!notifications.loaded) notifications.fetch().catch(() => {})

const title = computed(() => route.meta.title || props.appName)
const menuOpen = ref(false)
const primaryTab = computed(() => props.tabs.find((t) => t.primary))
const primaryMenu = computed(() => primaryTab.value?.menu || null)

// Couleur de l'application aussi sur <html> : les panneaux du bas (téléportés dans <body>) en héritent
watchEffect(() => document.documentElement.style.setProperty('--app-color', props.color))
onBeforeUnmount(() => document.documentElement.style.removeProperty('--app-color'))

function isActive(tab) {
  return tab.exact ? route.path === tab.to : route.path.startsWith(tab.to)
}

function goBack() {
  if (window.history.state?.back) router.back()
  else router.push(props.base)
}
</script>
