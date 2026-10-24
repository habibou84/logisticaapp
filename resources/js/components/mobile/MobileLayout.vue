<template>
  <div class="min-h-dvh bg-slate-50 tap" :style="{ '--app-color': color }">
    <!-- Ordinateur (application client) : menu à gauche à la place de la barre d'onglets -->
    <aside v-if="sidebar" class="hidden lg:flex fixed inset-y-0 left-0 z-40 w-64 flex-col gap-1 p-4 text-white" :style="{ backgroundColor: color }">
      <div class="mb-5 flex items-center gap-2 px-2">
        <span v-if="initials" class="h-9 w-9 shrink-0 rounded-xl bg-white grid place-items-center text-sm font-extrabold" :style="{ color }" aria-hidden="true">{{ initials }}</span>
        <span class="truncate font-bold">{{ appName }}</span>
      </div>
      <component
        :is="primaryMenu ? 'button' : RouterLink"
        v-if="primaryTab"
        v-bind="primaryMenu ? { type: 'button' } : { to: primaryTab.to }"
        class="mb-4 flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-white font-bold shadow-sm hover:brightness-95"
        :style="{ color }"
        @click="primaryMenu && (menuOpen = true)"
      >
        <StatusIcon name="plus" class="h-5 w-5" />{{ primaryTab.label }}
      </component>
      <RouterLink
        v-for="link in sidebarLinks"
        :key="link.to"
        :to="link.to"
        :class="['flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition', isActive(link) ? 'bg-white/20 text-white' : 'text-white/85 hover:bg-white/10']"
      >
        <component :is="link.icon" v-if="link.icon && typeof link.icon !== 'string'" class="h-5 w-5" />
        <StatusIcon v-else :name="link.icon || 'package'" class="h-5 w-5" />
        {{ link.label }}
      </RouterLink>
    </aside>

    <div :class="sidebar ? 'lg:pl-64' : ''">
    <!-- Barre du haut -->
    <header class="sticky top-0 z-30 text-white pt-safe" :style="{ backgroundColor: color }">
      <div :class="['mx-auto max-w-lg h-14 px-2 flex items-center gap-1', sidebar ? 'lg:max-w-6xl lg:px-6' : '']">
        <button v-if="route.meta.back" class="h-11 w-11 grid place-items-center rounded-full active:bg-white/15" aria-label="Retour" @click="goBack">
          <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M15 5l-7 7 7 7" /></svg>
        </button>
        <!-- Initiales de la boutique (application client), sinon logo de l'entreprise (Paramètres › Logo) -->
        <span v-if="!route.meta.back && initials" :class="{ 'lg:hidden': sidebar }" class="ml-1 h-8 w-8 shrink-0 rounded-lg bg-white grid place-items-center text-sm font-extrabold" :style="{ color }" aria-hidden="true">{{ initials }}</span>
        <img v-else-if="!route.meta.back && branding.logo_url" :src="branding.logo_url" alt="" class="ml-1 h-8 w-8 shrink-0 rounded-lg bg-white object-contain p-0.5">
        <h1 :class="['flex-1 truncate text-lg font-semibold', route.meta.back ? '' : 'pl-2']">{{ title }}</h1>
        <RouterLink :to="`${base}/notifications`" class="relative h-11 w-11 grid place-items-center rounded-full active:bg-white/15" aria-label="Notifications">
          <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 1112 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 003.4 0" stroke-linecap="round" stroke-linejoin="round" /></svg>
          <span v-if="notifications.unread" class="absolute top-1.5 right-1.5 min-w-5 h-5 px-1 rounded-full bg-red-500 text-[11px] font-bold grid place-items-center">{{ notifications.unread > 9 ? '9+' : notifications.unread }}</span>
        </RouterLink>
      </div>
      <div v-if="!pwa.online" class="bg-amber-500 text-amber-950 text-center text-sm font-medium py-1.5">
        Hors ligne : les informations peuvent ne pas être à jour
      </div>
      <slot name="banner" />
    </header>

    <main :class="['mx-auto max-w-lg px-4 pt-4 pb-28', sidebar ? 'lg:max-w-6xl lg:px-8 lg:pt-8 lg:pb-12' : '']">
      <RouterView />
    </main>
    </div>

    <!-- Barre d'onglets (cachée pendant la saisie au clavier) -->
    <nav v-show="!keyboardOpen" :class="['fixed bottom-0 inset-x-0 z-30 bg-white/95 backdrop-blur border-t border-slate-200 pb-safe', sidebar ? 'lg:hidden' : '']">
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
            class="flex-1 h-full flex flex-col items-center justify-center gap-0.5 text-xs font-medium"
            :style="isActive(tab) ? { color } : {}"
            :class="isActive(tab) ? '' : 'text-slate-600'"
          >
            <span class="relative">
              <component :is="tab.icon" class="h-6 w-6" />
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
          <span :class="['h-10 w-10 shrink-0 grid place-items-center rounded-xl', i === 0 ? 'bg-white/20' : 'bg-slate-100 text-slate-700']" aria-hidden="true"><StatusIcon :name="item.icon" class="h-5 w-5" /></span>
          <span><span class="block font-semibold">{{ item.label }}</span><span :class="['text-sm', i === 0 ? 'opacity-90' : 'text-slate-600']">{{ item.text }}</span></span>
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
import StatusIcon from './StatusIcon.vue'
import { useNotificationStore } from '../../stores/notifications'
import { useRealtime } from '../../composables/useRealtime'
import { pwa } from '../../composables/usePwa'
import { keyboardOpen } from '../../composables/useKeyboard'

const props = defineProps({
  base: { type: String, required: true }, // '/marchand' ou '/livreur'
  color: { type: String, required: true },
  appName: { type: String, required: true },
  tabs: { type: Array, required: true }, // { to, label, icon, primary?, menu?, badge?, exact? }
  // Application client : initiales de la boutique dans l'en-tête
  initials: { type: String, default: '' },
  // Ordinateur : menu à gauche ({ to, label, icon, exact? }), à la place de la barre d'onglets
  sidebar: { type: Array, default: null },
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
const sidebarLinks = computed(() => props.sidebar || [])

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
