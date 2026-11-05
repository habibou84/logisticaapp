<template>
  <div class="space-y-4">
    <div>
      <RouterLink to="/admin/reglages" class="text-sm text-blue-600">‹ Réglages</RouterLink>
      <h1 class="text-xl font-bold">💬 Messages aux clients</h1>
      <p class="text-sm text-gray-600">
        Pour chaque étape d'une course : la notification dans l'application est toujours envoyée (gratuite).
        Choisissez si le client reçoit aussi un message WhatsApp (ou une notification sur son téléphone quand il l'a activée).
      </p>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-if="!data && !error" class="card p-6 text-center text-gray-500">Chargement…</p>

    <template v-if="data">
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase text-slate-600">
            <tr>
              <th class="px-3 py-2 font-bold">Étape</th>
              <th class="px-3 py-2 font-bold">📱 Application</th>
              <th class="px-3 py-2 font-bold">💬 WhatsApp</th>
              <th class="hidden px-3 py-2 font-bold md:table-cell">Message</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="e in data.events" :key="e.event">
              <td class="px-3 py-2.5">
                <b>{{ e.label }}</b>
                <span v-if="HINTS[e.event]" class="block text-xs text-gray-500">{{ HINTS[e.event] }}</span>
                <span v-if="e.differs && !data.locked" class="mt-1 block text-xs text-amber-700">
                  {{ e.differs }} client{{ e.differs > 1 ? 's ont' : ' a' }} fait un autre choix.
                  <button type="button" class="font-semibold underline" :disabled="busy" @click="applyAll(e)">Appliquer à tous</button>
                </span>
              </td>
              <td class="px-3 py-2.5"><span class="text-xs font-semibold text-emerald-700">✓ toujours</span></td>
              <td class="px-3 py-2.5">
                <button
                  type="button" role="switch" :aria-checked="e.whatsapp" :aria-label="`WhatsApp : ${e.label}`" :disabled="busy"
                  :class="['inline-flex h-6 w-11 items-center rounded-full p-0.5 transition', e.whatsapp ? 'bg-emerald-600' : 'bg-slate-300']"
                  @click="toggle(e)"
                >
                  <span :class="['block h-5 w-5 rounded-full bg-white shadow transition', e.whatsapp ? 'translate-x-5' : '']" />
                </button>
                <span class="ml-1 text-xs text-gray-500">{{ e.whatsapp ? 'oui' : 'non' }}</span>
              </td>
              <td class="hidden px-3 py-2.5 text-xs text-gray-600 md:table-cell">{{ e.example ? `« ${e.example} »` : '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card space-y-2 p-4 text-sm">
        <label class="flex items-start gap-2">
          <input type="checkbox" class="mt-1" :checked="!data.locked" :disabled="busy" @change="save({ locked: !$event.target.checked })">
          <span>
            <b>Laisser chaque client changer ses messages</b> (Profil de son application)
            <span class="block text-xs text-gray-500">Sinon, vos choix s'appliquent à tous les clients et leurs interrupteurs sont grisés.</span>
          </span>
        </label>
        <div v-if="totalDiffers && !data.locked" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-amber-50 px-3 py-2 text-amber-900">
          <span>⚠️ Des clients ont gardé un autre choix que le vôtre ({{ totalDiffers }} réglage{{ totalDiffers > 1 ? 's' : '' }}).</span>
          <button type="button" class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-bold text-white" :disabled="busy" @click="applyAll(null)">Appliquer mes choix à tous</button>
        </div>
      </div>
      <p class="text-xs text-gray-500">
        Les courses envoyées par Jibiat Marchand ne reçoivent rien d'ici : Jibiat Marchand prévient lui-même la boutique.
        Numéro WhatsApp, modèles et envois : <RouterLink to="/admin/whatsapp" class="text-blue-600">page WhatsApp</RouterLink>.
      </p>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useToastStore } from '../../../stores/toasts'

/** Réglages › Messages aux clients : WhatsApp oui ou non par étape, choix laissés ou non aux clients */
const HINTS = {
  'order.received': 'À l\'enregistrement, quand la validation est automatique',
  'order.confirmed': 'Quand l\'équipe valide la course à la main',
}
const toasts = useToastStore()
const data = ref(null)
const error = ref('')
const busy = ref(false)
const totalDiffers = computed(() => (data.value?.events || []).reduce((n, e) => n + e.differs, 0))

async function load() {
  try {
    data.value = (await http.get('/company/merchant-messages')).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function save(payload) {
  busy.value = true
  try {
    data.value = (await http.put('/company/merchant-messages', payload)).data.data
    toasts.success('Enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

const toggle = (e) => save({ events: { [e.event]: !e.whatsapp } })

async function applyAll(e) {
  if (!window.confirm(e ? `« ${e.label} » : appliquer votre choix à tous les clients ?` : 'Appliquer vos choix à tous les clients ? Leurs propres réglages seront remplacés.')) return
  busy.value = true
  try {
    data.value = (await http.post('/company/merchant-messages/apply-all', { event: e?.event ?? null })).data.data
    toasts.success('Vos choix s\'appliquent maintenant à tous.')
  } catch (err) {
    toasts.error(apiErrorMessage(err))
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
