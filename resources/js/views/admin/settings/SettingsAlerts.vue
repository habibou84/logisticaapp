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
          <span>Envoyer le point de la semaine aux administrateurs<span class="block text-gray-500">Chaque lundi vers 7 h 30, par WhatsApp et dans la cloche : courses, taux de livraison, chiffre d'affaires, clients à relancer.</span></span>
        </label>
      </section>
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Caisse : argent gardé et clôture</h2>
        <div class="flex flex-wrap gap-4">
          <div>
            <label class="label" for="cash-hours">Argent gardé depuis plus de (heures)</label>
            <input id="cash-hours" v-model.number="form.cash_alert_hours" type="number" min="0" max="336" class="input w-24">
          </div>
          <div>
            <label class="label" for="cash-amount">ou au-dessus de (F)</label>
            <input id="cash-amount" v-model.number="form.cash_alert_amount" type="number" min="0" class="input w-36" placeholder="Pas de plafond">
          </div>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.cash_alert_courier" type="checkbox"> Rappel au livreur par WhatsApp</label>
        <p class="text-xs text-gray-500">
          Alerte aux administrateurs et caissiers dans l'application, une fois par livreur et par jour ; le livreur apparaît
          « à surveiller » dans la caisse. 0 heure et plafond vide = pas d'alerte.
        </p>
        <h3 class="text-sm font-semibold">Clôture de la caisse</h3>
        <div>
          <label class="label" for="cash-close">Rappeler de clôturer la caisse à</label>
          <input id="cash-close" v-model="form.cash_close_reminder_at" type="time" class="input w-32">
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.cash_close_summary" type="checkbox"> Envoyer le résumé de la clôture aux administrateurs par WhatsApp</label>
        <p class="text-xs text-gray-500">
          Rappel dans l'application aux administrateurs et caissiers si la journée n'est pas clôturée (une fois par jour).
          Heure vide = pas de rappel. Le résumé arrive aussi dans la cloche.
        </p>
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
  'pickup_assign_alert_minutes', 'delivery_assign_alert_minutes', 'parcel_hold_alert_hours', 'field_alert_reminder_minutes', 'weekly_report', 'cash_alert_hours', 'cash_alert_amount', 'cash_alert_courier', 'cash_close_reminder_at', 'cash_close_summary',
])
</script>
