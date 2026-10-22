<template>
  <!-- Journée de livraison (validation avec règles) : ouverte, ou fermée → nouvelles courses pour le lendemain -->
  <div v-if="day?.rules" :class="['card px-3 py-2 sm:p-3 flex flex-wrap items-center gap-3 text-sm', day.closed ? 'bg-amber-50 border-amber-200' : 'bg-emerald-50 border-emerald-200']">
    <span class="text-lg" aria-hidden="true">{{ day.closed ? '🌙' : '🟢' }}</span>
    <div class="min-w-0 flex-1">
      <template v-if="day.closed">
        <p class="font-semibold">Livraisons du jour fermées <span class="font-normal">({{ reasonLabel }})</span></p>
        <p class="hidden sm:block text-gray-600">Les nouvelles courses sont validées pour le {{ longDay(day.next_day) }} ; le ramassage reste le jour même.</p>
      </template>
      <template v-else>
        <p class="font-semibold">Livraisons du jour ouvertes</p>
        <!-- Téléphone : une ligne suffit -->
        <p class="hidden sm:block text-gray-600">
          Nouvelles courses validées automatiquement<template v-if="day.cutoff_time"> ; après {{ day.cutoff_time }}, elles partent le lendemain</template>.
        </p>
      </template>
    </div>

    <button v-if="day.closed && day.reason === 'manual'" class="btn-secondary" :disabled="busy" @click="reopen">Rouvrir<span class="hidden sm:inline">&nbsp;la journée</span></button>
    <button v-else-if="!day.closed && !closing" class="btn-secondary" @click="closing = true">🌙 Fermer<span class="hidden sm:inline">&nbsp;la journée</span></button>

    <div v-if="closing" class="w-full rounded-lg bg-white p-3 ring-1 ring-slate-200 space-y-2">
      <p class="font-medium">Fermer les livraisons du jour ?</p>
      <p class="text-gray-600">Jusqu'à minuit, les nouvelles courses du jour seront validées pour le {{ longDay(day.next_day) }} et leur marchand prévenu.</p>
      <label v-if="day.today_open_count" class="flex items-start gap-2">
        <input v-model="postpone" type="checkbox" class="mt-1">
        <span>
          Reporter aussi au {{ longDay(day.next_day) }} les <strong>{{ day.today_open_count }}</strong> course(s) du jour pas encore parties en livraison
          <span class="block text-xs text-gray-500">Celles en attente sont validées ; chaque marchand reçoit la nouvelle date. Les ramassages prévus restent pour aujourd'hui.</span>
        </span>
      </label>
      <div class="flex gap-2">
        <button class="btn-primary" :disabled="busy" @click="close">{{ busy ? 'Fermeture…' : 'Fermer la journée' }}</button>
        <button class="btn-secondary" :disabled="busy" @click="closing = false; postpone = false">Annuler</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'

const emit = defineEmits(['changed'])
const toasts = useToastStore()
const day = ref(null)
const closing = ref(false)
const postpone = ref(false)
const busy = ref(false)

const longDay = (iso) => new Date(`${iso}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
const reasonLabel = computed(() => (day.value.reason === 'cutoff'
  ? `heure limite ${day.value.cutoff_time} passée`
  : `fermée à la main${day.value.closed_at ? ` à ${new Date(day.value.closed_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}` : ''}`))

async function load() {
  try {
    day.value = (await http.get('/delivery-day')).data.data
  } catch {
    day.value = null
  }
}

async function close() {
  busy.value = true
  try {
    const { data } = await http.post('/delivery-day/close', { postpone: postpone.value })
    day.value = data.data
    closing.value = false
    postpone.value = false
    toasts.success(data.data.postponed ? `Journée fermée : ${data.data.postponed} course(s) reportée(s) au lendemain.` : 'Journée fermée.')
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function reopen() {
  busy.value = true
  try {
    day.value = (await http.post('/delivery-day/reopen')).data.data
    toasts.success('Journée rouverte : les nouvelles courses sont de nouveau pour aujourd\'hui.')
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(load)
defineExpose({ load })
</script>
