<template>
  <SettingsPage icon="⏰" title="Alertes et rappels" intro="Quand prévenir le dispatch et les administrateurs. 0 = pas d'alerte.">
    <template v-if="form">
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Courses sans livreur</h2>
        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label class="label" for="pickup-alert">Sans livreur de ramassage depuis plus de (minutes)</label>
            <input id="pickup-alert" v-model.number="form.pickup_assign_alert_minutes" type="number" min="0" max="1440" class="input w-24">
          </div>
          <div>
            <label class="label" for="delivery-alert">Colis récupéré sans livreur de livraison depuis plus de (minutes)</label>
            <input id="delivery-alert" v-model.number="form.delivery_assign_alert_minutes" type="number" min="0" max="1440" class="input w-24">
          </div>
        </div>
        <MoreHelp>
          Au-delà, la course passe en rouge dans la liste, le compteur du menu « Courses » augmente et le dispatch reçoit une alerte sonore
          (une fois par course) ; les administrateurs sont prévenus après trois fois ce délai. Une livraison prévue un autre jour ne compte
          qu'à partir de ce jour.
        </MoreHelp>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Terrain</h2>
        <div>
          <label class="label" for="hold-alert">Colis non livré resté chez un livreur depuis plus de (heures)</label>
          <input id="hold-alert" v-model.number="form.parcel_hold_alert_hours" type="number" min="0" max="168" class="input w-24">
          <p class="text-xs text-gray-500 mt-1">Alerte sonore au dispatch, une fois par colis, et colis en rouge dans « Colis chez les livreurs ».</p>
        </div>
        <div>
          <label class="label" for="reminder">Relancer un problème signalé par un livreur et resté sans suite après (minutes)</label>
          <input id="reminder" v-model.number="form.field_alert_reminder_minutes" type="number" min="0" max="240" class="input w-24">
          <p class="text-xs text-gray-500 mt-1">Relance au dispatch, puis aux administrateurs après trois fois ce délai. Répondre au livreur arrête les relances.</p>
        </div>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Point de la semaine</h2>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.weekly_report" type="checkbox" class="mt-1">
          <span>Envoyer le point de la semaine aux administrateurs<span class="block text-gray-500">Chaque lundi vers 7 h 30, par WhatsApp et dans la cloche : courses, taux de livraison, chiffre d'affaires, e-commerçants à relancer.</span></span>
        </label>
      </section>

      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import MoreHelp from '../../../components/settings/MoreHelp.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'

const { form, dirty, saving, error, save, reset } = useCompanySettings([
  'pickup_assign_alert_minutes', 'delivery_assign_alert_minutes', 'parcel_hold_alert_hours', 'field_alert_reminder_minutes', 'weekly_report',
])
</script>
