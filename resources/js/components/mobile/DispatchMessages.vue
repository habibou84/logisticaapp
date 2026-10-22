<template>
  <section v-if="messages.length" class="m-card p-4 space-y-3 ring-2 ring-sky-300">
    <h2 v-if="!showOrder" class="font-semibold">💬 Consignes de l'agence</h2>
    <div v-for="m in messages" :key="m.id" class="rounded-xl bg-sky-50 p-3 space-y-2">
      <p v-if="showOrder && m.tracking_code" class="text-xs text-slate-500">{{ m.tracking_code }}<span v-if="m.recipient_name"> · {{ m.recipient_name }}</span></p>
      <p class="text-base font-medium text-slate-900">{{ m.body }}</p>
      <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
        <span>{{ m.sender || 'Agence' }} · {{ time(m.created_at) }}</span>
        <button v-if="!m.acknowledged_at" type="button" class="tap rounded-full px-4 py-2 text-sm font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }" @click="ack(m)">👍 Compris</button>
        <span v-else class="text-emerald-700 font-medium">✓ Compris</span>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'
import http from '../../bootstrap/axios'
import { useCourierMessageStore } from '../../stores/courierMessages'

// orderId : consignes d'une course ; sinon, toutes les consignes récentes
const props = defineProps({ orderId: { type: Number, default: null }, showOrder: { type: Boolean, default: false } })
const emit = defineEmits(['loaded'])

const store = useCourierMessageStore()
const messages = ref([])

function time(value) {
  return new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

async function load() {
  const params = props.orderId ? { order_id: props.orderId } : { mark_read: 1 }
  const { data } = await http.get('/courier/messages', { params })
  const unreadBefore = messages.value.length ? 0 : data.data.filter((m) => !m.read_at).length
  messages.value = props.orderId ? data.data : [...data.data].reverse()
  // Les consignes affichées sont désormais lues
  store.setUnread(Math.max(0, store.unread - unreadBefore))
  emit('loaded', messages.value)
}

async function ack(m) {
  const { data } = await http.post(`/courier/messages/${m.id}/ack`)
  Object.assign(m, data.data)
}

// Nouvelle consigne reçue pendant que l'écran est ouvert
watch(() => store.version, load)
onMounted(load)
</script>
