<template>
  <RouterLink
    v-if="event"
    :to="`/admin/terrain?evenement=${event.id}`"
    :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', event.capacity === 'continue' ? 'bg-amber-100 text-amber-900' : 'bg-red-100 text-red-800']"
    :title="`Événement terrain en cours : ${event.label}`"
    @click.stop
  >
    <Icone v-if="event.icon" :nom="event.icon" taille="petite" />{{ event.label }}{{ event.capacity === 'stopped' ? ' · arrêté' : event.capacity === 'blocked' ? ' · bloqué' : '' }}
  </RouterLink>
</template>

<script setup>
import Icone from './ui/Icone.vue'
// Événement terrain en cours d'un livreur (panne, accident…), vers son détail dans Remontées terrain
defineProps({ event: { type: Object, default: null } })
</script>
