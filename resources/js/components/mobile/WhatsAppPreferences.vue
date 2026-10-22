<template>
  <section v-if="settings" class="space-y-2">
    <h2 class="px-1 text-sm font-semibold text-slate-500 uppercase tracking-wide">Mes notifications</h2>

    <!-- Notifications sur ce téléphone : remplacent WhatsApp quand elles sont activées -->
    <PushSettings v-if="settings.push_first" audience="merchant" />

    <p v-if="onPhone || !settings.push_first" class="px-1 text-sm text-slate-500">{{ deliveryHint }}</p>

    <div class="m-card divide-y">
      <button type="button" class="tap w-full p-4 flex items-center gap-3 text-left active:bg-slate-50" @click="openPhone">
        <span class="text-2xl">💬</span>
        <span class="flex-1">
          <span class="block text-sm text-slate-500">{{ onPhone ? 'WhatsApp de secours' : 'Messages WhatsApp envoyés au' }}</span>
          <span class="block font-medium">{{ localPhone(settings.messaging_phone) || 'Aucun numéro' }}</span>
        </span>
        <span class="text-sm font-medium text-[var(--app-color)]">Modifier</span>
      </button>
    </div>

    <div class="m-card divide-y">
      <ToggleRow
        v-for="e in settings.events"
        :key="e.event"
        :model-value="e.enabled"
        :label="e.label"
        :description="HINTS[e.event]"
        @update:model-value="setEvent(e, $event)"
      />
    </div>

    <h2 class="px-1 pt-2 text-sm font-semibold text-slate-500 uppercase tracking-wide">Point d'activité</h2>
    <div class="m-card divide-y">
      <template v-for="(r, frequency) in settings.reports" :key="frequency">
        <ToggleRow
          :model-value="r.active"
          :label="frequency === 'daily' ? 'Point du jour' : 'Point de la semaine'"
          :description="frequency === 'daily' ? `Chaque soir à ${r.send_time}` : `Chaque ${WEEKDAYS[r.weekday - 1]} à ${r.send_time}, pour les 7 derniers jours`"
          @update:model-value="setReport(frequency, { active: $event })"
        />
        <div v-if="r.active" class="p-4 pt-2">
          <p class="m-label">Heure d'envoi</p>
          <ChoiceChips
            :model-value="r.send_time"
            :options="(frequency === 'daily' ? DAILY_TIMES : WEEKLY_TIMES).map((t) => ({ value: t, label: t.replace(':', ' h ') }))"
            :columns="3"
            label="Heure d'envoi"
            @update:model-value="setReport(frequency, { send_time: $event })"
          />
        </div>
      </template>
    </div>

    <BottomSheet :open="phoneSheet" title="Numéro WhatsApp" @close="phoneSheet = false">
      <form class="space-y-4" @submit.prevent="savePhone">
        <div>
          <label class="m-label" for="wa-number">Numéro qui reçoit les messages</label>
          <input id="wa-number" v-model="phone" class="m-input" type="tel" inputmode="tel" placeholder="07 00 00 00 00">
          <p class="text-sm text-slate-500 mt-1">Laissez vide pour utiliser le numéro principal de la boutique.</p>
        </div>
        <p v-if="phoneError" class="text-sm text-red-600">{{ phoneError }}</p>
        <button class="m-btn-primary" :disabled="saving">Enregistrer</button>
      </form>
    </BottomSheet>
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BottomSheet from './BottomSheet.vue'
import ChoiceChips from './ChoiceChips.vue'
import PushSettings from './PushSettings.vue'
import ToggleRow from './ToggleRow.vue'
import { push } from '../../composables/usePush'
import { useAuthStore } from '../../stores/auth'
import { localPhone } from '../../utils/format'
import { useToastStore } from '../../stores/toasts'

const HINTS = {
  'order.confirmed': 'Quand une course est validée',
  'order.picked_up': 'Quand le livreur a votre colis',
  'order.delivered': 'Avec le montant encaissé',
  'order.incident': 'Client injoignable, refus, report…',
  'payout.paid': 'Quand votre argent est envoyé',
  'order.returned': 'Quand vos colis non livrés vous sont rapportés',
}
const WEEKDAYS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche']
const DAILY_TIMES = ['18:00', '19:00', '20:00', '21:00', '22:00', '07:00']
const WEEKLY_TIMES = ['08:00', '09:00', '12:00']

const auth = useAuthStore()
const toasts = useToastStore()
const settings = ref(null)
const phoneSheet = ref(false)
const phone = ref('')
const phoneError = ref('')
const saving = ref(false)

const url = () => `/merchants/${auth.user.merchant_id}/notifications`

// Messages reçus en notification (gratuit pour l'entreprise) dès qu'un téléphone est abonné
const onPhone = computed(() => settings.value?.push_first && settings.value.push_devices > 0)
const deliveryHint = computed(() => (onPhone.value
  ? `📱 Vos messages arrivent en notification sur ${settings.value.push_devices > 1 ? `vos ${settings.value.push_devices} appareils` : 'votre téléphone'}. WhatsApp ne sert que si elle n'arrive pas.`
  : '💬 Vos messages arrivent par WhatsApp.'))

async function load() {
  try {
    settings.value = (await http.get(url())).data.data
  } catch {
    settings.value = null // hors ligne : la section est masquée
  }
}

// Notifications activées ou coupées sur ce téléphone : le nombre d'appareils change
watch(() => push.subscribed, (now, before) => {
  if (settings.value && now !== before) load()
})

async function save(payload, success = 'Enregistré.') {
  saving.value = true
  try {
    settings.value = (await http.put(url(), payload)).data.data
    toasts.success(success)
    return true
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return false
  } finally {
    saving.value = false
  }
}

function setEvent(e, value) {
  e.enabled = value // réactif tout de suite, corrigé par la réponse en cas d'erreur
  save({ events: { [e.event]: value } }, value ? `« ${e.label} » activé.` : `« ${e.label} » désactivé.`)
}

function setReport(frequency, change) {
  const current = settings.value.reports[frequency]
  const next = { active: current.active, send_time: current.send_time, weekday: current.weekday, ...change }
  Object.assign(current, next)
  save({ reports: { [frequency]: next } })
}

function openPhone() {
  phone.value = settings.value.whatsapp_phone || ''
  phoneError.value = ''
  phoneSheet.value = true
}

async function savePhone() {
  phoneError.value = ''
  try {
    settings.value = (await http.put(url(), { whatsapp_phone: phone.value || null })).data.data
    phoneSheet.value = false
    toasts.success('Numéro enregistré.')
  } catch (e) {
    phoneError.value = apiErrorMessage(e)
  }
}

onMounted(load)
</script>
