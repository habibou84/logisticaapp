<template>
  <div class="min-h-screen bg-slate-50">
    <header class="flex items-center gap-3 border-b border-slate-200 bg-white px-5 py-4">
      <BrandLogo size="sm" />
    </header>

    <main class="mx-auto max-w-md space-y-4 px-4 py-6">
      <p v-if="loading" class="py-16 text-center text-slate-500">Chargement…</p>
      <p v-else-if="notFound" class="py-16 text-center text-slate-600">Ce lien n'existe pas. Contactez l'agence.</p>

      <template v-else-if="info">
        <!-- Fini : réponse enregistrée -->
        <div v-if="result" class="space-y-2 rounded-2xl bg-emerald-50 p-6 text-center">
          <p class="text-4xl" aria-hidden="true">✅</p>
          <p class="text-xl font-bold">C'est noté, merci !</p>
          <p v-for="r in result.rescheduled" :key="r.code" class="text-sm text-slate-700">{{ r.code }} : livraison le {{ longDay(r.date) }}</p>
          <p v-for="c in result.cancelled" :key="c" class="text-sm text-slate-700">{{ c }} : annulé</p>
          <p v-for="s in result.skipped" :key="s.code" class="text-sm text-slate-500">{{ s.code }} : pas changé ({{ s.why }})</p>
        </div>
        <div v-else-if="!info.open" class="rounded-2xl bg-slate-100 p-6 text-center text-slate-600">
          {{ info.expired ? 'Ce lien n\'est plus valable (3 jours). Contactez l\'agence pour choisir une date.' : 'Ces colis ont déjà une nouvelle date. Merci !' }}
        </div>

        <form v-else class="space-y-4" @submit.prevent="send">
          <div>
            <p class="text-sm text-slate-500">{{ info.company.name }} · {{ info.merchant_name }}</p>
            <h1 class="text-2xl font-bold">📅 Colis à reporter</h1>
            <p class="mt-1 text-sm text-slate-600">
              Nous n'avons pas pu ramasser {{ info.orders.length > 1 ? 'ces colis' : 'ce colis' }} le {{ longDay(info.missed_day) }}.
              Choisissez une nouvelle date pour chacun.
            </p>
            <p v-if="info.note" class="mt-2 rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-900">💬 {{ info.note }}</p>
          </div>

          <div v-for="o in info.orders" :key="o.id" class="space-y-2 rounded-2xl bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-2">
              <p class="min-w-0">
                <span class="font-mono text-sm font-semibold">{{ o.tracking_code }}</span>
                <span class="block text-sm text-slate-600">{{ o.recipient_name }}<template v-if="o.zone_name"> · {{ o.zone_name }}</template></span>
              </p>
              <span class="shrink-0 text-sm font-semibold tabular-nums">{{ money(o.cod_amount) }}</span>
            </div>
            <div class="grid grid-cols-3 gap-1.5" role="radiogroup" :aria-label="`Choix pour ${o.tracking_code}`">
              <button
                v-for="opt in OPTIONS" :key="opt.value" type="button" role="radio"
                :aria-checked="choices[o.id].mode === opt.value"
                :class="['tap rounded-xl border-[1.5px] px-2 py-2 text-sm font-semibold', choices[o.id].mode === opt.value ? opt.on : 'border-slate-300 bg-white text-slate-700']"
                @click="choices[o.id].mode = opt.value"
              >
                {{ opt.label }}
              </button>
            </div>
            <input
              v-if="choices[o.id].mode === 'date'" v-model="choices[o.id].date" type="date" class="input"
              :min="info.today" :max="info.max_date" required :aria-label="`Nouvelle date pour ${o.tracking_code}`"
            >
            <select v-if="choices[o.id].mode === 'cancel'" v-model="choices[o.id].cancel" class="input" required :aria-label="`Motif d'annulation pour ${o.tracking_code}`">
              <option value="" disabled>Motif de l'annulation…</option>
              <option v-for="r in info.reasons" :key="r.code" :value="r.code">{{ r.label }}</option>
            </select>
          </div>

          <button type="button" class="w-full rounded-xl border-[1.5px] border-emerald-600 py-3 font-semibold text-emerald-800" @click="allToday">
            Tout livrer aujourd'hui
          </button>
          <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
          <button class="w-full rounded-xl bg-emerald-700 py-3.5 font-semibold text-white disabled:opacity-50" :disabled="!ready || sending">
            {{ sending ? 'Envoi…' : 'Envoyer mes choix' }}
          </button>
          <p class="text-center text-xs text-slate-500">Lien personnel, valable 3 jours · aucune connexion nécessaire. Dates possibles jusqu'au {{ longDay(info.max_date) }}.</p>
        </form>
      </template>
    </main>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import BrandLogo from '../components/auth/BrandLogo.vue'
import { loadBranding } from '../composables/useBranding'
import { money } from '../utils/format'
import { siteOrigin } from '../native'

/**
 * Page de réponse du client : lien reçu sur WhatsApp quand ses colis n'ont pas été ramassés à leur date.
 * Pour chaque colis : aujourd'hui, une autre date ou l'annulation (avec motif).
 */
const OPTIONS = [
  { value: 'today', label: 'Aujourd\'hui', on: 'border-emerald-600 bg-emerald-50 text-emerald-800' },
  { value: 'date', label: 'Autre date', on: 'border-blue-600 bg-blue-50 text-blue-800' },
  { value: 'cancel', label: 'Annuler', on: 'border-red-400 bg-red-50 text-red-800' },
]
const route = useRoute()
const info = ref(null)
const loading = ref(true)
const notFound = ref(false)
const sending = ref(false)
const error = ref('')
const result = ref(null)
const choices = reactive({})

const url = () => `${siteOrigin()}/api/v1/reschedule/${encodeURIComponent(route.params.token)}`
const headers = { Accept: 'application/json' }
const longDay = (d) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }) : '')

const ready = computed(() => (info.value?.orders || []).every((o) => {
  const c = choices[o.id]
  return c && (c.mode === 'today' || (c.mode === 'date' && c.date) || (c.mode === 'cancel' && c.cancel))
}))

function setup(data) {
  info.value = data
  for (const o of data.orders) choices[o.id] ??= { mode: null, date: '', cancel: '' }
}

function allToday() {
  for (const o of info.value.orders) choices[o.id].mode = 'today'
}

async function send() {
  sending.value = true
  error.value = ''
  try {
    const payload = info.value.orders.map((o) => {
      const c = choices[o.id]
      return c.mode === 'cancel' ? { order_id: o.id, cancel: c.cancel } : { order_id: o.id, date: c.mode === 'today' ? info.value.today : c.date }
    })
    const { data } = await axios.post(url(), { choices: payload }, { headers })
    result.value = data.data.result
    setup(data.data)
  } catch (e) {
    error.value = e.response?.data?.message || 'Envoi impossible pour le moment. Réessayez.'
  } finally {
    sending.value = false
  }
}

onMounted(async () => {
  loadBranding()
  try {
    setup((await axios.get(url(), { headers })).data.data)
  } catch (e) {
    notFound.value = true
  } finally {
    loading.value = false
  }
})
</script>
