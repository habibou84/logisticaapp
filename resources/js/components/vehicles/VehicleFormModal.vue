<template>
  <Modal :open="open" :title="vehicle ? `Modifier ${vehicle.plate}` : 'Ajouter un véhicule'" @close="$emit('close')">
    <form class="space-y-3" @submit.prevent="save">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="v-type">Type</label>
          <select id="v-type" v-model="form.type" class="input">
            <option v-for="(t, value) in VEHICLE_TYPES" v-show="value !== 'pieton'" :key="value" :value="value">{{ t.icon }} {{ t.label }}</option>
          </select>
        </div>
        <div><label class="label" for="v-plate">Immatriculation</label><input id="v-plate" v-model="form.plate" class="input uppercase" required maxlength="30" placeholder="AB-1234-CI"></div>
        <div><label class="label" for="v-brand">Marque</label><input id="v-brand" v-model="form.brand" class="input" placeholder="Yamaha"></div>
        <div><label class="label" for="v-model">Modèle</label><input id="v-model" v-model="form.model" class="input" placeholder="Crypton"></div>
        <div><label class="label" for="v-year">Année</label><input id="v-year" v-model.number="form.year" type="number" min="1950" max="2100" class="input"></div>
        <div><label class="label" for="v-color">Couleur</label><input id="v-color" v-model="form.color" class="input"></div>
        <div class="col-span-2"><label class="label" for="v-vin">Numéro de châssis</label><input id="v-vin" v-model="form.vin" class="input"></div>
      </div>

      <fieldset class="grid grid-cols-3 gap-3 items-end">
        <legend class="label">Capacité</legend>
        <div><label class="text-xs text-gray-500" for="v-parcels">Colis</label><input id="v-parcels" v-model.number="form.capacity_parcels" type="number" min="1" class="input"></div>
        <div><label class="text-xs text-gray-500" for="v-kg">Poids (kg)</label><input id="v-kg" v-model.number="form.capacity_kg" type="number" min="1" class="input"></div>
        <label class="flex items-center gap-2 text-sm pb-2"><input v-model="form.has_box" type="checkbox"> Coffre</label>
      </fieldset>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="v-own">Propriétaire</label>
          <select id="v-own" v-model="form.ownership" class="input">
            <option v-for="o in catalog.ownerships" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
          <p v-if="form.ownership === 'lease'" class="text-xs text-gray-500 mt-1">Le contrat se crée ensuite sur la fiche (onglet Location).</p>
        </div>
        <div v-if="form.ownership === 'courier'">
          <label class="label" for="v-owner">Livreur propriétaire</label>
          <select id="v-owner" v-model="form.owner_courier_id" class="input">
            <option :value="null">—</option>
            <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <div v-if="!vehicle">
          <label class="label" for="v-courier">Confié à</label>
          <select id="v-courier" v-model="form.courier_id" class="input">
            <option :value="null">Personne pour l'instant</option>
            <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}{{ c.vehicle_id ? ' (a déjà un véhicule)' : '' }}</option>
          </select>
        </div>
        <div v-else>
          <label class="label" for="v-status">État</label>
          <select id="v-status" v-model="form.status" class="input">
            <option v-for="s in catalog.statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>
        <div><label class="label" for="v-odo">Compteur (km)</label><input id="v-odo" v-model.number="form.odometer" type="number" min="0" class="input"></div>
        <div><label class="label" for="v-price">Prix d'achat (F)</label><input id="v-price" v-model.number="form.purchase_price" type="number" min="0" class="input"></div>
        <div><label class="label" for="v-bought">Acheté le</label><input id="v-bought" v-model="form.purchased_on" type="date" class="input"></div>
      </div>
      <div><label class="label" for="v-notes">Notes</label><textarea id="v-notes" v-model="form.notes" rows="2" class="input" /></div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="busy">{{ vehicle ? 'Enregistrer' : 'Ajouter le véhicule' }}</button>
    </form>
  </Modal>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../Modal.vue'
import { VEHICLE_TYPES } from '../../utils/vehicles'

const props = defineProps({
  open: { type: Boolean, default: false },
  vehicle: { type: Object, default: null },
  couriers: { type: Array, default: () => [] },
  catalog: { type: Object, required: true },
})
const emit = defineEmits(['close', 'saved'])
const FIELDS = ['type', 'plate', 'brand', 'model', 'year', 'color', 'vin', 'capacity_parcels', 'capacity_kg', 'has_box', 'ownership', 'owner_courier_id', 'status', 'odometer', 'purchase_price', 'purchased_on', 'notes']
const form = reactive({})
const busy = ref(false)
const error = ref('')

watch(() => props.open, (open) => {
  if (!open) return
  error.value = ''
  const v = props.vehicle || { type: 'moto', has_box: false, ownership: 'company', status: 'active', courier_id: null, owner_courier: null }
  Object.assign(form, Object.fromEntries(FIELDS.map((k) => [k, v[k] ?? null])), { courier_id: null, owner_courier_id: v.owner?.id ?? null, has_box: !!v.has_box })
})

async function save() {
  busy.value = true
  error.value = ''
  try {
    const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    const { data } = props.vehicle ? await http.patch(`/vehicles/${props.vehicle.id}`, payload) : await http.post('/vehicles', payload)
    emit('saved', data.data)
    emit('close')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>
