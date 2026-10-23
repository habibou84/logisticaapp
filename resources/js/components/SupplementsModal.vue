<template>
  <Modal :open="open" :title="`Suppléments · ${merchant?.business_name || ''}`" @close="$emit('close')">
    <div class="space-y-4">
      <p class="text-sm text-gray-600">
        Demandes exceptionnelles du client, ajoutées à part sur sa facture du mois ({{ activityLabel(activity) }}). Le client les voit
        dans son application. Elles ne changent plus une fois la facture du mois émise.
      </p>

      <div class="flex flex-wrap items-end gap-2">
        <div><label class="label" for="sp-month">Mois</label><input id="sp-month" v-model="month" type="month" class="input w-auto" @change="load"></div>
        <p class="text-sm ml-auto">Total du mois : <strong>{{ money(total) }}</strong></p>
      </div>

      <ul class="divide-y rounded border text-sm">
        <li v-for="s in supplements" :key="s.id" class="p-2 flex flex-wrap items-start gap-2">
          <span class="text-gray-500 w-12 shrink-0">{{ date(s.performed_on).slice(0, 5) }}</span>
          <span class="flex-1 min-w-40">
            {{ s.label }}
            <span v-if="s.order" class="text-xs text-gray-500"> · course {{ s.order.tracking_code }}</span>
            <span v-if="s.quantity > 1" class="block text-xs text-gray-500">{{ s.quantity }} × {{ money(s.unit_price) }}</span>
          </span>
          <strong class="whitespace-nowrap">{{ money(s.amount) }}</strong>
          <span v-if="s.locked" class="text-xs text-gray-500">facturé {{ s.invoice_number || '' }}</span>
          <button v-else-if="canManage" type="button" class="text-xs text-red-600" @click="remove(s)">Retirer</button>
        </li>
        <li v-if="!supplements.length" class="p-3 text-gray-500">{{ loading ? 'Chargement…' : 'Aucun supplément ce mois-ci.' }}</li>
      </ul>

      <form v-if="canManage" class="space-y-2 border-t pt-3" @submit.prevent="add">
        <h3 class="font-semibold text-sm">Ajouter un supplément</h3>
        <p v-if="error" class="field-error">{{ error }}</p>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
          <div class="col-span-2 sm:col-span-4"><label class="label" for="sp-label">Libellé</label><input id="sp-label" v-model="draft.label" class="input" maxlength="255" required placeholder="Ex. : livraison urgente le dimanche, mise sous pli…"></div>
          <div><label class="label" for="sp-date">Date</label><input id="sp-date" v-model="draft.performed_on" type="date" class="input" required></div>
          <div><label class="label" for="sp-qty">Quantité</label><input id="sp-qty" v-model.number="draft.quantity" type="number" min="1" class="input" required></div>
          <div><label class="label" for="sp-price">Prix unitaire (F)</label><input id="sp-price" v-model.number="draft.unit_price" type="number" min="0" class="input" required></div>
          <div class="flex items-end"><p class="text-sm">= <strong>{{ money((draft.quantity || 0) * (draft.unit_price || 0)) }}</strong></p></div>
        </div>
        <p v-if="order" class="text-xs text-gray-500">Lié à la course {{ order.tracking_code }}.</p>
        <button class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Ajouter à la facture du mois' }}</button>
      </form>
    </div>
  </Modal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import Modal from './Modal.vue'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toasts'
import { activityLabel } from '../utils/activities'
import { date, money, today } from '../utils/format'

// order : course à l'origine du supplément (fiche course), facultatif
const props = defineProps({
  open: Boolean,
  merchant: { type: Object, default: null },
  activity: { type: String, default: 'distribution' },
  order: { type: Object, default: null },
})
const emit = defineEmits(['close', 'saved'])

const auth = useAuthStore()
const toasts = useToastStore()
const canManage = computed(() => auth.can(['invoices.manage', 'rates.manage']))
const month = ref(today().slice(0, 7))
const supplements = ref([])
const total = ref(0)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const draft = reactive({})

function resetDraft() {
  Object.assign(draft, {
    label: props.order ? `Course ${props.order.tracking_code} : ` : '',
    performed_on: today(),
    quantity: 1,
    unit_price: null,
  })
  error.value = ''
}

async function load() {
  if (!props.merchant) return
  loading.value = true
  try {
    const { data } = await http.get('/client-supplements', { params: { merchant_id: props.merchant.id, activity: props.activity, month: month.value } })
    supplements.value = data.data
    total.value = data.total
  } finally {
    loading.value = false
  }
}

watch(() => [props.open, props.merchant?.id, props.order?.id], ([open]) => {
  if (!open) return
  month.value = today().slice(0, 7)
  resetDraft()
  load()
}, { immediate: true })

async function add() {
  saving.value = true
  error.value = ''
  try {
    await http.post('/client-supplements', {
      ...draft,
      merchant_id: props.merchant.id,
      activity: props.activity,
      order_id: props.order?.id || undefined,
    })
    toasts.success('Supplément ajouté à la facture du mois.')
    month.value = draft.performed_on.slice(0, 7)
    resetDraft()
    await load()
    emit('saved')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function remove(s) {
  if (!window.confirm(`Retirer le supplément « ${s.label} » ?`)) return
  try {
    await http.delete(`/client-supplements/${s.id}`)
    await load()
    emit('saved')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}
</script>
