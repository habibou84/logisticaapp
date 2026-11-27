<template>
  <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', CLASSES[message.status]]" :title="message.error || ''">
    <Icone v-if="message.channel === 'push'" nom="mobile" taille="petite" />{{ PREFIX[message.channel] || '' }}{{ message.channel === 'push' && message.status === 'sent' ? 'Notifiée' : message.status_label }}
  </span>
</template>

<script setup>
import Icone from './ui/Icone.vue'
defineProps({ message: { type: Object, required: true } })

const PREFIX = { sms: 'SMS · ' }

const CLASSES = {
  queued: 'bg-slate-100 text-slate-700',
  sent: 'bg-sky-100 text-sky-800',
  delivered: 'bg-emerald-100 text-emerald-800',
  read: 'bg-emerald-600 text-white',
  failed: 'bg-red-100 text-red-800',
}
</script>
