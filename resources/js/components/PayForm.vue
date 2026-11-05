<template>
  <form class="space-y-2 border-t pt-3" @submit.prevent="$emit('pay', { method, transaction_ref: method !== 'compensation' && reference ? reference : undefined })">
    <div class="grid grid-cols-2 gap-2">
      <div>
        <label class="label" for="pay-method">Payé par</label>
        <select id="pay-method" v-model="method" class="input">
          <option v-for="(label, value) in PAYMENT_METHODS" :key="value" :value="value">{{ label }}</option>
          <option v-if="compensation" value="compensation">Gardé sur l'argent encaissé</option>
        </select>
      </div>
      <div v-if="method !== 'compensation'">
        <label class="label" for="pay-ref">Référence</label>
        <input id="pay-ref" v-model="reference" class="input" placeholder="N° de transaction">
      </div>
    </div>
    <p v-if="method === 'compensation'" class="text-xs text-gray-600">
      Le livreur garde ce montant sur l'argent des clients qu'il a en main : il le versera en moins lors de son prochain point.
    </p>
    <div class="flex gap-2">
      <button type="submit" class="btn-success flex-1">Marquer comme payé</button>
      <button type="button" class="btn-secondary text-red-700" @click="$emit('cancel')">{{ cancelLabel }}</button>
    </div>
  </form>
</template>

<script setup>
import { ref, watch } from 'vue'
import { PAYMENT_METHODS } from '../utils/format'

// compensation : paie d'un livreur, qui peut la garder sur l'argent encaissé
// method : moyen proposé (celui du marchand, au relevé d'un reversement)
// cancelLabel : le bouton annule le paiement (ou la fiche) lui-même, pas le formulaire
const props = defineProps({ compensation: { type: Boolean, default: false }, method: { type: String, default: null }, cancelLabel: { type: String, default: 'Annuler ce paiement' } })
defineEmits(['pay', 'cancel'])
const method = ref(props.method || 'wave')
watch(() => props.method, (m) => { if (m) method.value = m })
const reference = ref('')
</script>
