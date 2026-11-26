<template>
  <!-- Livraison › Parcours : tous les clients en mise à disposition, le parcours du matin de leurs livreurs -->
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-bold">🧭 Parcours <span :class="['ml-1 rounded-full px-2 py-0.5 align-middle text-xs font-medium', activityBadge('mad')]">MAD</span></h1>
      <p class="text-sm text-slate-600">
        Un parcours, c'est la routine du matin d'un livreur en mise à disposition avant d'arriver chez le client : récupérer un colis
        dans une agence, puis dans une autre, puis déposer au siège. Le livreur pointe à la <strong>première étape</strong>, à l'heure
        du parcours. Sans parcours, il pointe au siège du client. Les changements valent tout de suite.
      </p>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-else-if="!plans" class="card p-6 text-center text-slate-600">Chargement…</p>
    <div v-else-if="!plans.length" class="card p-6 text-center text-slate-600">
      Aucun contrat de mise à disposition en cours.
      <RouterLink v-if="auth.can('merchants.view')" to="/admin/contrats" class="text-blue-600 underline">Voir les contrats</RouterLink>
    </div>

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <section v-for="p in plans" :key="p.id" class="card space-y-3 p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div class="min-w-0">
            <h2 class="font-semibold">🏢 {{ p.merchant }}</h2>
            <p class="text-sm text-slate-600">{{ p.couriers.length ? p.couriers.join(', ') : 'Aucun livreur attitré sur le contrat' }}</p>
          </div>
          <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', p.couriers.length ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">
            {{ p.couriers.length }} livreur{{ p.couriers.length > 1 ? 's' : '' }}
          </span>
        </div>
        <RoutineList compact :list-url="`/billing-plans/${p.id}/routines`" :create-url="`/billing-plans/${p.id}/routines`" update-url="/routines" :editable="canEdit" />
      </section>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import RoutineList from '../../components/routines/RoutineList.vue'
import { useAuthStore } from '../../stores/auth'
import { activityBadge } from '../../utils/activities'

const auth = useAuthStore()
const plans = ref(null)
const canEdit = ref(false)
const error = ref('')

onMounted(async () => {
  try {
    const { data } = await http.get('/routines/contracts')
    plans.value = data.data
    canEdit.value = data.can_edit
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>
