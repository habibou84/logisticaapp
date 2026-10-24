<template>
  <BottomSheet :open="open" :title="type ? `${type.icon} ${type.label}` : 'Que se passe-t-il ?'" @close="$emit('close')">
    <p v-if="!setup" class="text-center text-slate-400 py-6">Chargement…</p>
    <p v-else-if="!setup.enabled" class="text-slate-600 py-4">Le signalement n'est pas activé par l'agence. Appelez-la directement.</p>

    <!-- 1. Le type, en grosses icônes -->
    <div v-else-if="!type" class="grid grid-cols-2 gap-2">
      <button
        v-for="t in setup.types"
        :key="t.key"
        type="button"
        :class="['tap rounded-2xl p-3 text-left ring-1 active:scale-[0.98]', t.severity === 'urgent' ? 'ring-red-200 bg-red-50' : 'ring-slate-200 bg-white']"
        @click="choose(t)"
      >
        <span class="block text-3xl leading-none">{{ t.icon }}</span>
        <span class="block font-semibold mt-1.5">{{ t.label }}</span>
        <span v-if="t.hint" class="block text-xs text-slate-500 leading-snug">{{ t.hint }}</span>
      </button>
    </div>

    <!-- 2. Les détails -->
    <div v-else class="space-y-4">
      <button type="button" class="text-sm font-medium text-[var(--app-color)]" @click="type = null">← Changer de type</button>

      <div>
        <p class="m-label">Pouvez-vous continuer ?</p>
        <ChoiceChips v-model="form.capacity" :options="capacities" label="Pouvez-vous continuer ?" />
        <div v-if="form.capacity === 'blocked'" class="mt-2">
          <p class="m-label">Pendant combien de temps, à peu près ?</p>
          <ChoiceChips v-model="form.blocked_minutes" :options="durations" :columns="2" label="Durée" />
        </div>
      </div>

      <!-- Photo et note vocale -->
      <div class="grid grid-cols-2 gap-2">
        <label :class="['m-btn-secondary cursor-pointer text-sm px-2', type.photo && !photos.length ? 'ring-2 ring-amber-400' : '']">
          📷 {{ photos.length ? `Photo (${photos.length})` : 'Photo' }}{{ type.photo ? ' *' : '' }}
          <input type="file" accept="image/*" capture="environment" class="hidden" :disabled="photos.length >= 3" @change="addPhoto">
        </label>
        <button v-if="setup.voice_notes && canRecord" type="button" :class="['m-btn-secondary text-sm px-2', recording ? 'ring-2 ring-red-400 text-red-700' : '']" @click="recording ? stopRecording() : startRecording()">
          {{ recording ? `⏹ Arrêter (${seconds} s)` : voice ? '🎙️ Refaire la note' : '🎙️ Note vocale' }}
        </button>
      </div>
      <div v-if="photos.length || voice" class="flex flex-wrap items-center gap-2">
        <span v-for="(p, i) in previews" :key="i" class="relative">
          <img :src="p" alt="" class="h-16 w-16 rounded-xl object-cover ring-1 ring-slate-200">
          <button type="button" class="absolute -top-2 -right-2 h-6 w-6 rounded-full bg-slate-800 text-white text-xs" :aria-label="`Retirer la photo ${i + 1}`" @click="removePhoto(i)">✕</button>
        </span>
        <span v-if="voice" class="flex items-center gap-2 rounded-xl bg-slate-100 px-2 py-1">
          <audio :src="voiceUrl" controls class="h-9 w-44" />
          <button type="button" class="text-slate-500" aria-label="Supprimer la note vocale" @click="clearVoice">✕</button>
        </span>
      </div>

      <textarea v-model="form.note" rows="2" class="m-input" placeholder="En quelques mots (facultatif) : ex. pneu crevé à Cocody, je cherche un réparateur" />

      <div v-if="setup.courier_cost">
        <label class="m-label" for="fe-cost">Coût du dépannage (facultatif)</label>
        <div class="relative">
          <input id="fe-cost" v-model.number="form.cost" type="number" min="0" inputmode="numeric" class="m-input pr-12">
          <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 font-semibold">F</span>
        </div>
      </div>

      <!-- Courses touchées -->
      <div v-if="setup.orders.length">
        <p class="m-label">Courses touchées</p>
        <div class="space-y-1 max-h-48 overflow-y-auto">
          <label v-for="o in setup.orders" :key="o.id" class="flex items-center gap-3 rounded-xl px-3 py-2 ring-1 ring-slate-200">
            <input v-model="form.order_ids" type="checkbox" :value="o.id" class="h-5 w-5">
            <span class="min-w-0 text-sm">
              <span class="block truncate">{{ o.label }}</span>
              <span class="block font-mono text-xs text-slate-500">{{ o.tracking_code }}</span>
            </span>
          </label>
        </div>
      </div>

      <p v-if="type.gps" class="text-xs text-slate-500">📍 Votre position est jointe pour que l'agence sache où vous êtes.</p>
      <p v-if="error" class="text-red-600 text-sm">{{ error }}</p>
      <button type="button" :class="type.severity === 'urgent' || type.severity === 'blocking' ? 'm-btn-danger' : 'm-btn-primary'" :disabled="busy || recording || (type.photo && !photos.length)" @click="send">
        {{ busy ? 'Envoi…' : 'Envoyer à l\'agence' }}
      </button>
    </div>
  </BottomSheet>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BottomSheet from '../mobile/BottomSheet.vue'
