<template>
  <div class="min-h-screen flex flex-col px-5 pt-14 pb-8" :style="{ paddingTop: 'max(3.5rem, env(safe-area-inset-top))', background: `linear-gradient(to bottom, rgba(0,0,0,.18), rgba(255,255,255,.06)), ${spaceColor('livreur')}` }">
    <div class="text-center space-y-3 text-white">
      <BrandLogo size="lg" :with-name="false" dark class="justify-center" />
      <p class="text-2xl font-bold">{{ branding.name }}</p>
      <p class="text-white/80">🛵 Espace livreur</p>
    </div>
    <div class="mt-8 w-full max-w-sm mx-auto rounded-3xl bg-white p-6 shadow-2xl">
      <LoginForm tone="courier" />
    </div>
    <div class="mt-4 w-full max-w-sm mx-auto">
      <InstallBanner app="livreur" />
      <NativeCompanySwitch dark class="mt-4" />
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import BrandLogo from '../../components/auth/BrandLogo.vue'
import LoginForm from '../../components/auth/LoginForm.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import NativeCompanySwitch from '../../components/auth/NativeCompanySwitch.vue'
import { branding, loadBranding, spaceColor } from '../../composables/useBranding'

onMounted(() => loadBranding())
</script>
