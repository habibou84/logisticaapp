<template>
  <div v-if="info" class="space-y-4">
    <!-- Sans véhicule : en choisir un libre pour la journée -->
    <template v-if="!info.vehicle">
      <p v-if="!info.free_vehicles.length" class="m-card p-4 text-slate-600">Aucun véhicule à votre nom ni de libre. Voyez avec l'agence.</p>
      <div v-else class="m-card p-4 space-y-2">
        <p class="font-semibold">Quel véhicule prenez-vous aujourd'hui ?</p>
        <ChoiceChips v-model="form.vehicle_id" :options="info.free_vehicles.map((v) => ({ value: v.id, icon: VEHICLE_TYPES[v.type]?.icon, label: v.plate, description: [v.name, km(v.odometer)].filter(Boolean).join(' · ') }))" label="Véhicule" />
        <p class="text-xs text-slate-500">Il vous est confié pour la journée et rendu à votre retour.</p>
      </div>
    </template>
    <p v-else class="text-sm text-slate-600">{{ VEHICLE_TYPES[info.vehicle.type]?.icon }} <b>{{ info.vehicle.plate }}</b> · {{ moment === 'end' ? 'au retour' : 'avant de partir' }}</p>

    <template v-if="info.vehicle || form.vehicle_id">
      <div class="m-card p-4">
        <p class="font-semibold">Compteur</p>
        <div class="mt-2 flex gap-3">
          <label class="grid h-20 w-24 shrink-0 cursor-pointer place-items-center overflow-hidden rounded-xl bg-slate-800 text-center text-xs text-emerald-300">
            <img v-if="previews.odometer" :src="previews.odometer" alt="Photo du compteur" class="h-full w-full object-cover">
            <span v-else>📷<br>Photo{{ info.settings.check_odometer_photo ? ' *' : '' }}</span>
            <input type="file" accept="image/*" capture="environment" class="hidden" @change="addPhoto('odometer', $event)">
          </label>
          <div class="flex-1">
            <input v-model.number="form.odometer" type="number" inputmode="numeric" min="0" class="m-input text-2xl font-bold" aria-label="Kilométrage du compteur" :placeholder="String(lastKm)">
            <p class="mt-1 text-xs text-slate-500">{{ form.odometer && form.odometer >= lastKm ? `+${(form.odometer - lastKm).toLocaleString('fr-FR')} km depuis le dernier relevé` : `Dernier relevé : ${km(lastKm)}` }}</p>
          </div>
        </div>
      </div>

      <div class="m-card p-4">
        <p class="font-semibold">Essence</p>
        <div class="mt-2 flex gap-1.5">
          <button v-for="f in info.settings.fuel_levels" :key="f.value" type="button" :class="['tap flex-1 rounded-xl py-2.5 text-sm font-semibold', form.fuel === f.value ? 'bg-[color-mix(in_srgb,var(--app-color)_10%,white)] ring-2 ring-[var(--app-color)]' : 'bg-white ring-1 ring-slate-200']" @click="form.fuel = f.value">{{ f.label }}</button>
        </div>
      </div>

      <div v-if="info.settings.check_items.length" class="m-card p-4">
        <p class="font-semibold">Contrôle rapide</p>
        <div class="mt-2 space-y-1.5">
          <div v-for="i in info.settings.check_items" :key="i.value" class="flex items-center justify-between rounded-xl px-3 py-2 ring-1 ring-slate-200">
            <span>{{ i.icon }} {{ i.label }}</span>
            <span class="flex gap-1">
              <button type="button" :class="['tap rounded-lg px-2.5 py-1 text-sm font-semibold', form.items[i.value] === 'ok' ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600']" @click="form.items[i.value] = 'ok'">OK</button>
              <button type="button" :class="['tap rounded-lg px-2.5 py-1 text-sm font-semibold', form.items[i.value] === 'problem' ? 'bg-red-600 text-white' : 'bg-slate-100 text-slate-600']" @click="form.items[i.value] = 'problem'">Problème</button>
            </span>
          </div>
        </div>
        <template v-if="problems">
          <textarea v-model="form.note" rows="2" class="m-input mt-3" placeholder="Qu'est-ce qui ne va pas ? Ex. rétroviseur gauche cassé" />
          <label class="m-btn-secondary mt-2 cursor-pointer">📷 Photo du problème ({{ photos.length - (photosByKind.odometer ? 1 : 0) }})<input type="file" accept="image/*" capture="environment" class="hidden" @change="addPhoto('problem', $event)"></label>
          <p class="mt-2 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-800">L'agence est prévenue ; vous pouvez partir si le véhicule roule sans danger.</p>
        </template>
      </div>

      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <button type="button" class="m-btn-success py-4 text-lg" :disabled="busy || !canSend" @click="send">
        {{ busy ? 'Envoi…' : moment === 'end' ? '✓ J\'ai rendu le véhicule' : info.vehicle ? '✓ C\'est bon, je commence' : '✓ Je prends ce véhicule et je commence' }}
      </button>
    </template>
  </div>
  <p v-else class="py-10 text-center text-slate-400">Chargement…</p>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import { currentPosition } from '../../composables/useGeolocation'
