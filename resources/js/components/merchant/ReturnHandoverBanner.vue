<template>
  <!-- Livreur chez le client avec des colis à lui rendre : il confirme les avoir reçus (preuve de la remise) -->
  <section v-for="h in list" :key="h.id" class="space-y-2.5 rounded-[22px] border border-orange-200 bg-orange-50 p-4 text-orange-950">
    <p class="flex items-center gap-2 text-[16px] font-bold"><Icone nom="retourner" />{{ title(h) }}</p>
    <p class="text-sm">Colis à vous rendre. Confirmez quand vous les avez en main.</p>
    <ul class="divide-y divide-orange-100 rounded-xl bg-white text-sm">
      <li v-for="o in h.orders" :key="o.id" class="flex justify-between gap-2 px-3 py-2">
        <span class="min-w-0 truncate">{{ o.tracking_code }}<template v-if="o.recipient_name"> · {{ o.recipient_name }}</template></span>
      </li>
    </ul>
    <div class="grid grid-cols-2 gap-2">
      <button type="button" class="tap min-h-12 rounded-2xl border border-red-300 bg-white text-sm font-semibold text-red-700" :disabled="busy" @click="act(h, 'dispute')">Il en manque</button>
      <button type="button" class="tap min-h-12 rounded-2xl bg-emerald-700 text-sm font-bold text-white" :disabled="busy" @click="act(h, 'confirm')">
        {{ h.orders.length > 1 ? `J'ai reçu les ${h.orders.length} colis` : 'J\'ai reçu le colis' }}
      </button>
    </div>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import Icone from '../ui/Icone.vue'

const toasts = useToastStore()
const list = ref([])
const busy = ref(false)
let timer = null

const title = (h) => (h.status === 'unconfirmed'
  ? `${h.courier.name || 'Le livreur'} vous a rendu ${h.orders.length} colis`
  : `${h.courier.name || 'Le livreur'} est chez vous avec ${h.orders.length} colis`)

async function load() {
  if (document.hidden) return
  try {
    list.value = (await http.get('/return-handovers/mine')).data.data
  } catch {
    // Bandeau facultatif : rien à afficher
  }
}

async function act(h, action) {
  busy.value = true
  try {
    await http.post(`/return-handovers/${h.id}/${action}`)
    toasts.success(action === 'confirm' ? 'Merci, la remise est confirmée.' : 'C\'est noté : l\'agence vous appelle.')
    list.value = list.value.filter((x) => x.id !== h.id)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  load()
  timer = setInterval(load, 15000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
