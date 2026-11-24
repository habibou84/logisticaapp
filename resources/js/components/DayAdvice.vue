<template>
  <!-- Conseil du jour : rouvrir (livreurs libres) ou fermer (trop de courses sans livreur). Le dispatch décide. -->
  <section
    v-if="advice"
    :class="['card flex flex-wrap items-start gap-4 px-5 py-4', advice.kind === 'reopen' ? 'border-emerald-300 bg-emerald-50' : 'border-amber-300 bg-amber-50']"
    aria-live="polite"
    :aria-label="advice.kind === 'reopen' ? 'Conseil : rouvrir la journée' : 'Conseil : fermer la journée'"
  >
    <span class="text-xl" aria-hidden="true">💡</span>
    <div class="min-w-0 flex-1 space-y-1">
      <template v-if="advice.kind === 'reopen'">
        <p class="font-semibold">Vous pouvez rouvrir la journée</p>
        <p class="text-sm text-slate-700">
          {{ plural(advice.free_couriers, 'livreur libre', 'livreurs libres') }}<template v-if="advice.couriers?.length"> ({{ advice.couriers.join(', ') }})</template>
          peuvent encore prendre environ <strong>{{ plural(advice.spare, 'course', 'courses') }}</strong> avant la fermeture du dépôt à {{ h(advice.depot_closes_at) }}.
          <template v-if="advice.waiting"> {{ plural(advice.waiting, 'course du jour attend', 'courses du jour attendent') }} déjà un livreur.</template>
        </p>
        <p class="text-sm text-slate-600">Les nouvelles courses seront livrées aujourd'hui jusqu'à {{ h(until) }}, puis le lendemain. Demain, l'heure limite habituelle revient.</p>
        <div class="flex flex-wrap items-center gap-2 pt-1">
          <label class="text-sm font-medium" for="advice-until">Jusqu'à</label>
          <input id="advice-until" v-model="until" type="time" class="input w-32">
          <button type="button" class="btn-primary" :disabled="busy || !until" @click="reopen">Rouvrir jusqu'à {{ h(until) }}</button>
          <button type="button" class="btn-secondary" :disabled="busy" @click="dismiss">Pas aujourd'hui</button>
        </div>
      </template>
      <template v-else>
        <p class="font-semibold">Pensez à fermer la journée</p>
        <p class="text-sm text-slate-700">
          <strong>{{ plural(advice.waiting, 'course du jour attend', 'courses du jour attendent') }}</strong> un livreur,
          <template v-if="advice.spare">mais les livreurs libres ne peuvent en prendre qu'environ {{ advice.spare }} avant la fermeture du dépôt à {{ h(advice.depot_closes_at) }}.</template>
          <template v-else>et aucun livreur libre ne peut en prendre avant la fermeture du dépôt à {{ h(advice.depot_closes_at) }}.</template>
        </p>
        <p class="text-sm text-slate-600">En fermant, les nouvelles courses partent le lendemain ; le ramassage reste le jour même.</p>
        <div class="flex flex-wrap gap-2 pt-1">
          <button type="button" class="btn-primary" :disabled="busy" @click="close">Fermer la journée</button>
          <button type="button" class="btn-secondary" :disabled="busy" @click="dismiss">Garder ouvert</button>
        </div>
      </template>
      <p v-if="!advice.depot_close_set" class="text-xs text-slate-600">
        Heure de fermeture du dépôt non réglée : 19 h est prise par défaut (Réglages › Livreurs).
      </p>
    </div>
  </section>
</template>

<script setup>
import { ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'

const props = defineProps({ advice: { type: Object, default: null } })
const emit = defineEmits(['changed'])
const toasts = useToastStore()
const busy = ref(false)
const until = ref('')

watch(() => props.advice?.until, (value) => { if (value) until.value = value }, { immediate: true })

const h = (time) => (time || '').slice(0, 5).replace(':', ' h ')
const plural = (n, one, many) => `${n} ${n > 1 ? many : one}`

async function run(request, message) {
  busy.value = true
  try {
    await request()
    if (message) toasts.success(message)
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

const reopen = () => run(() => http.post('/delivery-day/reopen', { until: until.value }), `Journée rouverte jusqu'à ${h(until.value)}.`)
const close = () => run(() => http.post('/delivery-day/close', { postpone: false }), 'Journée fermée : les nouvelles courses partent demain.')
const dismiss = () => run(() => http.post('/delivery-day/advice/dismiss', { kind: props.advice.kind }), null)
</script>
