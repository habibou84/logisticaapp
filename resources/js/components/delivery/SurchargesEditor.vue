<template>
  <!-- Suppléments d'une grille : express, fragile, tranches de poids -->
  <section class="card space-y-3 p-4">
    <div>
      <h2 class="font-semibold">Suppléments</h2>
      <p class="text-sm text-slate-600">Ajoutés au prix de la zone : un montant fixe, ou un pourcentage du prix.</p>
    </div>
    <p v-if="!loaded" class="text-sm text-slate-600">Chargement…</p>
    <template v-else>
      <p v-if="!surcharges.length" class="text-sm text-slate-600">Aucun supplément.</p>
      <div v-for="(s, i) in surcharges" :key="i" class="flex flex-wrap items-end gap-2" @input="dirty = true" @change="dirty = true">
        <div>
          <label class="label" :for="`sur-type-${i}`">Type</label>
          <select :id="`sur-type-${i}`" v-model="s.type" class="input w-auto">
            <option value="express">Express</option>
            <option value="fragile">Fragile</option>
            <option value="weight">Poids</option>
          </select>
        </div>
        <template v-if="s.type === 'weight'">
          <div><label class="label" :for="`sur-min-${i}`">À partir de (kg)</label><input :id="`sur-min-${i}`" v-model.number="s.min_value" type="number" min="0" class="input w-24"></div>
          <div><label class="label" :for="`sur-max-${i}`">Jusqu'à (kg, exclu)</label><input :id="`sur-max-${i}`" v-model.number="s.max_value" type="number" min="0" class="input w-24"></div>
        </template>
        <div><label class="label" :for="`sur-amount-${i}`">Montant (F)</label><input :id="`sur-amount-${i}`" v-model.number="s.amount" type="number" min="0" class="input w-28"></div>
        <div><label class="label" :for="`sur-pct-${i}`">ou % du prix</label><input :id="`sur-pct-${i}`" v-model.number="s.percent" type="number" min="0" max="100" class="input w-20"></div>
        <button type="button" class="btn-secondary" @click="surcharges.splice(i, 1); dirty = true">Retirer</button>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" @click="dirty = true; surcharges.push({ type: 'express', amount: 0, percent: null, min_value: null, max_value: null })">+ Supplément</button>
        <button type="button" class="btn-primary" :disabled="saving || !dirty" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer les suppléments' }}</button>
      </div>
    </template>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'

const props = defineProps({ gridId: { type: Number, required: true } })

const toasts = useToastStore()
const surcharges = ref([])
const loaded = ref(false)
const dirty = ref(false)
const saving = ref(false)

async function load() {
  const grid = (await http.get(`/pricing-grids/${props.gridId}`)).data.data
  surcharges.value = grid.surcharges.map((s) => ({ ...s }))
  dirty.value = false
  loaded.value = true
}

async function save() {
  saving.value = true
  try {
    await http.put(`/pricing-grids/${props.gridId}/surcharges`, {
      surcharges: surcharges.value.map(({ type, min_value, max_value, amount, percent }) => ({
        type, min_value, max_value, amount: amount || 0, percent: percent || null,
      })),
    })
    toasts.success('Suppléments enregistrés.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

onBeforeRouteLeave(() => !dirty.value || window.confirm('Des suppléments modifiés ne sont pas enregistrés. Quitter quand même ?'))
function warnBeforeUnload(event) {
  if (dirty.value) event.preventDefault()
}
window.addEventListener('beforeunload', warnBeforeUnload)
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload))

onMounted(load)
</script>
