<template>
  <div class="min-h-screen grid place-items-center px-4 py-10" :style="{ backgroundColor: spaceColor('admin') }">
    <div class="w-full max-w-sm space-y-6">
      <div class="text-center space-y-3">
        <BrandLogo size="lg" :with-name="false" dark class="justify-center" />
        <div>
          <p class="text-xl font-bold text-white">{{ branding.name }}</p>
          <p class="text-sm text-white/70">Espace équipe · administration, dispatch, caisse, entrepôt</p>
        </div>
      </div>
      <div class="rounded-2xl bg-white p-6 shadow-xl">
        <LoginForm tone="staff" />
      </div>
      <!-- Adresse de la console : les entreprises peuvent s'y inscrire -->
      <p v-if="onConsoleHost" class="text-center text-sm text-white/70">
        Entreprise de livraison ? <RouterLink to="/creer-mon-entreprise" class="font-medium text-white underline">Créer mon entreprise</RouterLink>
      </p>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import BrandLogo from '../../components/auth/BrandLogo.vue'
import LoginForm from '../../components/auth/LoginForm.vue'
import { branding, loadBranding, spaceColor } from '../../composables/useBranding'

// Console de la plateforme (admin.…) : aucune entreprise derrière l'adresse
const onConsoleHost = window.location.hostname.startsWith('admin.')

onMounted(() => loadBranding())
</script>
