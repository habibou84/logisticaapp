<template>
  <div class="space-y-4">
    <p v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</p>
    <p v-if="!options && !error" class="text-slate-600">Chargement…</p>

    <div v-if="options" class="grid gap-4 lg:grid-cols-5">
      <form class="card space-y-4 p-4 lg:col-span-3" @submit.prevent="download">
        <fieldset>
          <legend class="font-semibold">1. Contenu</legend>
          <p class="text-xs text-slate-600">
            <button type="button" class="text-blue-700 hover:underline" @click="form.sections = options.sections.map((s) => s.key)">Tout cocher</button> ·
            <button type="button" class="text-blue-700 hover:underline" @click="form.sections = []">Tout décocher</button>
          </p>
          <div class="mt-2 grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
            <label v-for="s in options.sections" :key="s.key" class="flex items-start gap-2 text-sm">
              <input v-model="form.sections" type="checkbox" :value="s.key" class="mt-0.5" :disabled="s.amounts && form.hide_amounts">
              <span :class="s.amounts && form.hide_amounts ? 'text-slate-400 line-through' : ''">{{ s.label }}<span v-if="s.key === 'orders'" class="text-slate-600"> (une ligne par course)</span></span>
            </label>
          </div>
        </fieldset>

        <fieldset class="space-y-2">
          <legend class="font-semibold">2. Périmètre</legend>
          <p class="text-sm text-slate-600">Du {{ date(from) }} au {{ date(to) }} : la période se choisit en haut de la page.</p>
          <div v-if="activities.length > 1">
            <label class="label" for="ex-activity">Activités</label>
            <select id="ex-activity" v-model="form.activity" class="input w-auto">
              <option :value="null">Toutes</option>
              <option v-for="a in activities" :key="a.value" :value="a.value">{{ a.label }}</option>
            </select>
          </div>
        </fieldset>

        <fieldset class="space-y-2">
          <legend class="font-semibold">3. Format</legend>
          <div class="inline-flex overflow-hidden rounded-lg border" role="group" aria-label="Format du fichier">
            <button
              v-for="(label, key) in options.formats" :key="key" type="button" :aria-pressed="form.format === key"
              :class="['px-3 py-1.5 text-sm font-semibold', form.format === key ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 hover:bg-slate-50']"
              @click="form.format = key"
            >{{ label }}</button>
          </div>
          <p class="text-xs text-slate-600">{{ FORMAT_HINTS[form.format] }}</p>
          <label v-if="options.can_amounts" class="flex items-center gap-2 text-sm"><input v-model="form.hide_amounts" type="checkbox"> Masquer les montants (les rubriques à montants sont retirées)</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="form.anonymize" type="checkbox"> Remplacer les noms des livreurs par « Livreur 1 », « Livreur 2 »…</label>
        </fieldset>

        <div class="flex flex-wrap items-center gap-2">
          <button type="submit" class="btn-primary" :disabled="busy || !chosen.length">{{ busy === 'download' ? 'Export…' : 'Exporter' }}</button>
          <button v-if="options.can_save" type="button" class="btn-secondary" :disabled="!!busy || !chosen.length" @click="saving = !saving">Enregistrer comme modèle</button>
          <button v-if="options.can_send" type="button" class="btn-secondary" :disabled="!!busy || !chosen.length" @click="sending = !sending">Envoyer par e-mail</button>
          <span v-if="message" class="text-sm text-emerald-700" role="status">{{ message }}</span>
        </div>

        <div v-if="saving" class="flex flex-wrap items-end gap-2 rounded-lg bg-slate-50 p-3">
          <div class="min-w-0 flex-1">
            <label class="label" for="ex-name">Nom du modèle</label>
            <input id="ex-name" v-model="name" type="text" maxlength="60" class="input" placeholder="Point mensuel de la direction">
          </div>
          <button type="button" class="btn-primary" :disabled="!!busy || !name.trim()" @click="saveTemplate">Enregistrer</button>
        </div>
        <div v-if="sending" class="flex flex-wrap items-end gap-2 rounded-lg bg-slate-50 p-3">
          <div class="min-w-0 flex-1">
            <label class="label" for="ex-to">Destinataires (séparés par une virgule, 10 au plus)</label>
            <input id="ex-to" v-model="recipients" type="text" class="input" placeholder="direction@exemple.ci">
          </div>
          <button type="button" class="btn-primary" :disabled="!!busy || !recipients.trim()" @click="send">{{ busy === 'send' ? 'Envoi…' : 'Envoyer' }}</button>
        </div>
      </form>

      <div class="space-y-4 lg:col-span-2">
        <section class="card space-y-2 bg-slate-50 p-4">
          <h2 class="font-semibold">Ce que contiendra le fichier</h2>
          <p class="text-sm"><strong>statistiques-{{ from }}-au-{{ to }}.{{ form.format }}</strong></p>
          <p class="text-sm text-slate-600">
            <template v-if="chosen.length">{{ chosen.length }} rubrique(s) : {{ chosen.map((s) => s.label).join(' · ') }}.</template>
            <template v-else>Cochez au moins une rubrique.</template>
          </p>
        </section>

        <section class="card space-y-2 p-4">
          <h2 class="font-semibold">Modèles enregistrés</h2>
          <p v-if="!options.templates.length" class="text-sm text-slate-600">Aucun modèle. Réglez un export puis « Enregistrer comme modèle » pour le refaire en un clic.</p>
          <ul v-else class="divide-y text-sm">
            <li v-for="t in options.templates" :key="t.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
              <span class="min-w-0"><b>{{ t.name }}</b> <span class="text-slate-600">· {{ options.formats[t.format] }} · {{ t.sections.length }} rubrique(s)</span></span>
              <span class="flex gap-2">
                <button type="button" class="text-blue-700 hover:underline" @click="apply(t)">Reprendre</button>
                <button v-if="options.can_save" type="button" class="text-red-700 hover:underline" @click="removeTemplate(t)">Supprimer</button>
              </span>
            </li>
          </ul>
          <p class="text-xs text-slate-600">Un modèle garde le contenu et le format ; il s'applique à la période choisie en haut de la page.</p>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { myActivities } from '../../utils/activities'
