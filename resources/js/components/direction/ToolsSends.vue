<template>
  <div class="space-y-4">
    <!-- Point par e-mail : chaque lundi, le 1er du mois -->
    <IntegrationsTab :only="['email']" />

    <section class="card space-y-2 p-4">
      <h2 class="font-semibold">Modèles d'export envoyés à date fixe</h2>
      <p class="text-sm text-slate-600">Un modèle enregistré dans « Export avancé » peut partir tout seul, sur la semaine ou le mois écoulé, aux adresses du point par e-mail ci-dessus.</p>
      <p v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</p>
      <p v-if="templates && !templates.length" class="text-sm text-slate-600">Aucun modèle enregistré pour l'instant.</p>
      <ul v-else-if="templates" class="divide-y text-sm">
        <li v-for="t in templates" :key="t.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
          <span><b>{{ t.name }}</b> <span class="text-slate-600">· {{ formats[t.format] }} · {{ t.sections.length }} rubrique(s)</span></span>
          <span>
            <label class="sr-only" :for="`sd-${t.id}`">Envoi du modèle {{ t.name }}</label>
            <select :id="`sd-${t.id}`" :value="t.schedule || ''" class="input w-auto py-1" @change="schedule(t, $event.target.value)">
              <option value="">Pas d'envoi</option>
              <option value="weekly">Chaque lundi</option>
              <option value="monthly">Le 1er du mois</option>
            </select>
          </span>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import IntegrationsTab from '../stats/IntegrationsTab.vue'

const templates = ref(null)
const formats = ref({})
const error = ref('')

async function schedule(t, value) {
  error.value = ''
  try {
    templates.value = (await http.put(`/stats/export/templates/${t.id}/schedule`, { schedule: value || null })).data.data.templates
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

http.get('/stats/export/options').then((r) => {
  templates.value = r.data.data.templates
  formats.value = r.data.data.formats
}).catch((e) => { error.value = apiErrorMessage(e) })
</script>
