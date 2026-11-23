<template>
  <div class="max-w-5xl space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">WhatsApp</h1>
        <p class="text-sm text-slate-600">Le numéro de l'entreprise, les messages qui partent tout seuls, et comment ils partent.</p>
      </div>
      <div v-if="settings" class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" :disabled="syncing" @click="sync">Vérifier l'approbation chez Meta</button>
        <button v-if="settings.mode === 'live' && toSubmit" type="button" class="btn-primary" :disabled="submitting" @click="submitTemplates">
          {{ submitting ? 'Envoi à Meta…' : `Soumettre ${toSubmit > 1 ? `les ${toSubmit} modèles` : 'le modèle'} à Meta` }}
        </button>
      </div>
    </div>
    <div><WhatsAppTabs /></div>

    <p class="text-sm text-slate-600">
      Chaque message a sa ligne, la même pour tout le monde : l'interrupteur décide s'il part. « Voir le texte » montre ce que la personne reçoit.
      Meta doit approuver chaque modèle avant son premier envoi.
    </p>
    <p v-if="settings && settings.mode !== 'live'" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
      WhatsApp n'est pas connecté : ces messages ne partent pas encore.
      <RouterLink to="/admin/whatsapp" class="font-semibold underline">Connecter WhatsApp</RouterLink>
    </p>

    <p v-if="loadError" class="field-error">{{ loadError }}</p>
    <p v-else-if="!data" class="card p-6 text-center text-slate-600">Chargement…</p>

    <template v-else>
      <section v-for="(title, audience) in data.audiences" :key="audience" class="space-y-2">
        <div class="flex flex-wrap items-baseline gap-2">
          <h2 class="text-lg font-semibold">{{ title }}</h2>
          <span class="text-sm text-slate-600">{{ rowsOf(audience).length }} message{{ rowsOf(audience).length > 1 ? 's' : '' }}</span>
        </div>
        <p v-if="audience === 'merchant'" class="rounded-xl bg-blue-50 p-3 text-sm text-blue-900 ring-1 ring-blue-200">
          L'interrupteur donne le réglage de départ de tous les clients : notification sur leur téléphone si elle est activée, sinon WhatsApp.
          Chaque client peut ensuite avoir ses propres choix (Réglages › Messages aux clients).
        </p>

        <div class="card overflow-hidden">
          <div class="hidden grid-cols-[6rem_minmax(0,1fr)_11rem_9rem] gap-3 border-b bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-700 md:grid">
            <span>Envoyer</span><span>Message · quand il part</span><span>Par</span><span>Meta</span>
          </div>
          <div v-for="m in rowsOf(audience)" :key="m.id" class="border-b last:border-b-0">
            <div class="grid grid-cols-[6rem_minmax(0,1fr)] items-center gap-x-3 gap-y-1 px-4 py-3 md:grid-cols-[6rem_minmax(0,1fr)_11rem_9rem]">
              <div>
                <SwitchButton v-if="!m.fixed" :on="m.on" :label="`Envoyer : ${m.title}`" :disabled="busy[m.id]" @toggle="toggle(m)" />
                <span v-else class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 ring-1 ring-slate-300">{{ m.fixed }}</span>
              </div>
              <div class="min-w-0">
                <p class="font-semibold">{{ m.title }}</p>
                <p class="text-sm text-slate-600">
                  {{ m.when }}<template v-if="m.link"> · <RouterLink :to="m.link.to" class="font-semibold text-blue-700 hover:underline">{{ m.link.label }} · modifier</RouterLink></template>
                </p>
                <p v-if="m.note" class="text-sm text-amber-800">{{ m.note }}</p>
                <button type="button" class="mt-0.5 text-xs font-semibold text-blue-700" :aria-expanded="!!opened[m.id]" @click="opened[m.id] = !opened[m.id]">
                  {{ opened[m.id] ? 'Masquer le texte' : 'Voir le texte' }}
                </button>
              </div>
              <span class="col-start-2 text-sm text-slate-700 md:col-start-auto">{{ m.channel }}</span>
              <span :class="['col-start-2 justify-self-start rounded-full px-2 py-0.5 text-xs font-medium md:col-start-auto', META[m.meta_status]?.[1] || 'bg-slate-100 text-slate-700']">
                {{ META[m.meta_status]?.[0] || m.meta_status }}
              </span>
            </div>
            <p v-if="opened[m.id]" class="mx-4 mb-3 break-words rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm [overflow-wrap:anywhere] md:ml-[7.75rem]">{{ m.text }}</p>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import SwitchButton from '../../components/ui/SwitchButton.vue'
import WhatsAppTabs from '../../components/WhatsAppTabs.vue'
import { useToastStore } from '../../stores/toasts'

/**
 * WhatsApp › Messages automatiques : chaque message qui part tout seul, une ligne, la même pour les destinataires, les
 * clients, les livreurs et l'équipe ; l'interrupteur décide de l'envoi (enregistré tout de suite).
 */
const META = {
  approved: ['Approuvé', 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200'],
  pending: ['En attente chez Meta', 'bg-amber-50 text-amber-800 ring-1 ring-amber-200'],
  rejected: ['Refusé par Meta', 'bg-red-50 text-red-700 ring-1 ring-red-200'],
  paused: ['En pause', 'bg-amber-50 text-amber-800 ring-1 ring-amber-200'],
  disabled: ['Désactivé', 'bg-red-50 text-red-700 ring-1 ring-red-200'],
  unknown: ['À soumettre', 'bg-slate-100 text-slate-700 ring-1 ring-slate-300'],
}

const toasts = useToastStore()
const data = ref(null)
const settings = ref(null)
const loadError = ref('')
const busy = reactive({})
const opened = reactive({})
const syncing = ref(false)
const submitting = ref(false)

const rowsOf = (audience) => data.value.messages.filter((m) => m.audience === audience)
const toSubmit = computed(() => (settings.value?.templates || []).filter((t) => ['unknown', 'rejected'].includes(t.status)).length)

async function load() {
  try {
    const [messages, s] = await Promise.all([http.get('/whatsapp/messages'), http.get('/whatsapp/settings')])
    data.value = messages.data.data
    settings.value = s.data.data
    loadError.value = ''
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  }
}

async function toggle(m) {
  busy[m.id] = true
  try {
    const { data: r } = await http.put(`/whatsapp/messages/${m.id}`, { on: !m.on })
    Object.assign(m, r.data)
    toasts.success(m.on ? `« ${m.title} » sera envoyé.` : `« ${m.title} » ne sera plus envoyé.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy[m.id] = false
  }
}

async function sync() {
  syncing.value = true
  try {
    await http.post('/whatsapp/templates/sync')
    await load()
    toasts.success('État des modèles mis à jour.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    syncing.value = false
  }
}

async function submitTemplates() {
  submitting.value = true
  try {
    const { data: r } = await http.post('/whatsapp/templates/submit')
    const errors = Object.entries(r.meta.errors || {})
    if (errors.length) toasts.error(`${errors.length} modèle(s) refusé(s) à l'envoi : ${errors.map(([name, error]) => `${name} (${error})`).join(' ; ')}`)
    else toasts.success(`${r.meta.submitted} modèle(s) envoyé(s) à Meta pour validation.`)
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>
