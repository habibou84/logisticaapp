<template>
  <div v-if="messages.length" class="card p-4 text-sm">
    <h2 class="font-semibold mb-2">Messages WhatsApp / SMS</h2>
    <ul class="space-y-3">
      <li v-for="m in messages" :key="m.id" class="space-y-1">
        <div class="flex justify-between gap-2">
          <span class="font-medium">{{ m.recipient_type === 'recipient' ? 'Destinataire' : 'Marchand' }} · {{ m.to }}</span>
          <MessageStatus :message="m" />
        </div>
        <p class="text-gray-600">{{ m.body }}</p>
        <p v-if="m.error" class="text-red-700 text-xs">{{ m.error }}</p>
        <p class="text-gray-400 text-xs">{{ dateTime(m.created_at) }}</p>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'
import http from '../bootstrap/axios'
import MessageStatus from './MessageStatus.vue'
import { dateTime } from '../utils/format'

const props = defineProps({
  orderId: { type: Number, required: true },
  // Incrémenté par le parent à chaque changement de la course
  version: { type: [Number, String], default: 0 },
})

const messages = ref([])

async function load() {
  try {
    messages.value = (await http.get(`/orders/${props.orderId}/messages`)).data.data
  } catch {
    messages.value = []
  }
}

// Les messages partent en file d'attente : on relit un peu après chaque changement
watch(() => [props.orderId, props.version], () => {
  load()
  setTimeout(load, 3000)
})
onMounted(load)
</script>
