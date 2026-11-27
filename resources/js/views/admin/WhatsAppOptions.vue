<template>
  <div class="max-w-4xl space-y-4">
    <div>
      <h1 class="text-xl font-bold">WhatsApp</h1>
      <p class="text-sm text-slate-600">Le numéro de l'entreprise, les messages qui partent tout seuls, et comment ils partent.</p>
    </div>
    <div><WhatsAppTabs /></div>

    <p v-if="loadError" class="field-error">{{ loadError }}</p>
    <p v-else-if="!s" class="card p-6 text-center text-slate-600">Chargement…</p>

    <template v-else>
      <!-- Comment les messages partent -->
      <section class="card divide-y">
        <div class="flex items-start gap-4 p-4">
          <SwitchButton :on="!!s.sms_fallback" label="SMS de secours" :disabled="busy" @toggle="save('sms_fallback', !s.sms_fallback)" />
          <div class="min-w-0 text-sm">
            <p class="font-semibold">SMS de secours</p>
            <p class="text-slate-600">
              Un SMS part si le message WhatsApp n'arrive pas : numéro sans WhatsApp ou échec définitif.
              <template v-if="s.sms_driver === 'none'">Les SMS sont désactivés sur ce serveur.</template>
              <template v-else-if="s.sms_driver === 'log'">Les SMS sont simulés sur ce serveur.</template>
            </p>
          </div>
        </div>
        <div class="flex items-start gap-4 p-4">
          <SwitchButton :on="!!s.merchant_push_first" label="Notification avant WhatsApp" :disabled="busy || !s.push_enabled" @toggle="save('merchant_push_first', !s.merchant_push_first)" />
          <div class="min-w-0 text-sm">
            <p class="font-semibold">Notification avant WhatsApp, pour les clients</p>
            <p class="text-slate-600">
              Gratuit : le client est prévenu par une notification sur son téléphone. WhatsApp ne sert que s'il n'a pas activé les
              notifications dans son application, ou si la notification n'arrive pas.
              <template v-if="!s.push_enabled">Les notifications ne sont pas configurées sur ce serveur.</template>
              <template v-else-if="s.merchants_total">
                <strong>{{ s.merchants_with_push }} client{{ s.merchants_with_push > 1 ? 's' : '' }} sur {{ s.merchants_total }}</strong> reçoivent déjà les notifications.
              </template>
            </p>
          </div>
        </div>
      </section>

      <!-- Courses reçues sur WhatsApp -->
      <section class="card space-y-3 p-4">
        <div class="flex items-start gap-4">
          <SwitchButton :on="!!s.whatsapp_orders" label="Courses par WhatsApp" :disabled="busy" @toggle="save('whatsapp_orders', !s.whatsapp_orders)" />
          <div class="min-w-0 flex-1 text-sm">
            <p class="font-semibold">Courses par WhatsApp</p>
            <p class="text-slate-600">
              Les clients créent des courses en écrivant au numéro de l'entreprise. Le robot demande ce qui manque, affiche un récapitulatif
              avec le prix et crée la course après confirmation. Menu : nouvelle course, point du jour, suivi d'un colis.
            </p>
            <p :class="['mt-1', s.ai_parser ? 'text-emerald-700' : 'text-slate-600']">
              <Icone v-if="s.ai_parser" nom="ia" taille="petite" class="inline" /> {{ s.ai_parser ? 'Les messages libres sont compris par Claude.' : 'Les messages sont lus par des règles (numéros, montants, communes, format « Nom : … »).' }}
            </p>
          </div>
          <RouterLink to="/admin/whatsapp/simulateur" class="btn-secondary shrink-0">Essayer le robot</RouterLink>
        </div>
      </section>

      <p class="text-sm text-slate-600">
        Quels messages partent, un par un : onglet <RouterLink to="/admin/whatsapp/messages" class="font-semibold text-blue-700 hover:underline">Messages automatiques</RouterLink>.
        Ce que chaque client reçoit, étape par étape :
        <RouterLink v-if="auth.can('settings.manage')" to="/admin/reglages/messages-clients" class="font-semibold text-blue-700 hover:underline">Messages aux clients</RouterLink><template v-else>Réglages › Messages aux clients</template>.
      </p>
    </template>
  </div>
</template>

<script setup>
import Icone from '../../components/ui/Icone.vue'
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import SwitchButton from '../../components/ui/SwitchButton.vue'
import WhatsAppTabs from '../../components/WhatsAppTabs.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

/** WhatsApp › Options d'envoi : SMS de secours, notification avant WhatsApp, courses créées par WhatsApp. */
const auth = useAuthStore()
const toasts = useToastStore()
const s = ref(null)
const loadError = ref('')
const busy = ref(false)

async function load() {
  try {
    s.value = (await http.get('/whatsapp/settings')).data.data
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  }
}

async function save(key, value) {
  busy.value = true
  try {
    s.value = (await http.put('/whatsapp/settings', { [key]: value })).data.data
    toasts.success('Enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
