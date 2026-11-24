<template>
  <!-- Ramassé, date de livraison dépassée : retour (qui le rapporte) ou report (nouvelle date) d'un ou plusieurs colis -->
  <Modal :open="!!orders.length" :title="title" @close="emit('close')">
    <div class="space-y-4 text-sm">
      <template v-if="mode === 'return'">
        <p>{{ orders.length > 1 ? `Les ${orders.length} colis retournent` : 'Le colis retourne' }} à <b>{{ merchants }}</b>. Qui {{ orders.length > 1 ? 'les rapporte' : 'le rapporte' }} ?</p>
        <label v-if="holders.length" :class="choiceClass('holder')">
          <input v-model="who" type="radio" value="holder" class="mt-1">
          <span><b>{{ holders.length === 1 && orders.length === 1 ? holders[0] : 'Le livreur qui a déjà chaque colis' }}</b><template v-if="orders.length === 1"> · a déjà le colis</template>
            <span class="block text-slate-600">Conseillé : pas de relais à faire{{ withoutHolder ? ` (${withoutHolder} au dépôt : à confier plus tard)` : '' }}</span></span>
        </label>
        <label :class="choiceClass('other')">
          <input v-model="who" type="radio" value="other" class="mt-1">
          <span class="flex-1"><b>Choisir un livreur</b>
            <span class="block text-slate-600">Un colis encore chez un autre livreur doit d'abord lui être remis.</span>
            <SearchSelect
              v-if="who === 'other'" v-model="courierId" input-class="input mt-2" placeholder="Livreur…" :allow-empty="false" :empty-value="null" aria-label="Livreur du retour"
              :options="couriers.map((c) => ({ value: c.id, label: c.name, hint: c.is_available === false ? 'indisponible' : '', search: c.phone }))"
            />
          </span>
        </label>
        <label :class="choiceClass('later')">
          <input v-model="who" type="radio" value="later" class="mt-1">
          <span><b>Plus tard</b><span class="block text-slate-600">Le colis attend dans Courses › Retours clients, pour un bon de retour.</span></span>
        </label>
      </template>

      <template v-else>
        <p class="font-medium">Nouvelle date de livraison</p>
        <div class="flex flex-wrap items-center gap-2">
          <button v-for="d in quickDays" :key="d.value" type="button" :class="['rounded-full px-4 py-1.5 ring-1', date === d.value ? 'bg-slate-900 font-semibold text-white ring-transparent' : 'bg-white ring-slate-300']" @click="date = d.value">{{ d.label }}</button>
          <input v-model="date" type="date" class="input w-auto" :min="tomorrow" aria-label="Autre date">
        </div>
        <label class="flex items-start gap-2 rounded-xl border p-3">
          <input v-model="keepCourier" type="checkbox" class="mt-1">
          <span><b>Garder le livreur qui a le colis</b> pour la livraison<span class="block text-slate-600">Sinon le colis attend un livreur pour la nouvelle date.</span></span>
        </label>
        <p class="text-slate-600">Le client voit la nouvelle date sur sa course.</p>
      </template>

      <ul v-if="errors.length" class="space-y-1 rounded-lg bg-red-50 p-3 text-red-800" role="alert">
        <li v-for="e in errors" :key="e.id">{{ e.message }}</li>
      </ul>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="emit('close')">Annuler</button>
        <button type="button" class="btn-primary" :disabled="busy || !canSubmit" @click="submit">{{ submitLabel }}</button>
      </div>
    </div>
  </Modal>
</template>

<script setup>
import SearchSelect from './SearchSelect.vue'
import { computed, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import Modal from './Modal.vue'

const props = defineProps({
  orders: { type: Array, default: () => [] },
  mode: { type: String, default: 'return' },
  couriers: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'done'])
const toasts = useToastStore()

const iso = (d) => d.toLocaleDateString('sv-SE')
const shift = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return iso(d) }
const tomorrow = shift(1)
const quickDays = [{ value: shift(1), label: 'Demain' }, { value: shift(2), label: 'Après-demain' }]

const who = ref('holder')
const courierId = ref(null)
const date = ref(tomorrow)
const keepCourier = ref(true)
const busy = ref(false)
const errors = ref([])

const holders = computed(() => [...new Set(props.orders.map((o) => o.held_by?.name).filter(Boolean))])
const withoutHolder = computed(() => props.orders.filter((o) => !o.held_by).length)
const merchants = computed(() => [...new Set(props.orders.map((o) => o.merchant?.business_name).filter(Boolean))].join(', ') || 'son client')
const one = computed(() => props.orders.length === 1 ? props.orders[0].tracking_code : `${props.orders.length} colis`)
const title = computed(() => (props.mode === 'return' ? `Retour de ${one.value}` : `Reporter ${one.value}`))
const longDay = (v) => new Date(`${v}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
const canSubmit = computed(() => (props.mode === 'return' ? who.value !== 'other' || courierId.value : !!date.value))
const submitLabel = computed(() => (props.mode === 'return' ? (who.value === 'later' ? 'Décider le retour' : 'Confier le retour') : `Reporter au ${longDay(date.value)}`))
const choiceClass = (value) => ['flex cursor-pointer items-start gap-2 rounded-xl border p-3', who.value === value ? 'border-2 border-emerald-600 bg-emerald-50' : '']

watch(() => props.orders, (list) => {
  if (!list.length) return
  who.value = list.some((o) => o.held_by) ? 'holder' : 'other'
  courierId.value = null
  date.value = tomorrow
  keepCourier.value = true
  errors.value = []
})

async function submit() {
  busy.value = true
  errors.value = []
  try {
    const payload = props.mode === 'return'
      ? { decision: 'return', holder: who.value === 'holder', courier_id: who.value === 'other' ? courierId.value : null }
      : { decision: 'reschedule', date: date.value, keep_courier: keepCourier.value }
    const { data } = await http.post('/orders/picked-late/decide', { ...payload, order_ids: props.orders.map((o) => o.id) })
    errors.value = data.data.errors
    toasts.success(props.mode === 'return' ? `Retour décidé pour ${data.data.done} colis.` : `${data.data.done} colis reporté(s) au ${longDay(date.value)}.`)
    emit('done')
    if (!errors.value.length) emit('close')
  } catch (e) {
    errors.value = e.response?.data?.data?.errors || [{ id: 0, message: apiErrorMessage(e) }]
  } finally {
    busy.value = false
  }
}
</script>
