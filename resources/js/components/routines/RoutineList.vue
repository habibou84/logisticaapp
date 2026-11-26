<template>
  <!-- Parcours d'un contrat MAD : liste, ajout, modification, suppression -->
  <div class="space-y-3">
    <p v-if="!compact" class="text-sm text-slate-600">
      Un parcours, c'est la routine du matin du livreur avant d'arriver chez {{ plan?.merchant || 'le client' }} : récupérer un colis dans une agence,
      puis dans une autre, puis déposer au siège. Le livreur pointe à la <strong>première étape</strong>, à l'heure du parcours.
      Sans parcours, il pointe au siège du client. Les changements valent tout de suite.
    </p>
    <p v-if="loading" class="py-4 text-center text-slate-400">Chargement…</p>
    <p v-else-if="loadError" class="text-sm text-red-600">{{ loadError }}</p>

    <template v-else-if="editing !== false">
      <RoutineEditor :plan="plan" :routine="editing" :create-url="createUrl" :update-url="updateUrl" :mobile="mobile" @saved="saved" @cancel="editing = false" />
    </template>

    <template v-else>
      <p v-if="!plan" class="text-sm text-slate-600">Aucun contrat de mise à disposition en cours.</p>
      <p v-else-if="!routines.length" class="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Aucun parcours : les livreurs pointent au siège{{ plan.merchant_located ? '' : ' (pas encore placé sur la carte)' }}{{ plan.mad_from ? ` à ${plan.mad_from}` : '' }}.</p>
      <div v-for="r in routines" :key="r.id" :class="['space-y-2 rounded-xl p-3 ring-1', r.is_active ? 'ring-slate-200' : 'ring-slate-100 opacity-60']">
        <div class="flex flex-wrap items-start gap-2">
          <div class="min-w-0 flex-1">
            <p class="font-semibold">{{ r.name }} <span v-if="!r.is_active" class="text-xs font-normal text-slate-600">(inactif)</span></p>
            <p class="text-sm text-slate-600">{{ daysText(r.days) }} · départ {{ r.starts_at || plan.mad_from || 'sans heure' }} · {{ r.courier || 'tous les livreurs du contrat' }}</p>
            <p class="text-xs text-slate-600">Modifié {{ r.source === 'merchant' ? 'par le client' : 'par l\'équipe' }}{{ r.updated_by ? ` (${r.updated_by})` : '' }} le {{ dateTime(r.updated_at) }}</p>
          </div>
          <template v-if="canEdit">
            <button type="button" class="text-sm font-semibold text-[var(--app-color,#1f2937)]" @click="editing = r">Modifier</button>
            <button type="button" class="text-sm font-semibold text-red-600" @click="remove(r)">Supprimer</button>
          </template>
        </div>
        <p v-if="!r.first_located" class="text-xs text-amber-700"><Icone nom="alerte" taille="petite" class="inline align-text-bottom" /> La première étape n'a pas de position sur la carte : le livreur ne pourra pas pointer par GPS.</p>
        <ol class="space-y-1">
          <li v-for="s in r.stops" :key="s.id" class="flex gap-2 text-sm">
            <span :class="['grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white', s.position === 1 ? 'bg-emerald-600' : 'bg-slate-400']">{{ s.position }}</span>
            <span><strong>{{ s.action_label }}</strong> · {{ s.label }}<template v-if="s.expected_time"> · vers {{ s.expected_time }}</template>
              <span v-if="s.instructions" class="text-slate-600"> · « {{ s.instructions }} »</span></span>
          </li>
        </ol>
      </div>
      <button v-if="plan && canEdit" type="button" :class="mobile ? 'm-btn-primary' : 'btn-primary'" @click="editing = null">+ Nouveau parcours</button>
    </template>
  </div>
</template>

<script setup>
import Icone from '../ui/Icone.vue'
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'
import { daysText } from '../../utils/shifts'
import RoutineEditor from './RoutineEditor.vue'
import { confirmDialog } from '../../composables/useDialog'

const props = defineProps({
  // Liste (GET) et création (POST)
  listUrl: { type: String, required: true },
  createUrl: { type: String, required: true },
  // Modification et suppression : suivie de l'identifiant
  updateUrl: { type: String, required: true },
  mobile: { type: Boolean, default: false },
  editable: { type: Boolean, default: true },
  // Page Parcours : l'explication est donnée une fois, en tête de page
  compact: { type: Boolean, default: false },
})
const toasts = useToastStore()
const routines = ref([])
const plan = ref(null)
const canEdit = ref(props.editable)
const loading = ref(true)
const loadError = ref('')
// false : liste ; null : nouveau ; objet : modification
const editing = ref(false)

async function load() {
  try {
    const { data } = await http.get(props.listUrl)
    routines.value = data.data
    plan.value = data.plan
    if (data.can_edit !== undefined) canEdit.value = props.editable && data.can_edit
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function saved() {
  toasts.success('Parcours enregistré : il vaut dès maintenant.')
  editing.value = false
  load()
}

async function remove(r) {
  if (!(await confirmDialog(`Supprimer le parcours « ${r.name} » ?`))) return
  try {
    await http.delete(`${props.updateUrl}/${r.id}`)
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>
