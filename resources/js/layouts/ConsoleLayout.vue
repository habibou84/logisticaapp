<template>
  <div class="min-h-screen bg-slate-100">
    <header class="bg-slate-950 text-white print:hidden">
      <div class="mx-auto max-w-6xl px-4 py-3 flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap items-center gap-4">
          <RouterLink to="/console" class="font-bold">🛰️ Console de la plateforme</RouterLink>
          <nav class="flex gap-1 text-sm">
            <RouterLink to="/console" class="rounded px-3 py-1.5 hover:bg-white/10" exact-active-class="bg-white/15">Entreprises</RouterLink>
            <RouterLink to="/console/formules" class="rounded px-3 py-1.5 hover:bg-white/10" active-class="bg-white/15">Formules</RouterLink>
            <RouterLink to="/console/facturation" class="rounded px-3 py-1.5 hover:bg-white/10" active-class="bg-white/15">Facturation</RouterLink>
            <RouterLink to="/console/paiements" class="rounded px-3 py-1.5 hover:bg-white/10" active-class="bg-white/15">Paiements Wave</RouterLink>
          </nav>
        </div>
        <div class="flex items-center gap-3 text-sm">
          <NotificationBell class="[&>button]:hover:bg-white/10" />
          <span class="text-slate-300">{{ auth.user?.name }}</span>
          <button class="rounded bg-white/10 px-3 py-1.5 hover:bg-white/20" @click="logout">Déconnexion</button>
        </div>
      </div>
    </header>
    <main class="mx-auto max-w-6xl p-4 md:p-6">
      <RouterView />
    </main>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import NotificationBell from '../components/NotificationBell.vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function logout() {
  await auth.logout()
  router.push('/admin/connexion')
}
</script>
