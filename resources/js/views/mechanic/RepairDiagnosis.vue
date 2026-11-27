<template>
  <form v-if="repair" class="space-y-5" @submit.prevent="save">
    <p class="text-sm text-slate-600">Sur place depuis {{ time(repair.arrived_at) }} · {{ duration(repair.arrived_at) }}</p>

    <fieldset>
      <legend class="m-label">Type de panne</legend>
      <div class="grid grid-cols-3 gap-2">
        <button v-for="c in mechanicSetup.categories" :key="c.key" type="button" :aria-pressed="form.category === c.key"
                :class="['tap rounded-xl border-[1.5px] px-1 py-2.5 text-center text-xs font-semibold', form.category === c.key ? 'border-[var(--app-color)] bg-orange-50 text-orange-900' : 'border-slate-200 bg-white text-slate-700']"
                @click="form.category = c.key">
          <span class="mb-0.5 grid place-items-center text-xl" aria-hidden="true"><Icone :nom="c.icon || 'atelier'" /></span>{{ c.label }}
        </button>
      </div>
    </fieldset>

    <div>
      <label for="diag-part" class="m-label">Pièce touchée</label>
      <input id="diag-part" v-model="form.part" class="m-input" maxlength="120" placeholder="Ex. : cosse de batterie oxydée">
    </div>

    <div>
      <p class="m-label">Photos avant réparation</p>
      <RepairPhotos :repair="repair" kind="before" editable @updated="repair = $event" />
    </div>

    <fieldset>
      <legend class="m-label">Décision</legend>
      <div class="space-y-2">
        <label v-for="d in mechanicSetup.decisions" :key="d.key" :class="['tap flex items-center gap-3 rounded-xl border-[1.5px] bg-white px-4 py-3', form.decision === d.key ? 'border-[var(--app-color)] bg-orange-50' : 'border-slate-200']">
          <input v-model="form.decision" type="radio" name="decision" :value="d.key" class="h-5 w-5 accent-[var(--app-color)]">
          <span class="font-medium">{{ d.label }}</span>
        </label>
      </div>
      <p v-if="form.decision && form.decision !== 'fixed'" class="mt-2 text-sm text-amber-800">L'agence est prévenue tout de suite pour organiser {{ form.decision === 'towed' ? 'le remorquage' : 'le véhicule de remplacement' }}.</p>
    </fieldset>

    <div>
      <label for="diag-note" class="m-label">Remarque (facultatif)</label>
      <textarea id="diag-note" v-model="form.diagnosis" class="m-input" rows="2" maxlength="2000" placeholder="Ce que vous avez constaté" />
    </div>

    <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
    <button type="submit" class="m-btn-primary text-lg" :disabled="busy || !form.category || !form.decision">{{ repair.status === 'repairing' ? 'Enregistrer' : 'Commencer la réparation' }}</button>
  </form>
  <p v-else-if="error" class="py-10 text-center text-slate-600">{{ error }}</p>
  <p v-else class="py-10 text-center text-slate-400">Chargement…</p>
</template>

<script setup>
import Icone from '../../components/ui/Icone.vue'
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import RepairPhotos from '../../components/mechanic/RepairPhotos.vue'
import { duration, loadBoard, loadMechanicSetup, mechanicSetup, time } from '../../composables/useMechanic'

const route = useRoute()
const router = useRouter()
const repair = ref(null)
const error = ref('')
const busy = ref(false)
const form = reactive({ category: '', part: '', decision: 'fixed', diagnosis: '' })

onMounted(async () => {
  try {
    await loadMechanicSetup()
    repair.value = (await http.get(`/mechanic/repairs/${route.params.id}`)).data.data
    Object.assign(form, {
      category: repair.value.category || '',
      part: repair.value.part || '',
      decision: repair.value.decision || 'fixed',
      diagnosis: repair.value.diagnosis || '',
    })
  } catch (e) {
    error.value = apiErrorMessage(e, 'Panne introuvable.')
  }
})

async function save() {
  busy.value = true
  error.value = ''
  try {
    await http.post(`/mechanic/repairs/${repair.value.id}/start`, { ...form, at: new Date().toISOString() })
    loadBoard()
    router.replace(`/mecanicien/pannes/${repair.value.id}`)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Diagnostic pas enregistré. Vérifiez votre réseau.')
  } finally {
    busy.value = false
  }
}
</script>
