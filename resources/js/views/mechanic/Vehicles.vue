<template>
  <div class="space-y-3">
    <input v-model="q" type="search" class="m-input" placeholder="Plaque, marque ou livreur" aria-label="Chercher un véhicule" @input="search">
    <SkeletonList v-if="loading && !vehicles.length" :rows="4" />
    <p v-else-if="error" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ error }}</p>
    <div v-else-if="vehicles.length" class="m-card divide-y">
      <RouterLink v-for="v in vehicles" :key="v.id" :to="`/mecanicien/vehicules/${v.id}`" class="flex items-center gap-3 p-3.5 active:bg-slate-50">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-slate-100" aria-hidden="true">{{ v.type === 'voiture' ? '🚗' : v.type === 'velo' ? '🚲' : '🛵' }}</span>
        <div class="min-w-0 flex-1">
          <p class="font-semibold">{{ v.plate || 'Sans plaque' }}</p>
          <p class="truncate text-sm text-slate-600">{{ [v.name, v.courier].filter(Boolean).join(' · ') }}</p>
        </div>
        <span :class="['shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold', v.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">{{ v.status_label }}</span>
      </RouterLink>
    </div>
    <EmptyState v-else icon="🛵" title="Aucun véhicule" :text="q ? 'Aucun véhicule ne correspond à votre recherche.' : 'Les véhicules du parc apparaissent ici.'" />
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import EmptyState from '../../components/mobile/EmptyState.vue'
import SkeletonList from '../../components/mobile/SkeletonList.vue'

const q = ref('')
const vehicles = ref([])
const loading = ref(false)
const error = ref('')
let timer

async function load() {
  loading.value = true
  try {
    vehicles.value = (await http.get('/mechanic/vehicles', { params: { q: q.value || undefined } })).data.data
    error.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e, 'Véhicules indisponibles. Vérifiez votre réseau.')
  } finally {
    loading.value = false
  }
}

function search() {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
}

onMounted(load)
</script>
