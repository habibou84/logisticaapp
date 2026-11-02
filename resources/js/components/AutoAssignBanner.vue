<template>
  <!-- Réglages qui manquent pour que les courses soient assignées automatiquement : bandeau qui appelle à l'action -->
  <section v-if="items.length && !hidden" class="card border-l-4 border-orange-300 border-l-orange-500 bg-orange-50 p-3 sm:p-4" role="region" aria-label="Réglages à faire">
    <div class="flex flex-wrap items-start gap-3">
      <span class="text-2xl" aria-hidden="true">⚠️</span>
      <div class="min-w-[14rem] flex-1">
        <p class="font-semibold text-orange-900">
          {{ items.length }} réglage{{ items.length > 1 ? 's' : '' }} à faire pour que les courses soient assignées automatiquement aux livreurs
        </p>
        <p v-if="!expanded" class="text-sm text-orange-800"><b>{{ items[0].text }}</b> : {{ items[0].why }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <RouterLink :to="items[0].to" class="rounded-lg bg-orange-600 px-3 py-2 text-sm font-semibold text-white hover:bg-orange-700">{{ items[0].action }}</RouterLink>
        <button v-if="items.length > 1" type="button" class="rounded-lg border border-orange-300 bg-white px-3 py-2 text-sm text-orange-900" :aria-expanded="expanded" @click="expanded = !expanded">
          {{ expanded ? 'Masquer' : `Voir les ${items.length} réglages` }}
        </button>
        <button type="button" class="px-1 text-sm text-orange-500 hover:text-orange-700" title="Cacher jusqu'à demain" @click="later">Plus tard ✕</button>
      </div>
    </div>

    <ul v-if="expanded" class="mt-3 space-y-2">
      <li v-for="i in items" :key="i.key" class="flex flex-wrap items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm ring-1 ring-orange-200">
        <span class="min-w-[12rem] flex-1"><b class="text-orange-900">{{ i.text }}</b><span class="block text-gray-600">{{ i.why }}</span></span>
        <RouterLink :to="i.to" class="rounded-lg border border-orange-300 px-3 py-1.5 font-medium text-orange-900 hover:bg-orange-100">{{ i.action }}</RouterLink>
      </li>
    </ul>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'

/**
 * Bandeau de la page Courses (repris de Jibiat) : ce qui empêche l'assignation automatique (planning, réglages,
 * contrats MAD sans livreur), chaque ligne avec la page ou le réglage à ouvrir. « Plus tard » : caché pour la journée.
 */
const auth = useAuthStore()
const items = ref([])
const expanded = ref(false)
const hidden = ref(false)

const today = new Date().toLocaleDateString('sv-SE')
const storeKey = computed(() => `auto-assign-later:${auth.user?.id}`)

function later() {
  hidden.value = true
  try {
    localStorage.setItem(storeKey.value, today)
  } catch {
    // Stockage indisponible : caché jusqu'au prochain chargement
  }
}

onMounted(async () => {
  if (!auth.can('settings.manage') || !auth.user?.company_id) return
  try {
    hidden.value = localStorage.getItem(storeKey.value) === today
  } catch {
    hidden.value = false
  }
  try {
    items.value = (await http.get('/reports/auto-assign-checks')).data.data
  } catch {
    items.value = []
  }
})
</script>
