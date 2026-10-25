<template>
  <Modal :open="open" :title="department ? `Modifier ${department.name}` : 'Nouveau département'" @close="$emit('close')">
    <form class="space-y-3" @submit.prevent="save">
      <div class="grid grid-cols-[1fr_7rem] gap-3">
        <div><label class="label" for="d-name">Nom</label><input id="d-name" v-model="form.name" class="input" required maxlength="120" placeholder="Finance"></div>
        <div><label class="label" for="d-code">Code court</label><input id="d-code" v-model="form.code" class="input uppercase" maxlength="12" placeholder="FIN"></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="d-manager">Responsable</label><input id="d-manager" v-model="form.manager_name" class="input" maxlength="120" placeholder="Mme Koné"></div>
        <div><label class="label" for="d-phone">Téléphone</label><input id="d-phone" v-model="form.phone" class="input" inputmode="tel" placeholder="07 00 00 00 00"></div>
      </div>
      <div><label class="label" for="d-location">Emplacement (bâtiment, étage, bureau)</label><input id="d-location" v-model="form.location" class="input" maxlength="255" placeholder="Bâtiment A, 3e étage, bureau 302"></div>

      <fieldset class="rounded-lg border border-slate-200 p-3 space-y-2">
        <legend class="px-1 text-sm font-medium">Adresse</legend>
        <label class="flex items-center gap-2 text-sm"><input v-model="ownAddress" type="radio" :value="false"> À l'adresse du client</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="ownAddress" type="radio" :value="true"> Sur un autre site : ramassage et remise à son adresse</label>
        <template v-if="ownAddress">
          <div><label class="label" for="d-zone">Commune</label><ZoneSelect id="d-zone" v-model="form.pickup_zone_id" :zones="zones" required /></div>
          <div><label class="label" for="d-address">Adresse</label><input id="d-address" v-model="form.pickup_address" class="input" maxlength="500" placeholder="Rue du Commerce, immeuble Alpha"></div>
          <div><label class="label" for="d-landmark">Repère</label><input id="d-landmark" v-model="form.pickup_landmark" class="input" maxlength="255"></div>
        </template>
      </fieldset>

      <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="d-cc">Centre de coûts</label><input id="d-cc" v-model="form.cost_center" class="input" maxlength="60" placeholder="CC-100"></div>
        <div><label class="label" for="d-quota">Quota du mois <span class="font-normal text-gray-500">(envois)</span></label><input id="d-quota" v-model.number="form.monthly_quota" type="number" min="1" class="input" placeholder="Sans quota"></div>
      </div>
      <label class="flex items-start gap-2 text-sm"><input v-model="form.is_main" type="checkbox" class="mt-1"> <span>Département principal<span class="block text-xs text-gray-500">Paie le forfait quand rien n'est à répartir, les lots et les suppléments.</span></span></label>
      <label v-if="department" class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox"> Actif</label>
      <div v-if="staff"><label class="label" for="d-notes">Notes (internes à l'agence)</label><textarea id="d-notes" v-model="form.notes" rows="2" class="input" /></div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="busy">{{ department ? 'Enregistrer' : 'Ajouter le département' }}</button>
    </form>
  </Modal>
</template>

<script setup>
import { ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../Modal.vue'
import ZoneSelect from '../ZoneSelect.vue'

const props = defineProps({
  open: Boolean,
  merchantId: { type: Number, required: true },
  department: { type: Object, default: null },
  zones: { type: Array, default: () => [] },
  // Personnel de l'agence (notes internes)
  staff: Boolean,
})
const emit = defineEmits(['close', 'saved'])

const FIELDS = ['name', 'code', 'manager_name', 'phone', 'location', 'pickup_zone_id', 'pickup_address', 'pickup_landmark', 'cost_center', 'monthly_quota', 'is_main', 'active', 'notes']
const form = ref({})
const ownAddress = ref(false)
const busy = ref(false)
const error = ref('')

watch(() => [props.open, props.department], () => {
  if (!props.open) return
  const d = props.department || {}
  form.value = Object.fromEntries(FIELDS.map((k) => [k, d[k] ?? (k === 'active' ? true : k === 'is_main' ? false : null)]))
  ownAddress.value = !!d.own_address
  error.value = ''
}, { immediate: true })

async function save() {
  busy.value = true
  error.value = ''
  try {
    const payload = { ...form.value }
    if (!ownAddress.value) Object.assign(payload, { pickup_zone_id: null, pickup_address: null, pickup_landmark: null })
    if (!props.staff) delete payload.notes
    const { data } = props.department
      ? await http.put(`/departments/${props.department.id}`, payload)
      : await http.post(`/merchants/${props.merchantId}/departments`, payload)
    emit('saved', data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>
