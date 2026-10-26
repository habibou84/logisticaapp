<template>
  <SettingsPage icon="📝" title="Motifs et notes de suivi" intro="Ce que le livreur choisit quand il ne livre pas ou ne ramasse pas, et les notes de suivi de l'équipe. Les motifs communs se règlent ou se désactivent ; ajoutez les vôtres.">
    <div class="flex flex-wrap gap-2">
      <button v-for="t in TABS" :key="t.key" type="button" class="px-3 py-1.5 rounded-full text-sm border"
        :class="tab === t.key ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700'" @click="tab = t.key; editing = null">
        {{ t.label }} <span class="opacity-70">{{ countFor(t.key) }}</span>
      </button>
    </div>
    <p class="text-sm text-gray-600">{{ currentTab.help }}</p>

    <div class="flex flex-wrap gap-2 items-center">
      <select v-if="tab !== 'follow_up'" v-model="activity" class="input w-auto" aria-label="Activité">
        <option value="">Toutes les activités</option>
        <option v-for="a in ACTIVITIES" :key="a.value" :value="a.value">{{ a.label }}</option>
      </select>
      <label class="flex items-center gap-1.5 text-sm"><input v-model="showInactive" type="checkbox"> Voir les motifs désactivés</label>
      <button type="button" class="btn-primary ml-auto" @click="startNew">+ Nouveau motif</button>
    </div>

    <p v-if="loadError" class="text-red-600 text-sm">{{ loadError }}</p>

    <ReasonEditor v-if="editing && !editing.id" :model="editing" :tab="tab" :saving="saving" :error="error" @save="save" @cancel="editing = null" />

    <section v-for="g in groups" :key="g.key" class="card divide-y">
      <h2 v-if="tab !== 'follow_up'" class="px-4 py-2 font-semibold text-sm bg-gray-50">{{ g.icon }} {{ g.label }}</h2>
      <div v-for="r in g.reasons" :key="r.id">
        <div class="px-4 py-2.5 flex items-start gap-3 cursor-pointer hover:bg-gray-50" :class="{ 'opacity-50': !r.is_active }" @click="edit(r)">
          <div class="flex-1 min-w-0">
            <p class="font-medium">{{ r.label }} <span v-if="r.company_id" class="text-xs text-blue-600">· le vôtre</span></p>
            <p class="flex flex-wrap gap-1 mt-0.5">
              <span v-for="t in tags(r)" :key="t" class="text-[11px] rounded bg-gray-100 text-gray-700 px-1.5">{{ t }}</span>
            </p>
          </div>
          <label class="flex items-center gap-1 text-xs text-gray-600" @click.stop>
            <input type="checkbox" :checked="r.is_active" @change="toggle(r)"> Actif
          </label>
        </div>
        <ReasonEditor v-if="editing?.id === r.id" :model="editing" :tab="tab" :saving="saving" :error="error" :deletable="!!r.company_id"
          class="border-t bg-gray-50" @save="save" @cancel="editing = null" @delete="remove(r)" />
      </div>
    </section>
    <p v-if="!groups.length && !loadError" class="text-sm text-gray-500">Aucun motif.</p>
  </SettingsPage>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import ReasonEditor from '../../../components/settings/ReasonEditor.vue'
import { ACTIVITIES, activityShort } from '../../../utils/activities'
import { DESTINATION_EFFECTS, REASON_FAMILIES } from '../../../utils/reasons'
import { useToastStore } from '../../../stores/toasts'

const TABS = [
  { key: 'delivery', label: 'Non-livraison', help: 'Choisis par le livreur quand il ne peut pas remettre la course. Rangés par famille.' },
  { key: 'pickup', label: 'Ramassage', help: 'Choisis par le livreur quand un colis n\'est pas remis au ramassage.' },
  { key: 'follow_up', label: 'Notes de suivi', help: 'Posées par l\'équipe sur une course (fiche de la course). Le livreur ne les voit pas ; le client seulement si vous le cochez.' },
]

