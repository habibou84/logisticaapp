<template>
  <Modal :open="open" :title="doc ? 'Modifier le papier' : 'Ajouter un papier'" @close="$emit('close')">
    <form class="space-y-3" @submit.prevent="save">
      <div>
        <label class="label" for="d-kind">Papier</label>
        <select id="d-kind" v-model="form.kind" class="input">
          <option v-for="k in kinds" :key="k.value" :value="k.value">{{ k.icon }} {{ k.label }}</option>
        </select>
      </div>
      <div v-if="form.kind === 'other'"><label class="label" for="d-label">Nom du papier</label><input id="d-label" v-model="form.label" class="input"></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="d-ref">Numéro, police</label><input id="d-ref" v-model="form.reference" class="input"></div>
        <div><label class="label" for="d-issuer">Délivré par</label><input id="d-issuer" v-model="form.issuer" class="input" placeholder="Assureur, centre, mairie…"></div>
        <div><label class="label" for="d-start">Début</label><input id="d-start" v-model="form.starts_on" type="date" class="input"></div>
        <div>
          <label class="label" for="d-end">Fin</label><input id="d-end" v-model="form.ends_on" type="date" class="input">
          <p class="text-xs text-gray-500 mt-1">Vide : sans fin (carte grise).</p>
        </div>
        <div><label class="label" for="d-cost">Coût (F)</label><input id="d-cost" v-model.number="form.cost" type="number" min="0" class="input"></div>
        <div>
          <label class="label" for="d-file">Photo ou PDF</label>
          <input id="d-file" ref="input" type="file" accept="image/*,application/pdf" class="text-sm" @change="pick">
          <p v-if="photo?.meta?.scanned" class="text-xs text-green-700 mt-1">📄 Document scanné{{ photo.original ? ', original gardé' : '' }}</p>
        </div>
      </div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="busy">Enregistrer</button>
    </form>
  </Modal>
  <!-- Photo d'un papier : passée au scanner (recadrée, redressée, nettoyée), l'original gardé selon le réglage -->
  <DocumentScanner v-if="scanning" :key="attempt" :file="scanning" :mode="settings.mode" :quality-check="settings.quality_check"
                   :max-side="settings.resolution === 'high' ? 3000 : 2000" :keep-original="settings.keep_original" :attempt="attempt"
                   @done="scanned" @retake="input?.click()" @cancel="scanning = null" />
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../Modal.vue'
import DocumentScanner from '../mobile/DocumentScanner.vue'
import { photoSettings } from '../../composables/usePhotoSettings'

// Papier d'un véhicule, ou permis d'un livreur (courierId)
const props = defineProps({
  open: { type: Boolean, default: false },
  doc: { type: Object, default: null },
  vehicleId: { type: Number, default: null },
  courierId: { type: Number, default: null },
  catalog: { type: Object, required: true },
})
const emit = defineEmits(['close', 'saved'])
const form = reactive({})
const file = ref(null)
// Photo passée au scanner : { file, original, meta }
const photo = ref(null)
const scanning = ref(null)
const attempt = ref(0)
const input = ref(null)
const settings = computed(() => photoSettings())
const busy = ref(false)
const error = ref('')
const kinds = computed(() => props.catalog.documents.filter((k) => (props.courierId ? k.value === 'license' : k.value !== 'license')))

watch(() => props.open, (open) => {
  if (!open) return
  error.value = ''
  file.value = null
  photo.value = null
  const d = props.doc || { kind: props.courierId ? 'license' : 'insurance' }
  Object.assign(form, { kind: d.kind, label: d.label ?? '', reference: d.reference ?? '', issuer: d.issuer ?? '', starts_on: d.starts_on ?? '', ends_on: d.ends_on ?? '', cost: d.cost ?? null })
})

// Photo (pas un PDF) et scanner activé pour les papiers : passage au scanner avant l'enregistrement
function pick(e) {
  const picked = e.target.files[0]
  photo.value = null
  file.value = picked || null
  if (picked?.type.startsWith('image/') && settings.value.scan.vehicle) {
    attempt.value++
    scanning.value = picked
  }
}

function scanned(p) {
  scanning.value = null
  attempt.value = 0
  photo.value = p
  file.value = p.file
}

async function save() {
  busy.value = true
  error.value = ''
  const body = new FormData()
  Object.entries(form).forEach(([k, v]) => v !== null && v !== '' && body.append(k, v))
  if (!props.doc) body.append(props.courierId ? 'courier_id' : 'vehicle_id', props.courierId ?? props.vehicleId)
  if (file.value) body.append('file', file.value)
  if (photo.value?.original) body.append('file_original', photo.value.original, 'original.jpg')
  if (photo.value?.meta) body.append('file_meta', JSON.stringify(photo.value.meta))
  try {
    await http.post(props.doc ? `/vehicles/documents/${props.doc.id}` : '/vehicles/documents', body)
    emit('saved')
    emit('close')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>
