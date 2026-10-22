<template>
  <div class="space-y-2">
    <ul v-if="messages.length" class="space-y-1.5">
      <li v-for="m in messages" :key="m.id" class="rounded bg-sky-50 px-3 py-2 text-sm">
        <p class="font-medium">💬 {{ m.body }}</p>
        <p class="text-xs text-gray-500">
          {{ m.sender || 'Agence' }} · {{ time(m.created_at) }} ·
          <span v-if="m.acknowledged_at" class="text-emerald-700 font-medium">✓ Compris à {{ time(m.acknowledged_at) }}</span>
          <span v-else-if="m.read_at" class="text-sky-700">Lu à {{ time(m.read_at) }}</span>
          <span v-else>Envoyé, pas encore lu</span>
        </p>
      </li>
    </ul>

    <form v-if="composer" class="space-y-2" @submit.prevent="send">
      <div class="flex flex-wrap gap-1">
        <button v-for="q in QUICK_REPLIES" :key="q" type="button" class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-xs hover:bg-slate-50" @click="body = q">{{ q }}</button>
      </div>
      <textarea v-model="body" rows="2" maxlength="500" class="input text-sm" :placeholder="placeholder" :aria-label="placeholder" />
      <label v-if="replyTo" class="flex items-center gap-2 text-xs"><input v-model="markHandled" type="checkbox"> Marquer la remontée comme traitée</label>
      <p v-if="error" class="field-error">{{ error }}</p>
      <div class="flex gap-2">
        <button class="btn-primary text-sm" :disabled="sending || !body.trim()">Envoyer au livreur</button>
        <button v-if="cancelable" type="button" class="btn-secondary text-sm" @click="$emit('cancel')">Annuler</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { QUICK_REPLIES } from '../utils/quickReplies'

const props = defineProps({
  orderId: { type: Number, required: true },
  messages: { type: Array, default: () => [] },
  replyTo: { type: Number, default: null },
  courierId: { type: Number, default: null },
  composer: { type: Boolean, default: true },
  cancelable: { type: Boolean, default: false },
  placeholder: { type: String, default: 'Consigne pour le livreur…' },
})
const emit = defineEmits(['sent', 'cancel'])

const body = ref('')
const markHandled = ref(true)
const sending = ref(false)
const error = ref('')

function time(value) {
  return new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

async function send() {
  sending.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/orders/${props.orderId}/courier-messages`, {
      body: body.value,
      reply_to_event_id: props.replyTo || undefined,
      courier_id: props.courierId || undefined,
      mark_handled: props.replyTo ? markHandled.value : undefined,
    })
    body.value = ''
    emit('sent', data.data, markHandled.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    sending.value = false
  }
}
</script>
