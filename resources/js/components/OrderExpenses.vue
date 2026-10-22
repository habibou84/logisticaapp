<template>
  <div class="card p-4 text-sm space-y-3">
    <div class="flex items-center justify-between">
      <h2 class="font-semibold">Frais de la course</h2>
      <button v-if="canAdd && !form.open" class="text-blue-600 text-xs underline" @click="form.open = true">Ajouter des frais</button>
    </div>

    <ul v-if="expenses.length" class="space-y-2">
      <li v-for="e in expenses" :key="e.id" :class="['flex justify-between gap-2', e.cancelled_at ? 'line-through text-gray-400' : '']">
        <span>
          {{ e.description }}
          <span class="block text-xs text-gray-500">
            {{ e.paid_by === 'courier' ? `Payé par ${e.courier_name || 'le livreur'}${e.refunded ? ' (remboursé)' : ''}` : 'Payé par l\'agence' }}
            · {{ e.billed_to === 'merchant' ? 'facturé au marchand' : 'à la charge de l\'agence' }}
          </span>
        </span>
        <span class="flex items-start gap-2">
          <span class="font-semibold whitespace-nowrap">{{ money(e.amount) }}</span>
          <button v-if="canAdd && !e.cancelled_at && !e.refunded" class="text-red-600 text-xs" :aria-label="`Annuler ${e.description}`" @click="cancel(e)">Annuler</button>
        </span>
      </li>
    </ul>
    <p v-else-if="!form.open" class="text-gray-500">Aucun frais.</p>

    <form v-if="form.open" class="space-y-2 border-t pt-3" @submit.prevent="save">
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="label" for="exp-type">Type</label>
          <select id="exp-type" v-model="form.type" class="input">
            <option v-for="(label, value) in TYPES" :key="value" :value="value">{{ label }}</option>
          </select>
        </div>
        <div><label class="label" for="exp-amount">Montant (F)</label><input id="exp-amount" v-model.number="form.amount" type="number" min="1" class="input" required></div>
      </div>
      <div><label class="label" for="exp-label">Précision{{ form.type === 'other' ? ' *' : '' }}</label><input id="exp-label" v-model="form.label" class="input" :required="form.type === 'other'"></div>
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="label" for="exp-paid">Payé par</label>
          <select id="exp-paid" v-model="form.paid_by" class="input">
            <option value="courier">Le livreur (remboursé à son versement)</option>
            <option value="company">L'agence</option>
          </select>
        </div>
        <div>
          <label class="label" for="exp-billed">À la charge de</label>
          <select id="exp-billed" v-model="form.billed_to" class="input">
            <option value="merchant">Marchand (déduit de son point)</option>
            <option value="company">Agence</option>
          </select>
        </div>
      </div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <div class="flex gap-2">
        <button class="btn-primary" :disabled="saving">Enregistrer</button>
        <button type="button" class="btn-secondary" @click="form.open = false">Fermer</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { money } from '../utils/format'

const TYPES = {
  transport: 'Transport (taxi, moto-taxi…)',
  packaging: 'Emballage',
  parking: 'Stationnement, péage',
  shipping: 'Frais de gare / expédition',
  other: 'Autres frais',
}

const props = defineProps({
  orderId: { type: Number, required: true },
  expenses: { type: Array, default: () => [] },
  canAdd: { type: Boolean, default: false },
})
const emit = defineEmits(['changed'])
const toasts = useToastStore()
const form = reactive({ open: false, type: 'transport', amount: null, label: '', paid_by: 'courier', billed_to: 'merchant' })
const error = ref('')
const saving = ref(false)

async function save() {
  saving.value = true
  error.value = ''
  try {
    await http.post(`/orders/${props.orderId}/expenses`, {
      type: form.type, amount: form.amount, label: form.label || undefined, paid_by: form.paid_by, billed_to: form.billed_to,
    })
    Object.assign(form, { open: false, amount: null, label: '' })
    toasts.success('Frais enregistrés.')
    emit('changed')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function cancel(expense) {
  if (!window.confirm(`Annuler « ${expense.description} » (${money(expense.amount)}) ?`)) return
  try {
    await http.post(`/orders/${props.orderId}/expenses/${expense.id}/cancel`)
    toasts.success('Frais annulés.')
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}
</script>