const toasts = useToastStore()
const reasons = ref([])
const tab = ref('delivery')
const activity = ref('')
const showInactive = ref(false)
const editing = ref(null)
const saving = ref(false)
const error = ref('')
const loadError = ref('')

const currentTab = computed(() => TABS.find((t) => t.key === tab.value))
const inTab = (r, key) => (key === 'follow_up' ? r.applies_to === 'follow_up' : r.applies_to === key || r.applies_to === 'both')
const countFor = (key) => reasons.value.filter((r) => r.is_active && inTab(r, key)).length

const groups = computed(() => {
  const list = reasons.value.filter((r) => inTab(r, tab.value)
    && (showInactive.value || r.is_active)
    && (!activity.value || !r.activities?.length || r.activities.includes(activity.value)))
  if (tab.value === 'follow_up') return list.length ? [{ key: 'all', reasons: list }] : []
  return Object.entries(REASON_FAMILIES)
    .map(([key, f]) => ({ key, ...f, reasons: list.filter((r) => (r.family || 'other') === key) }))
    .filter((g) => g.reasons.length)
})

function tags(r) {
  const t = []
  if (r.applies_to === 'both') t.push('Ramassage et livraison')
  if (r.applies_to !== 'follow_up') t.push(r.activities?.length ? r.activities.map(activityShort).join(', ') : 'Toutes activités')
  if (r.counts_as_attempt) t.push('Compte une tentative')
  if (r.triggers_return) t.push('↩️ Retour au client')
  if (r.requires_date) t.push('📅 Date')
  if (r.requires_note) t.push('✏️ Note obligatoire')
  if (r.photo === 'required') t.push('📷 Photo obligatoire')
  if (r.photo === 'optional') t.push('📷 Photo possible')
  if (r.destination_effect) t.push(`📇 ${DESTINATION_EFFECTS[r.destination_effect]}`)
  if (r.holds) t.push('⏸ Met en attente')
  if (r.visible_to_client) t.push('👁 Vu du client')
  return t
}

function blank() {
  return {
    label: '', applies_to: tab.value, family: tab.value === 'follow_up' ? null : (tab.value === 'pickup' ? 'pickup' : 'other'),
    activities: [], requires_date: false, requires_note: false, photo: 'off', counts_as_attempt: tab.value === 'delivery',
    triggers_return: false, destination_effect: null, holds: false, visible_to_client: false, is_active: true,
  }
}

function startNew() {
  error.value = ''
  editing.value = blank()
}

function edit(r) {
  error.value = ''
  editing.value = editing.value?.id === r.id ? null : { ...r, activities: [...(r.activities || [])] }
}

function payload(m) {
  const keys = ['label', 'applies_to', 'family', 'activities', 'requires_date', 'requires_note', 'photo', 'counts_as_attempt',
    'triggers_return', 'destination_effect', 'holds', 'visible_to_client', 'is_active']
  const p = Object.fromEntries(keys.map((k) => [k, m[k]]))
  if (!p.activities?.length) p.activities = null
  return p
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    const m = editing.value
    const { data } = m.id
      ? await http.patch(`/incident-reason-settings/${m.id}`, payload(m))
      : await http.post('/incident-reason-settings', payload(m))
    const i = reasons.value.findIndex((r) => r.id === data.data.id)
    if (i >= 0) reasons.value[i] = data.data
    else reasons.value.push(data.data)
    editing.value = null
    toasts.success('Motif enregistré')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function toggle(r) {
  try {
    const { data } = await http.patch(`/incident-reason-settings/${r.id}`, { is_active: !r.is_active })
    Object.assign(r, data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function remove(r) {
  if (!confirm(`Supprimer « ${r.label} » ?`)) return
  try {
    await http.delete(`/incident-reason-settings/${r.id}`)
    reasons.value = reasons.value.filter((x) => x.id !== r.id)
    editing.value = null
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

onMounted(async () => {
  try {
    reasons.value = (await http.get('/incident-reason-settings')).data.data
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  }
})
</script>
