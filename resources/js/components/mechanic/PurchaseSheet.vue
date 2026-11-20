<template>
  <BottomSheet :open="open" title="Acheter une pièce sur place" @close="$emit('close')">
    <form class="space-y-3" @submit.prevent="send">
      <p class="text-sm text-slate-600">Pièce manquante ou magasin fermé : l'administrateur doit d'abord accepter. Vous serez prévenu de sa réponse.</p>
      <div>
        <label for="purchase-label" class="m-label">Pièce</label>
        <input id="purchase-label" v-model="form.label" class="m-input" maxlength="120" placeholder="Ex. : batterie 12 V 7 Ah" required>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label for="purchase-qty" class="m-label">Quantité</label>
          <input id="purchase-qty" v-model.number="form.quantity" class="m-input" type="number" min="1" max="100" inputmode="numeric">
        </div>
        <div>
          <label for="purchase-amount" class="m-label">Prix estimé (F)</label>
          <input id="purchase-amount" v-model.number="form.estimated_amount" class="m-input" type="number" min="1" inputmode="numeric" required>
        </div>
      </div>
      <div>
        <label for="purchase-reason" class="m-label">Pourquoi ?</label>
        <input id="purchase-reason" v-model="form.reason" class="m-input" maxlength="255" placeholder="Ex. : magasin fermé, rupture">
      </div>
      <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
      <button type="submit" class="m-btn-primary" :disabled="busy || !form.label || !form.estimated_amount">Demander l'accord</button>
    </form>
  </BottomSheet>
</template>

<script setup>
import { reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BottomSheet from '../mobile/BottomSheet.vue'

const props = defineProps({ open: { type: Boolean, default: false }, repairId: { type: Number, required: true } })
const emit = defineEmits(['close', 'sent'])
const form = reactive({ label: '', quantity: 1, estimated_amount: null, reason: '' })
const busy = ref(false)
const error = ref('')

async function send() {
  busy.value = true
  error.value = ''
  try {
    const repair = (await http.post(`/mechanic/repairs/${props.repairId}/purchases`, form)).data.data
    Object.assign(form, { label: '', quantity: 1, estimated_amount: null, reason: '' })
    emit('sent', repair)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Demande pas envoyée. Vérifiez votre réseau.')
  } finally {
    busy.value = false
  }
}
</script>
