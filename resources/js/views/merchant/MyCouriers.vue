<template>
  <!-- Livreurs préférés ou écartés (si l'entreprise le permet) -->
  <div class="space-y-4">
    <p class="text-sm text-slate-600">
      Votre livreur préféré reçoit vos courses en priorité quand il est disponible ; un livreur écarté ne les reçoit plus
      automatiquement. Choisissez-les en notant une course.
    </p>
    <p v-if="!loading && !enabled" class="m-card p-4 text-sm text-slate-600">L'agence ne propose pas le choix des livreurs.</p>
    <div v-else class="m-card divide-y divide-slate-100 overflow-hidden">
      <div v-for="p in list" :key="p.courier_id" class="flex items-center gap-3 px-4 py-3.5">
        <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-lg', p.kind === 'preferred' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700']"><StatusIcon name="user" class="h-5 w-5" /></span>
        <span class="min-w-0 flex-1"><strong class="block">{{ p.name }}</strong><span class="text-sm text-slate-600">{{ p.kind === 'preferred' ? 'Mon livreur préféré' : 'Écarté de mes courses' }}</span></span>
        <button type="button" class="text-sm font-semibold text-[var(--app-color)]" @click="remove(p)">Retirer</button>
      </div>
      <p v-if="!loading && !list.length" class="p-4 text-sm text-slate-500">Aucun livreur préféré ni écarté.</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import StatusIcon from '../../components/mobile/StatusIcon.vue'

const list = ref([])
const enabled = ref(false)
const loading = ref(true)

async function load() {
  try {
    const { data } = await http.get('/merchant/courier-preferences')
    list.value = data.data
    enabled.value = data.enabled
  } finally {
    loading.value = false
  }
}

async function remove(p) {
  const { data } = await http.put(`/merchant/courier-preferences/${p.courier_id}`, { kind: null })
  list.value = data.data
}

onMounted(load)
</script>