import ChoiceChips from '../mobile/ChoiceChips.vue'
import { currentPosition } from '../../composables/useGeolocation'
import { sendFieldEvent, shrinkPhoto } from '../../composables/useFieldEvents'

/**
 * Fiche « Signaler » du livreur : le type (grosses icônes), puis s'il peut continuer, photo, note vocale, mot,
 * coût du dépannage et courses touchées (toutes cochées). Part même sans réseau (envoi au retour du réseau).
 */
const props = defineProps({ open: { type: Boolean, default: false } })
const emit = defineEmits(['close', 'sent'])

const setup = ref(null)
const type = ref(null)
const busy = ref(false)
const error = ref('')
const photos = ref([])
const previews = ref([])
const voice = ref(null)
const voiceUrl = ref('')
const voiceSeconds = ref(0)
const recording = ref(false)
const seconds = ref(0)
const form = reactive({ capacity: 'continue', blocked_minutes: 60, note: '', cost: null, order_ids: [] })

const capacities = [
  { value: 'continue', label: 'Je peux continuer', icon: '✅' },
  { value: 'blocked', label: 'Je suis bloqué un moment', icon: '⏳' },
  { value: 'stopped', label: 'J\'arrête pour aujourd\'hui', icon: '🛑' },
]
const durations = [
  { value: 30, label: '30 min' },
  { value: 60, label: '1 heure' },
  { value: 120, label: '2 heures' },
  { value: 240, label: '4 heures' },
]
const canRecord = computed(() => typeof window !== 'undefined' && !!window.MediaRecorder && !!navigator.mediaDevices?.getUserMedia)

async function loadSetup() {
  try {
    setup.value = (await http.get('/courier/field-events/setup')).data.data
    try { localStorage.setItem('field-events-setup', JSON.stringify(setup.value)) } catch { /* stockage indisponible */ }
  } catch {
    // Sans réseau : derniers réglages connus
    try { setup.value = JSON.parse(localStorage.getItem('field-events-setup') || 'null') } catch { setup.value = null }
    // Courses inconnues hors connexion : toutes celles en main seront rattachées à l'envoi
    if (setup.value) setup.value = { ...setup.value, orders: [], offline: true }
  }
}

function reset() {
  type.value = null
  error.value = ''
  photos.value = []
  previews.value.forEach((u) => URL.revokeObjectURL(u))
  previews.value = []
  clearVoice()
  Object.assign(form, { capacity: 'continue', blocked_minutes: 60, note: '', cost: null, order_ids: (setup.value?.orders || []).map((o) => o.id) })
}

watch(() => props.open, async (open) => {
  if (!open) {
    if (recording.value) stopRecording()
    return
  }
  await loadSetup()
  reset()
})

function choose(t) {
  type.value = t
  form.capacity = ['blocking', 'urgent'].includes(t.severity) ? 'blocked' : 'continue'
}

async function addPhoto(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  const small = await shrinkPhoto(file)
  photos.value.push(small)
  previews.value.push(URL.createObjectURL(small))
}

function removePhoto(i) {
  URL.revokeObjectURL(previews.value[i])
  photos.value.splice(i, 1)
  previews.value.splice(i, 1)
}

// Note vocale : enregistrement limité à la durée choisie par l'agence
let recorder = null
let chunks = []
let timer = null
let stream = null

async function startRecording() {
  error.value = ''
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch {
    error.value = 'Micro indisponible : autorisez-le dans les réglages du téléphone, ou écrivez quelques mots.'
    return
  }
  chunks = []
  recorder = new MediaRecorder(stream)
  recorder.ondataavailable = (ev) => ev.data.size && chunks.push(ev.data)
  recorder.onstop = () => {
    stream?.getTracks().forEach((t) => t.stop())
    clearVoice()
    voice.value = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' })
    voiceUrl.value = URL.createObjectURL(voice.value)
    voiceSeconds.value = seconds.value
  }
  recorder.start()
  recording.value = true
  seconds.value = 0
  timer = setInterval(() => {
    seconds.value++
    if (seconds.value >= (setup.value?.voice_max_seconds || 60)) stopRecording()
  }, 1000)
}

function stopRecording() {
  clearInterval(timer)
  recording.value = false
  if (recorder?.state === 'recording') recorder.stop()
}

function clearVoice() {
  if (voiceUrl.value) URL.revokeObjectURL(voiceUrl.value)
  voice.value = null
  voiceUrl.value = ''
  voiceSeconds.value = 0
}

async function send() {
  busy.value = true
  error.value = ''
  try {
    const position = type.value.gps ? await currentPosition(5000) : null
    const fields = {
      type: type.value.key,
      capacity: form.capacity,
      blocked_minutes: form.capacity === 'blocked' ? form.blocked_minutes : null,
      note: form.note.trim() || null,
      cost: form.cost || null,
      ...(position || {}),
      all_orders: !!setup.value.offline,
      order_ids: form.order_ids,
      voice_duration: voice.value ? voiceSeconds.value : null,
    }
    const result = await sendFieldEvent({ fields, photos: photos.value, voice: voice.value })
    emit('sent', result)
    emit('close')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onBeforeUnmount(() => {
  stopRecording()
  reset()
})
</script>