import { shrinkPhoto } from '../../composables/useFieldEvents'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { VEHICLE_TYPES, km } from '../../utils/vehicles'

// État des lieux du véhicule : au départ (puis mise en service), ou au retour (?moment=end)
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const info = ref(null)
const busy = ref(false)
const error = ref('')
const moment = computed(() => (route.query.moment === 'end' ? 'end' : 'start'))
const form = reactive({ vehicle_id: null, odometer: null, fuel: null, items: {}, note: '' })
const photosByKind = reactive({})
const extra = ref([])
const previews = reactive({})
const photos = computed(() => [photosByKind.odometer, ...extra.value].filter(Boolean))
const problems = computed(() => Object.values(form.items).filter((v) => v === 'problem').length)
const lastKm = computed(() => info.value?.vehicle?.odometer ?? info.value?.free_vehicles.find((v) => v.id === form.vehicle_id)?.odometer ?? 0)
const canSend = computed(() => !!form.odometer && (!info.value.settings.check_odometer_photo || !!photosByKind.odometer))

async function addPhoto(kind, e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  const small = await shrinkPhoto(file)
  if (kind === 'odometer') {
    photosByKind.odometer = small
    previews.odometer = URL.createObjectURL(small)
  } else {
    extra.value.push(small)
  }
}

async function send() {
  busy.value = true
  error.value = ''
  const body = new FormData()
  body.append('moment', moment.value)
  if (form.vehicle_id) body.append('vehicle_id', form.vehicle_id)
  body.append('odometer', form.odometer)
  if (form.fuel) body.append('fuel', form.fuel)
  Object.entries(form.items).forEach(([k, v]) => body.append(`items[${k}]`, v))
  if (form.note.trim()) body.append('note', form.note.trim())
  photos.value.forEach((p, i) => body.append('photos[]', p, `photo-${i + 1}.jpg`))
  try {
    await http.post('/courier/vehicle/checks', body)
    if (moment.value === 'start' && !auth.user?.courier?.is_available) {
      // État des lieux fait : mise en service
      const position = await currentPosition()
      const { data } = await http.patch('/courier/status', { is_available: true, ...(position || {}) }).catch(() => ({ data: null }))
      if (data && auth.user.courier) auth.user.courier.is_available = data.data.is_available
    }
    toasts.success(moment.value === 'end' ? 'Merci, véhicule rendu.' : 'État des lieux enregistré : bonne journée !')
    router.push('/livreur')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  info.value = (await http.get('/courier/vehicle')).data.data
  // Tout est OK par défaut : le livreur ne touche que ce qui ne va pas
  info.value.settings.check_items.forEach((i) => { form.items[i.value] = 'ok' })
  if (!info.value.vehicle && info.value.free_vehicles.length === 1) form.vehicle_id = info.value.free_vehicles[0].id
})
onBeforeUnmount(() => previews.odometer && URL.revokeObjectURL(previews.odometer))
</script>
