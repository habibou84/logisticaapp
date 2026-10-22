<template>
  <div :class="['min-h-screen flex flex-col px-5 pb-8 bg-gradient-to-b', theme.bg]" style="padding-top: max(3.5rem, env(safe-area-inset-top))">
    <div class="text-center space-y-3 text-white">
      <img :src="logo" alt="" class="mx-auto h-16 w-16">
      <p class="text-2xl font-bold">{{ title }}</p>
      <p :class="theme.muted">Votre entreprise de livraison, dans votre poche</p>
    </div>

    <div class="mt-8 w-full max-w-sm mx-auto rounded-3xl bg-white p-6 shadow-2xl">
      <!-- Entreprise trouvée : confirmation -->
      <div v-if="found" class="space-y-5 text-center">
        <img v-if="found.logo_url" :src="found.logo_url" :alt="`Logo ${found.name}`" class="mx-auto h-16 max-w-40 object-contain">
        <div>
          <p class="text-sm text-slate-500">Votre entreprise de livraison</p>
          <p class="text-xl font-bold text-slate-900">{{ found.name }}</p>
        </div>
        <button type="button" :class="['w-full rounded-xl py-3 font-semibold text-white shadow-sm', theme.button]" @click="confirm">Continuer</button>
        <button type="button" class="w-full text-sm text-slate-600 hover:underline" @click="found = null">Ce n'est pas mon entreprise</button>
      </div>

      <!-- Saisie du code -->
      <form v-else class="space-y-4" @submit.prevent="search">
        <div v-if="error" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2" role="alert">{{ error }}</div>
        <label class="block">
          <span class="text-sm font-medium text-slate-700">Code de votre entreprise</span>
          <input
            v-model.trim="code"
            type="text"
            autocapitalize="none"
            autocomplete="off"
            spellcheck="false"
            placeholder="ex. rapide-express"
            required
            :class="['mt-1 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base focus:outline-none focus:ring-2 focus:border-transparent', theme.ring]"
          >
        </label>
        <p class="text-sm text-slate-500">Demandez-le à votre entreprise de livraison : c'est le début de son adresse, <strong>code</strong>.jibiat.com.</p>
        <button type="submit" :disabled="loading" :class="['w-full rounded-xl py-3 font-semibold text-white shadow-sm disabled:opacity-60', theme.button]">
          {{ loading ? 'Recherche…' : 'Continuer' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import logo from '../../../../public/marketing/logo.png'
import { useAuthStore } from '../../stores/auth'
import { findCompany, nativeApp, nativeCompany, nativeConfig, setNativeCompany } from '../../native'

const THEMES = {
  livreur: { bg: 'from-blue-800 to-blue-600', muted: 'text-blue-100', button: 'bg-blue-700 hover:bg-blue-800', ring: 'focus:ring-blue-500' },
  ecommercant: { bg: 'from-emerald-800 to-emerald-600', muted: 'text-emerald-100', button: 'bg-emerald-600 hover:bg-emerald-700', ring: 'focus:ring-emerald-500' },
}
const theme = computed(() => THEMES[nativeApp] || THEMES.ecommercant)
const title = nativeApp === 'livreur' ? 'Jibiat Livreur' : 'Jibiat E-commerçant'

const router = useRouter()
const auth = useAuthStore()

const code = ref(nativeCompany()?.code || '')
const found = ref(null)
const error = ref('')
const loading = ref(false)

async function search() {
  error.value = ''
  loading.value = true
  try {
    found.value = await findCompany(code.value)
  } catch (e) {
    error.value = e instanceof TypeError ? 'Pas de connexion. Réessayez quand le réseau revient.' : e.message
  } finally {
    loading.value = false
  }
}

function confirm() {
  // Autre entreprise : la session de la précédente ne vaut plus
  if (nativeCompany()?.code !== found.value.code) auth.clear()
  setNativeCompany(found.value)
  router.replace(nativeConfig?.login || '/')
}
</script>
