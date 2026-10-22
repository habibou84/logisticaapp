<template>
  <Modal :open="open" :title="`Déclarer perdu · ${order?.tracking_code || ''}`" @close="$emit('close')">
    <form v-if="order" class="space-y-3" @submit.prevent="submit">
      <p class="rounded bg-red-50 p-3 text-sm text-red-800">
        La course passe « Perdu » (définitif). L'indemnité est portée au compte du marchand et lui est
        versée avec son prochain reversement ; la retenue éventuelle s'applique à la prochaine paie du livreur.
      </p>
      <div>
        <label class="label" for="lost-reason">Circonstances *</label>
        <textarea id="lost-reason" v-model="form.reason" class="input" rows="2" maxlength="500" required placeholder="Ex. : introuvable après le point de caisse, livreur injoignable depuis 3 jours…" />
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="lost-comp">Indemnité au marchand (F) *</label>
          <input id="lost-comp" v-model.number="form.compensation" type="number" min="0" class="input" required>
          <p class="text-xs text-gray-500 mt-1">Valeur déclarée des articles : {{ money(order.amounts.items_amount) }}</p>
        </div>
        <div>
          <label class="label" for="lost-ded">Retenue sur le livreur (F)</label>
          <input id="lost-ded" v-model.number="form.courier_deduction" type="number" min="0" class="input">
          <p class="text-xs text-gray-500 mt-1">0 si le livreur n'est pas en faute.</p>
        </div>
      </div>
      <div v-if="form.courier_deduction > 0">
        <label class="label" for="lost-courier">Livreur responsable *</label>
        <select id="lost-courier" v-model="form.responsible_courier_id" class="input" required>
          <option :value="null" disabled>Choisir…</option>
          <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}{{ order.held_by?.id === c.id ? ' · avait le colis' : '' }}</option>
        </select>
      </div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="btn-danger w-full" :disabled="busy || !form.reason">Confirmer la perte du colis</button>
    </form>
  </Modal>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import Modal from './Modal.vue'
import { money } from '../utils/format'

const props = defineProps({
  open: { type: Boolean, default: false },
  order: { type: Object, default: null },
  couriers: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'declared'])

const form = reactive({ reason: '', compensation: 0, courier_deduction: 0, responsible_courier_id: null })
const busy = ref(false)
const error = ref('')

watch(() => props.open, (open) => {
  if (!open || !props.order) return
  Object.assign(form, { reason: '', compensation: props.order.amounts.items_amount || 0, courier_deduction: 0, responsible_courier_id: props.order.held_by?.id ?? null })
  error.value = ''
})

async function submit() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/orders/${props.order.id}/lost`, {
      reason: form.reason,
      compensation: form.compensation || 0,
      courier_deduction: form.courier_deduction || 0,
      responsible_courier_id: form.courier_deduction > 0 ? form.responsible_courier_id : undefined,
    })
    emit('declared', data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>
