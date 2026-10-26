<template>
  <div>
    <button type="button" class="btn-secondary w-full" @click="open = true">🔁 Reconduire la course</button>
    <Modal :open="open" title="Reconduire la course" @close="open = false">
      <form class="space-y-3" @submit.prevent="submit">
        <p class="text-sm text-gray-600">Une nouvelle course identique est créée (même expéditeur, destinataire et envoi). Celle-ci reste comptée.</p>
        <div>
          <label class="label" for="renew-date">Date de livraison</label>
          <input id="renew-date" v-model="date" type="date" :min="today()" class="input">
        </div>
        <div>
          <label class="label" for="renew-note">Note</label>
          <input id="renew-note" v-model="note" class="input" maxlength="255" placeholder="Ex. : cachet ajouté, bon de commande joint">
        </div>
        <p v-if="error" class="text-red-600 text-sm">{{ error }}</p>
        <button type="submit" class="btn-primary w-full" :disabled="saving">{{ saving ? 'Création…' : 'Créer la nouvelle course' }}</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import Modal from './Modal.vue'
import { useToastStore } from '../stores/toasts'
import { today } from '../utils/format'

// Course revenue, annulée ou en échec : la reconduire (nouvelle course liée à celle-ci)
const props = defineProps({ order: { type: Object, required: true } })
const router = useRouter()
const toasts = useToastStore()
const open = ref(false)
const date = ref(null)
const note = ref('')
const saving = ref(false)
const error = ref('')

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/orders/${props.order.id}/renew`, { date: date.value || null, note: note.value || null })
    open.value = false
    toasts.success(`Course reconduite : ${data.data.tracking_code}`)
    router.push(router.currentRoute.value.path.replace(/[^/]+$/, data.data.id))
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
