<template>
  <SettingsPage icon="⭐" title="Notation des livreurs" intro="Qui note les livreurs, comment la note se calcule, ce que voit le livreur et à quoi servent les notes.">
    <template v-if="form?.rating_settings">
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">La note finale (sur 5)</h2>
        <p class="text-sm text-gray-600">
          Trois sources, pondérées : la note automatique (faits enregistrés par l'application), les notes des clients et celles de
          l'équipe. Une source sans notes ne compte pas, les autres se partagent son poids.
        </p>
        <div class="grid sm:grid-cols-3 gap-3">
          <div><label class="label" for="w-auto">Note automatique (%)</label><input id="w-auto" v-model.number="r.weight_auto" type="number" min="0" max="100" class="input w-28"></div>
          <div><label class="label" for="w-client">Clients (%)</label><input id="w-client" v-model.number="r.weight_client" type="number" min="0" max="100" class="input w-28"></div>
          <div><label class="label" for="w-team">Équipe (%)</label><input id="w-team" v-model.number="r.weight_team" type="number" min="0" max="100" class="input w-28"></div>
        </div>
        <p v-if="weightTotal !== 100" class="text-xs text-amber-700">Total : {{ weightTotal }} % (les poids sont ramenés à 100 %).</p>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label" for="window">Période prise en compte (jours)</label><input id="window" v-model.number="r.window_days" type="number" min="7" max="365" class="input w-28"></div>
          <div>
            <label class="label" for="min-client">Notes de clients avant de compter leur moyenne</label>
            <input id="min-client" v-model.number="r.min_client_ratings" type="number" min="1" max="100" class="input w-28">
            <p class="text-xs text-gray-500 mt-1">En dessous, « nouveau livreur » côté clients.</p>
          </div>
        </div>
        <div>
          <p class="label">Indicateurs de la note automatique</p>
          <div class="grid sm:grid-cols-2 gap-1">
            <label v-for="i in criteria.indicators" :key="i.value" class="flex items-center gap-2 text-sm">
              <input v-model="r.auto_indicators" type="checkbox" :value="i.value"> {{ i.label }}
            </label>
          </div>
        </div>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Les clients notent</h2>
        <label class="flex items-start gap-2 text-sm"><input v-model="r.client_course" type="checkbox" class="mt-1"><span>Chaque course terminée, dans leur application<span class="block text-xs text-gray-500">Étoiles, bons points en un clic, motif si la note est basse, commentaire.</span></span></label>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label" for="edit-hours">Délai pour noter ou changer sa note (heures)</label><input id="edit-hours" v-model.number="r.client_edit_hours" type="number" min="1" max="720" class="input w-28"></div>
          <div>
            <label class="label" for="mad-period">Clients en MAD : note de leur livreur attitré</label>
            <select id="mad-period" v-model="r.client_mad_period" class="input w-auto">
              <option value="off">Non</option>
              <option value="weekly">Chaque semaine</option>
              <option value="monthly">Chaque mois</option>
            </select>
          </div>
        </div>
        <label class="flex items-start gap-2 text-sm"><input v-model="r.whatsapp_invite" type="checkbox" class="mt-1"><span>Inviter le client à noter par WhatsApp après la livraison<span class="block text-xs text-gray-500">Modèle « course_a_noter » à faire approuver dans le compte WhatsApp.</span></span></label>
        <label class="flex items-start gap-2 text-sm"><input v-model="r.recipient_rating" type="checkbox" class="mt-1"><span>Le destinataire peut aussi noter, sur la page de suivi<span class="block text-xs text-gray-500">Avec le code de livraison, une fois, dans les 7 jours.</span></span></label>
        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label class="label" for="reason-below">Motif demandé, et responsable prévenu, à partir de</label>
            <select id="reason-below" v-model.number="r.reason_below" class="input w-auto">
              <option :value="0">Jamais</option>
              <option v-for="n in 4" :key="n" :value="n">{{ n }} étoile{{ n > 1 ? 's' : '' }} ou moins</option>
            </select>
          </div>
          <div>
            <label class="label" for="incident-weight">Course où le livreur a signalé un problème : la note compte</label>
            <select id="incident-weight" v-model.number="r.incident_weight" class="input w-auto">
              <option :value="1">Entièrement</option>
              <option :value="0.5">À moitié</option>
              <option :value="0.25">Au quart</option>
              <option :value="0">Pas du tout</option>
            </select>
          </div>
        </div>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Ce que voit le livreur</h2>
        <label class="flex items-center gap-2 text-sm"><input v-model="r.courier_sees_score" type="checkbox"> Sa note et le détail (dans son application)</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="r.courier_sees_comments" type="checkbox" :disabled="!r.courier_sees_score"> Les commentaires des clients</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="r.comments_anonymous" type="checkbox" :disabled="!r.courier_sees_comments"> Sans le nom du client</label>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Alertes et usages</h2>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label" for="alert-below">Alerte si la note passe sous</label><input id="alert-below" v-model.number="r.alert_below" type="number" min="0" max="5" step="0.1" class="input w-28"></div>
          <div><label class="label" for="alert-bad">Ou après ce nombre de mauvaises notes en 7 jours (0 : jamais)</label><input id="alert-bad" v-model.number="r.alert_bad_count" type="number" min="0" max="50" class="input w-28"></div>
        </div>
        <label class="flex items-start gap-2 text-sm"><input v-model="r.assignment_preference" type="checkbox" class="mt-1"><span>Assignation automatique : à égalité, préférer les livreurs les mieux notés</span></label>
        <label class="flex items-start gap-2 text-sm"><input v-model="r.client_choice" type="checkbox" class="mt-1"><span>Les clients peuvent choisir un livreur préféré ou en écarter un<span class="block text-xs text-gray-500">Livreur écarté : il ne reçoit plus automatiquement les courses de ce client. Préféré : prioritaire s'il est disponible.</span></span></label>
        <p class="text-xs text-gray-500">Prime sur la note : Paie des livreurs › Primes d'objectifs › « Note du livreur ».</p>
      </section>

      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '../../../bootstrap/axios'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'

const { form, dirty, saving, error, save, reset } = useCompanySettings(['rating_settings'])
const r = computed(() => form.value.rating_settings)
const weightTotal = computed(() => (r.value.weight_auto || 0) + (r.value.weight_client || 0) + (r.value.weight_team || 0))
const criteria = ref({ indicators: [] })

onMounted(async () => {
  criteria.value = (await http.get('/ratings/criteria')).data.data
})
</script>
