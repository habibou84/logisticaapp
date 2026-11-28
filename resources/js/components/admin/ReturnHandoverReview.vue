<template>
  <!-- Remises au client à vérifier : faites sans sa confirmation, ou « il en manque » -->
  <section v-if="list.length" class="space-y-3">
    <h2 class="font-semibold">Remises à vérifier : {{ list.length }}</h2>
    <div v-for="h in list" :key="h.id" :class="['card space-y-3 p-4', h.status === 'disputed' ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50']">
      <div class="flex items-start gap-2">
        <Icone :nom="h.status === 'disputed' ? 'urgent' : 'attente'" class="mt-0.5" />
        <div class="flex-1 text-sm">
          <p class="font-semibold">
            {{ h.status === 'disputed' ? `${h.merchant.business_name} signale qu'il manque des colis` : `${h.merchant.business_name} n'a pas confirmé la remise` }}
          </p>
          <p class="text-slate-700">
            Remise {{ h.reference }} de {{ h.courier.name }}
            <template v-if="h.status === 'disputed'"> · signalé le {{ dateTime(h.disputed_at) }}. Les colis sont encore chez le livreur.</template>
            <template v-else> · remis le {{ dateTime(h.handed_at) }} à {{ h.received_by_name }} · motif : {{ h.fallback_label }}</template>
          </p>
        </div>
      </div>
      <div class="flex flex-wrap gap-3 text-sm">
        <span v-for="o in h.orders" :key="o.id" class="rounded-lg bg-white px-2 py-1">
          <RouterLink :to="`/admin/courses/${o.id}`" class="font-mono text-blue-700">{{ o.tracking_code }}</RouterLink>
          <span class="text-slate-600"> · {{ o.status_label }}</span>
        </span>
      </div>
      <div v-if="h.has_signature || h.has_photo" class="flex flex-wrap gap-3">
        <AuthImage v-if="h.has_signature" :src="`/return-handovers/${h.id}/proof/signature`" alt="Signature" class="h-24 rounded-lg border bg-white" />
        <AuthImage v-if="h.has_photo" :src="`/return-handovers/${h.id}/proof/photo`" alt="Photo de la remise" class="h-24 rounded-lg border bg-white" />
      </div>
      <p class="text-sm text-slate-700">Appelez le client<template v-if="h.status === 'disputed'"> et le livreur</template>, puis tranchez. Pour un colis perdu, ouvrez la course.</p>
      <div class="flex flex-wrap gap-2">
        <template v-if="h.status === 'disputed'">
          <button type="button" class="btn-primary" :disabled="busy" @click="resolve(h, 'returned')">Le client confirme, c'est rendu</button>
          <button type="button" class="btn-secondary" :disabled="busy" @click="resolve(h, 'kept')">Colis toujours chez le livreur</button>
        </template>
        <button v-else type="button" class="btn-primary" :disabled="busy" @click="resolve(h, 'verified')">Vérifié, c'est rendu</button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import AuthImage from '../AuthImage.vue'
import Icone from '../ui/Icone.vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'

const toasts = useToastStore()
const list = ref([])
const busy = ref(false)

async function load() {
  try {
    list.value = (await http.get('/return-handovers/review')).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function resolve(h, decision) {
  busy.value = true
  try {
    await http.post(`/return-handovers/${h.id}/resolve`, { decision })
    toasts.success(decision === 'kept' ? 'Remise annulée : les colis restent à rendre.' : 'Remise vérifiée.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