import { date } from '../../utils/format'

const props = defineProps({ from: { type: String, required: true }, to: { type: String, required: true } })

const FORMAT_HINTS = {
  xlsx: 'Une feuille par rubrique, à ouvrir dans Excel ou un tableur.',
  csv: 'Un seul fichier texte, les rubriques l\'une sous l\'autre : pour un autre logiciel.',
  pdf: 'Un document mis en page, prêt à imprimer ou à transmettre. Chaque tableau est limité à ses 150 premières lignes.',
}

const auth = useAuthStore()
const activities = computed(() => myActivities(auth.user))
const options = ref(null)
const form = reactive({ sections: [], format: 'xlsx', activity: null, hide_amounts: false, anonymize: false })
const busy = ref('')
const error = ref('')
const message = ref('')
const saving = ref(false)
const sending = ref(false)
const name = ref('')
const recipients = ref('')

// Rubriques réellement exportées : cochées, et sans celles à montants quand ils sont masqués
const chosen = computed(() => (options.value?.sections ?? []).filter((s) => form.sections.includes(s.key) && !(s.amounts && form.hide_amounts)))
const params = () => ({
  from: props.from, to: props.to, activity: form.activity || undefined, sections: chosen.value.map((s) => s.key), format: form.format,
  hide_amounts: form.hide_amounts ? 1 : 0, anonymize: form.anonymize ? 1 : 0,
})

async function run(kind, action, done) {
  busy.value = kind
  error.value = ''
  message.value = ''
  try {
    await action()
    if (done) message.value = done
  } catch (e) {
    // Une réponse en fichier (blob) porte son message d'erreur en JSON : on le relit
    const blob = e?.response?.data
    error.value = blob instanceof Blob ? (JSON.parse(await blob.text().catch(() => '{}')).message || 'L\'export a échoué.') : apiErrorMessage(e)
  } finally {
    busy.value = ''
  }
}

const download = () => run('download', async () => {
  const { data: blob } = await http.get('/stats/export', { params: params(), responseType: 'blob' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = `statistiques-${props.from}-au-${props.to}.${form.format}`
  link.click()
  URL.revokeObjectURL(link.href)
})

const saveTemplate = () => run('save', async () => {
  const { data } = await http.post('/stats/export/templates', { name: name.value.trim(), options: { ...form, sections: chosen.value.map((s) => s.key) } })
  options.value.templates = data.data.templates
  saving.value = false
  name.value = ''
}, 'Modèle enregistré.')

const removeTemplate = (t) => run('delete', async () => {
  options.value.templates = (await http.delete(`/stats/export/templates/${t.id}`)).data.data.templates
})

const send = () => run('send', async () => {
  const list = recipients.value.split(/[,;\s]+/).filter(Boolean)
  await http.post('/stats/export/send', { ...params(), hide_amounts: form.hide_amounts, anonymize: form.anonymize, recipients: list })
  sending.value = false
}, 'Export envoyé.')

function apply(t) {
  Object.assign(form, { sections: [...t.sections], format: t.format, activity: t.activity, hide_amounts: t.hide_amounts && options.value.can_amounts, anonymize: t.anonymize })
  message.value = `Modèle « ${t.name} » repris : cliquez sur Exporter.`
}

http.get('/stats/export/options').then((r) => {
  options.value = r.data.data
  form.sections = options.value.sections.filter((s) => s.default).map((s) => s.key)
  recipients.value = options.value.recipients.join(', ')
}).catch((e) => { error.value = apiErrorMessage(e) })
</script>
