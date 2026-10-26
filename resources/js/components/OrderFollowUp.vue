<template>
  <div class="card p-4 space-y-2">
    <h2 class="font-semibold">Note de suivi <span class="text-xs font-normal text-gray-500">· équipe</span></h2>
    <div v-if="order.follow_up && !editing" class="rounded-lg px-3 py-2 text-sm" :class="order.follow_up.holds ? 'bg-amber-50 text-amber-900' : 'bg-slate-50'">
      <p class="font-medium">{{ order.follow_up.holds ? '⏸ ' : '📌 ' }}{{ order.follow_up.label }}</p>
      <p v-if="order.follow_up.note">{{ order.follow_up.note }}</p>
      <p class="text-xs text-gray-500">{{ order.follow_up.by }}<template v-if="order.follow_up.at"> · {{ dateTime(order.follow_up.at) }}</template></p>
      <p v-if="order.follow_up.holds" class="text-xs mt-1">En attente : ni attribution automatique, ni file « sans livreur ».</p>
    </div>
    <div v-if="order.follow_up && !editing" class="flex gap-2">
      <button type="button" class="btn-secondary flex-1" @click="start">Modifier</button>
      <button type="button" class="btn-secondary flex-1" :disabled="saving" @click="clear">✓ Lever</button>
    </div>
    <button v-else-if="!editing" type="button" class="btn-secondary w-full" @click="start">📌 Poser une note de suivi</button>

    <form v-if="editing" class="space-y-2" @submit.prevent="save">
      <select v-model="form.reason_id" class="input" required aria-label="Note de suivi">
        <option :value="null" disabled>Choisir…</option>
        <option v-for="r in reasons" :key="r.id" :value="r.id">{{ r.label }}{{ r.holds ? ' (met en attente)' : '' }}</option>
      </select>
      <input v-if="selected?.requires_date" v-model="form.date" type="date" :min="today()" class="input" required aria-label="Date">
      <textarea v-model="form.note" rows="2" class="input" maxlength="500" :required="!!selected?.requires_note"
        :placeholder="selected?.requires_note ? 'Précisez (obligatoire)' : 'Précision (facultatif)'" />
      <p v-if="selected?.visible_to_client" class="text-xs text-gray-500">👁 Le client verra cette note dans son suivi.</p>
      <p v-if="error" class="text-red-600 text-sm">{{ error }}</p>
      <div class="flex gap-2">
        <button type="submit" class="btn-primary flex-1" :disabled="saving || !form.reason_id">Enregistrer</button>
        <button type="button" class="btn-secondary" @click="editing = false">Annuler</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { cachedGet } from '../composables/useCachedApi'
import { dateTime, today } from '../utils/format'

/**
 * Note de suivi de l'équipe sur une course (« En attente de l'adresse », « Réceptionne le »…). Réglages › Motifs.
 * Elle se lève d'elle-même quand la course repart en livraison ou se termine.
 */
const props = defineProps({ order: { type: Object, required: true } })
const emit = defineEmits(['changed'])

const all = ref([])
const editing = ref(false)
const saving = ref(false)
const error = ref('')
const form = reactive({ reason_id: null, note: '', date: null })
const reasons = computed(() => all.value.filter((r) => r.applies_to === 'follow_up'))
const selected = computed(() => reasons.value.find((r) => r.id === form.reason_id))

function start() {
  Object.assign(form, { reason_id: props.order.follow_up?.reason_id ?? null, note: props.order.follow_up?.note ?? '', date: null })
  error.value = ''
  editing.value = true
}

async function send(payload) {
  saving.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/orders/${props.order.id}/follow-up`, payload)
    editing.value = false
    emit('changed', data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

const save = () => send({ reason_id: form.reason_id, note: form.note || null, date: selected.value?.requires_date ? form.date : null })
const clear = () => send({ reason_id: null })

onMounted(async () => {
  all.value = (await cachedGet('/incident-reasons').catch(() => ({ data: { data: [] } }))).data.data
})
</script>
