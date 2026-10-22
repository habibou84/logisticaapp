<template>
  <ol class="relative border-l-2 border-slate-200 ml-2 space-y-4">
    <li v-for="event in events" :key="event.id" class="ml-4">
      <span :class="['absolute -left-[7px] mt-1.5 h-3 w-3 rounded-full', dot(event)]" />
      <div class="flex flex-wrap items-baseline gap-x-2">
        <span class="font-medium text-sm">{{ title(event) }}</span>
        <StatusBadge v-if="event.to_status && event.type !== 'created'" :status="event.to_status" :label="event.to_status_label" />
        <span v-if="!event.visible_to_merchant" class="text-xs rounded bg-slate-100 px-1.5 text-slate-500">interne</span>
      </div>
      <p class="text-xs text-gray-500">
        {{ dateTime(event.created_at) }} · {{ event.actor_name || 'Système' }}<span v-if="event.actor_role"> ({{ ROLE_LABELS[event.actor_role] || event.actor_role }})</span><span v-if="event.meta?.channel" class="text-indigo-700"> · via {{ event.meta.channel }}</span>
        <a v-if="event.lat && event.lng" :href="mapsLink(event.lat, event.lng)" target="_blank" class="ml-1 text-blue-600">📍 position</a>
      </p>
      <p v-if="event.incident_reason" class="text-sm text-red-700">Motif : {{ event.incident_reason.label }}</p>
      <p v-if="event.rescheduled_to" class="text-sm text-orange-700">Reporté au {{ date(event.rescheduled_to) }}</p>
      <p v-if="event.meta?.courier_name" class="text-sm text-gray-700">{{ event.meta.type_label }} → {{ event.meta.courier_name }}</p>
      <p v-if="event.meta?.collected_amount !== undefined" class="text-sm text-gray-700">Encaissé : {{ money(event.meta.collected_amount) }}</p>
      <p v-if="event.meta?.offline" class="text-xs text-sky-700">📵 Fait hors connexion, envoyé au retour du réseau</p>
      <ul v-if="event.meta?.changes" class="text-xs text-gray-600 list-disc ml-4">
        <li v-for="(change, field) in event.meta.changes" :key="field">{{ field }} : {{ change.from ?? '—' }} → {{ change.to ?? '—' }}</li>
      </ul>
      <p v-if="event.note" class="text-sm bg-slate-50 rounded px-2 py-1 mt-1 whitespace-pre-line">{{ event.note }}</p>
      <div v-if="event.attachments?.length" class="flex gap-2 mt-1">
        <button v-for="a in event.attachments" :key="a.id" class="text-sm text-blue-600 underline" @click="openAttachment(a)">📷 Voir la photo</button>
      </div>
    </li>
  </ol>
</template>

<script setup>
import StatusBadge from './StatusBadge.vue'
import http from '../bootstrap/axios'
import { date, dateTime, EVENT_LABELS, mapsLink, money, ROLE_LABELS } from '../utils/format'

defineProps({ events: { type: Array, default: () => [] } })

function title(event) {
  if (event.type === 'status_changed') return event.to_status_label
  return EVENT_LABELS[event.type] || event.type
}

function dot(event) {
  if (event.type === 'incident') return 'bg-red-500'
  if (event.to_status === 'delivered') return 'bg-emerald-500'
  if (event.type === 'note') return 'bg-amber-400'
  return 'bg-slate-400'
}

// Les photos sont protégées : on les charge avec le jeton puis on les ouvre
async function openAttachment(attachment) {
  const { data } = await http.get(attachment.url.replace(/^\/api\/v1/, ''), { responseType: 'blob' })
  window.open(URL.createObjectURL(data), '_blank')
}
</script>
