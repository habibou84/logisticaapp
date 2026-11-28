<template>
  <div class="space-y-4">
    <p v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</p>
    <p v-if="!data && !error" class="text-slate-600">Chargement…</p>

    <form v-if="data" class="space-y-4" @submit.prevent="save">
      <section class="card overflow-x-auto">
        <div class="p-4 pb-2">
          <h2 class="font-semibold">Alertes par événement</h2>
          <p class="text-sm text-slate-600">Chaque matin à 7 h 35, l'application vérifie ces événements. Une même alerte n'est pas renvoyée avant 7 jours.</p>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-slate-600">
            <tr><th class="p-2 pl-4">Événement</th><th class="p-2">Seuil</th><th class="p-2 text-center">Cloche</th><th class="p-2 text-center">WhatsApp</th><th class="p-2 text-center whitespace-nowrap">E-mail</th><th class="p-2 pr-4 text-center">Active</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="e in data.events" :key="e.key" :class="e.active ? '' : 'text-slate-500'">
              <td class="p-2 pl-4 font-medium">{{ e.label }}</td>
              <td class="p-2 whitespace-nowrap">
                <template v-if="e.unit">
                  <label class="sr-only" :for="`al-${e.key}`">Seuil : {{ e.label }} ({{ e.unit }})</label>
                  <input :id="`al-${e.key}`" v-model.number="e.threshold" type="number" min="0" class="input inline-block w-24 py-1" :disabled="!e.active">
                  <span class="ml-1 text-xs text-slate-600">{{ e.unit }}</span>
                </template>
                <span v-else class="text-xs text-slate-600">Sans seuil</span>
              </td>
              <td v-for="c in CHANNELS" :key="c.key" class="p-2 text-center">
                <input v-model="e[c.key]" type="checkbox" :disabled="!e.active" :aria-label="`${e.label} : ${c.label}`">
              </td>
              <td class="p-2 pr-4 text-center"><input v-model="e.active" type="checkbox" :aria-label="`Activer : ${e.label}`"></td>
            </tr>
          </tbody>
        </table>
      </section>

      <div class="grid gap-4 lg:grid-cols-2">
        <section class="card space-y-3 p-4">
          <h2 class="font-semibold">Qui reçoit les alertes</h2>
          <div>
            <label class="label" for="al-to">E-mails (séparés par une virgule, 10 au plus)</label>
            <input id="al-to" v-model="recipients" type="text" class="input" placeholder="direction@exemple.ci">
          </div>
          <p class="text-sm text-slate-600">La cloche et WhatsApp vont aux administrateurs et aux comptes qui ont l'autorisation « Voir les statistiques de direction ».</p>
          <div>
            <label class="label" for="al-min">Ne pas alerter sous ce volume (courses)</label>
            <input id="al-min" v-model.number="data.min_volume" type="number" min="1" class="input w-28">
            <p class="text-xs text-slate-600">Pour les alertes sur les zones, les livreurs et les clients : évite d'être alerté pour trois courses.</p>
          </div>
          <div class="flex items-center gap-2">
            <button type="submit" class="btn-primary" :disabled="busy">Enregistrer</button>
            <span v-if="message" class="text-sm text-emerald-700" role="status">{{ message }}</span>
          </div>
        </section>

        <section class="card space-y-2 bg-slate-50 p-4">
          <h2 class="font-semibold">Dernières alertes reçues</h2>
          <p v-if="!data.recent.length" class="text-sm text-slate-600">Aucune alerte pour l'instant.</p>
          <ul v-else class="divide-y text-sm">
            <li v-for="(a, i) in data.recent" :key="i" class="py-2">
              <p class="font-medium">{{ a.title }} <span class="font-normal text-slate-600">· {{ date(a.at) }}</span></p>
              <p class="text-slate-600">{{ a.body }}</p>
            </li>
          </ul>
        </section>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { date } from '../../utils/format'

const CHANNELS = [{ key: 'bell', label: 'cloche' }, { key: 'whatsapp', label: 'WhatsApp' }, { key: 'email', label: 'e-mail' }]

const data = ref(null)
const recipients = ref('')
const busy = ref(false)
const error = ref('')
const message = ref('')

function apply(payload) {
  data.value = payload
  recipients.value = payload.recipients.join(', ')
}
async function save() {
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    const events = Object.fromEntries(data.value.events.map((e) => [e.key, { active: e.active, threshold: e.unit ? (e.threshold === '' ? null : e.threshold) : null, bell: e.bell, whatsapp: e.whatsapp, email: e.email }]))
    apply((await http.put('/stats/alerts', { events, recipients: recipients.value.split(/[,;\s]+/).filter(Boolean), min_volume: data.value.min_volume || null })).data.data)
    message.value = 'Alertes enregistrées.'
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

http.get('/stats/alerts').then((r) => apply(r.data.data)).catch((e) => { error.value = apiErrorMessage(e) })
</script>
