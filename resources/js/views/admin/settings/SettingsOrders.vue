<template>
  <SettingsPage icon="📦" title="Courses et livraison" intro="Comment les courses sont validées, quand la journée se termine et comment les livreurs travaillent.">
    <template v-if="form">
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Validation des nouvelles courses</h2>
        <div class="space-y-2" role="radiogroup" aria-label="Validation des nouvelles courses">
          <label v-for="m in VALIDATION_MODES" :key="m.value" :class="['flex items-start gap-2 rounded-lg border p-3 text-sm cursor-pointer', form.validation_mode === m.value ? 'border-blue-500 bg-blue-50' : 'border-slate-200']">
            <input v-model="form.validation_mode" type="radio" :value="m.value" class="mt-1">
            <span><strong>{{ m.label }}</strong><span class="block text-gray-500">{{ m.help }}</span></span>
          </label>
          <p v-if="form.validation_mode === 'rules' && !form.daily_cutoff_time" class="text-xs text-amber-700">
            Choisissez l'heure limite du jour ci-dessous : sans elle, seule la fermeture de la journée par le dispatch décale les courses.
          </p>
        </div>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Journée</h2>
        <div>
          <label class="label" for="cutoff">Heure limite du jour</label>
          <input id="cutoff" v-model="form.daily_cutoff_time" type="time" class="input w-32">
          <p class="text-xs text-gray-500 mt-1">Passé cette heure, les courses du jour encore sans livreur sont signalées et le dispatch est alerté. Vide = pas d'heure limite.</p>
          <MoreHelp>
            Les courses concernées sont signalées dans la liste et le tableau de bord, et les administrateurs et le dispatch reçoivent
            une alerte (une fois par jour). En validation avec règles, les nouvelles courses du jour arrivées après cette heure
            sont validées pour le lendemain.
          </MoreHelp>
          <details class="mt-2" :open="Object.keys(form.cutoff_by_day || {}).some((d) => form.cutoff_by_day[d])">
            <summary class="text-sm text-blue-600 cursor-pointer">Heure limite différente certains jours</summary>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-2">
              <div v-for="(day, i) in WEEKDAYS" :key="day">
                <label class="label" :for="`cutoff-${i + 1}`">{{ day }}</label>
                <input :id="`cutoff-${i + 1}`" v-model="form.cutoff_by_day[i + 1]" type="time" class="input w-full">
              </div>
            </div>
            <p class="text-xs text-gray-500 mt-1">Un jour laissé vide suit l'heure limite commune.</p>
          </details>
        </div>
        <div>
          <label class="label" for="attempts">Nombre maximal de tentatives de livraison</label>
          <input id="attempts" v-model.number="form.default_max_attempts" type="number" min="1" max="10" class="input w-24">
        </div>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Livreurs</h2>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.require_delivery_code" type="checkbox" class="mt-1">
          <span>Exiger le code de livraison du destinataire<span class="block text-gray-500">Le destinataire reçoit son code sur WhatsApp quand le livreur part livrer, ou par le client.</span></span>
        </label>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.offline_courier_actions" type="checkbox" class="mt-1">
          <span>Mode hors connexion des livreurs
            <span class="block text-gray-500">Sans réseau, le livreur enregistre quand même ses étapes ; l'application les envoie au retour du réseau.</span>
          </span>
        </label>
        <MoreHelp>
          Hors connexion, le livreur peut enregistrer « récupéré », « en route », « livré » (avec l'encaissement), un échec, un report ou une note,
          à l'heure où ils ont été faits. Une livraison avec un code du destinataire faux est gardée mais signalée (file « Codes à vérifier »).
          Les WhatsApp au destinataire et au client partent au retour du réseau.
        </MoreHelp>
        <div>
          <p class="label">Après une mission ramassage, les colis…</p>
          <div class="grid sm:grid-cols-3 gap-2">
            <label v-for="m in CUSTODY_MODES" :key="m.value" :class="['rounded-lg border p-3 text-sm cursor-pointer', form.pickup_mission_custody === m.value ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200']">
              <input v-model="form.pickup_mission_custody" type="radio" :value="m.value" class="mr-1">
              <strong>{{ m.label }}</strong>
              <span class="block text-xs text-gray-500 mt-1">{{ m.help }}</span>
            </label>
          </div>
        </div>
        <div v-if="form.pickup_mission_custody !== 'keep'">
          <label class="label" for="depot-closes">Heure de fermeture du dépôt</label>
          <input id="depot-closes" v-model="form.depot_closes_at" type="time" class="input w-32">
          <p class="text-xs text-gray-500 mt-1">Passé cette heure, le livreur garde les colis d'une mission ramassage jusqu'à leur livraison. Vide = dépôt toujours ouvert.</p>
        </div>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Ramassage automatique</h2>
        <p class="text-sm text-gray-600">
          Les ramassages des courses validées vont au livreur prévu par le
          <RouterLink to="/admin/planning" class="text-blue-600 underline">planning des livreurs</RouterLink>
          (ou à son remplaçant). Sans règle pour la course, rien ne change : l'équipe la confie.
        </p>
        <div class="grid sm:grid-cols-3 gap-2" role="radiogroup" aria-label="Ramassage automatique">
          <label v-for="m in AUTO_MODES" :key="m.value" :class="['rounded-lg border p-3 text-sm cursor-pointer', form.auto_pickup === m.value ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200']">
            <input v-model="form.auto_pickup" type="radio" :value="m.value" class="mr-1">
            <strong>{{ m.label }}</strong>
            <span class="block text-xs text-gray-500 mt-1">{{ m.help }}</span>
          </label>
        </div>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Livraison du jour même automatique</h2>
        <p class="text-sm text-gray-600">
          Un colis récupéré ou au dépôt, à livrer aujourd'hui avant l'heure limite, part seul au livreur qui convient :
          celui qui a déjà le colis, puis le planning des livraisons, puis la zone principale avant la zone de secours,
          celui qui livre déjà dans le secteur, le moins chargé. Zones, capacité et fin de service se règlent sur la fiche de chaque livreur.
        </p>
        <div class="grid sm:grid-cols-3 gap-2" role="radiogroup" aria-label="Livraison automatique">
          <label v-for="m in DELIVERY_MODES" :key="m.value" :class="['rounded-lg border p-3 text-sm cursor-pointer', form.auto_delivery === m.value ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200']">
            <input v-model="form.auto_delivery" type="radio" :value="m.value" class="mr-1">
            <strong>{{ m.label }}</strong>
            <span class="block text-xs text-gray-500 mt-1">{{ m.help }}</span>
          </label>
        </div>
        <div v-if="form.auto_delivery !== 'off'" class="space-y-3">
          <div>
            <label class="label" for="max-cod">Montant à encaisser au plus</label>
            <input id="max-cod" v-model.number="form.auto_delivery_max_cod" type="number" min="0" step="500" class="input w-40" placeholder="Sans plafond">
            <p class="text-xs text-gray-500 mt-1">Au-dessus, l'équipe choisit elle-même le livreur. Vide = sans plafond.</p>
          </div>
          <label class="flex items-start gap-2 text-sm">
            <input v-model="form.same_courier_delivery" type="checkbox" class="mt-1">
            <span>Le livreur qui a fait le ramassage livre aussi, s'il dessert la zone et a de la place.</span>
          </label>
          <p class="text-xs text-gray-500">
            Pas de livraison automatique pour une expédition, un téléphone à vérifier, un retour demandé ou des tentatives épuisées.
          </p>
        </div>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Acceptation des missions</h2>
        <p class="text-sm text-gray-600">
          Faut-il que le livreur accepte chaque mission ? Les missions du planning sont toujours acceptées d'office.
          Une règle différente peut être donnée à un livreur depuis sa fiche.
        </p>
        <div class="grid sm:grid-cols-3 gap-2" role="radiogroup" aria-label="Acceptation des missions">
          <label v-for="m in ACCEPT_MODES" :key="m.value" :class="['rounded-lg border p-3 text-sm cursor-pointer', form.mission_acceptance === m.value ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200']">
            <input v-model="form.mission_acceptance" type="radio" :value="m.value" class="mr-1">
            <strong>{{ m.label }}</strong>
            <span class="block text-xs text-gray-500 mt-1">{{ m.help }}</span>
          </label>
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label class="label" for="unreachable">Livreur injoignable après (minutes)</label>
            <input id="unreachable" v-model.number="form.unreachable_minutes" type="number" min="0" max="240" class="input w-32">
            <p class="text-xs text-gray-500 mt-1">Sans aucun signe de son application, ses ramassages du planning pas encore commencés passent à son remplaçant, et l'équipe est prévenue. 0 = jamais.</p>
          </div>
          <div>
            <label class="label" for="late-pickup">Ramassage pas commencé : alerte (minutes avant l'heure limite)</label>
            <input id="late-pickup" v-model.number="form.late_pickup_alert_minutes" type="number" min="0" max="600" class="input w-32">
            <p class="text-xs text-gray-500 mt-1">
              L'équipe est prévenue des ramassages du jour pas encore commencés ; rien n'est retiré au livreur. 0 = jamais.
              <span v-if="!form.daily_cutoff_time" class="text-amber-700">Indiquez d'abord l'heure limite du jour.</span>
            </p>
          </div>
        </div>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.courier_available_alert" type="checkbox" class="mt-1">
          <span>
            Prévenir l'équipe quand un livreur est libre avec des ramassages près de lui
            <span class="block text-xs text-gray-500">Une alerte par livreur et par jour, pour lui confier une mission ramassage.</span>
          </span>
        </label>
      </section>

      <!-- Délai interne : pour l'équipe, jamais montré au client -->
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Délai interne de distribution</h2>
        <p class="text-sm text-gray-600">
          Délai que l'entreprise se donne pour distribuer une course, à partir de sa création. À 80 % du délai, l'équipe reçoit
          « risque de retard » ; au-delà, « en retard » (dispatch et responsables de l'activité). Les courses concernées sont
          dans la file « ⏰ Délai interne dépassé ». Un contrat peut avoir son propre délai. Vide : pas de délai interne.
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div v-for="a in INTERNAL_ACTIVITIES" :key="a.value">
            <label class="label" :for="`delay-${a.value}`">{{ a.label }} (heures)</label>
            <input :id="`delay-${a.value}`" v-model.number="form.internal_delays[a.value]" type="number" min="1" max="2000" class="input" placeholder="Aucun">
          </div>
        </div>
      </section>

      <!-- Étiquettes : qui imprime, ce qui est imprimé, format proposé ; chaque client peut faire autrement -->
      <section v-if="form.label_settings" class="card p-4 space-y-3">
        <h2 class="font-semibold">Étiquettes</h2>
        <div>
          <label class="label" for="label-client">Les clients impriment leurs étiquettes</label>
          <select id="label-client" v-model="form.label_settings.client_print" class="input">
            <option value="yes">Oui, s'ils le souhaitent</option>
            <option value="required">Oui, obligatoire avant le ramassage (rappel au client, signalé au livreur)</option>
            <option value="no">Non, l'agence les imprime</option>
          </select>
        </div>
        <div>
          <label class="label" for="label-format">Format proposé</label>
          <select id="label-format" v-model="form.label_settings.default_format" class="input">
            <option value="thermal">Rouleau 10 × 15 cm (imprimante thermique)</option>
            <option value="a6">A6</option>
            <option value="a4">A4 · 4 par page</option>
          </select>
          <p class="text-xs text-gray-500 mt-1">Chacun peut en choisir un autre au moment d'imprimer : l'appareil le garde.</p>
        </div>
        <fieldset class="grid sm:grid-cols-2 gap-2 text-sm">
          <legend class="label">Imprimé sur l'étiquette</legend>
          <label v-for="(f, key) in LABEL_FIELDS" :key="key" class="flex items-center gap-2"><input v-model="form.label_settings.fields[key]" type="checkbox"> {{ f }}</label>
        </fieldset>
        <p class="text-xs text-gray-500">Un client peut avoir son propre choix (Clients › Modifier › Étiquettes).</p>
      </section>

      <!-- Photos de documents : scanner de l'application livreur (recadrage, redressement, nettoyage, contrôle) -->
      <section v-if="form.photo_settings" class="card p-4 space-y-3">
        <h2 class="font-semibold">Photos de documents</h2>
        <p class="text-sm text-gray-600">
          Le livreur photographie le document : l'application trouve la feuille, la redresse, efface les ombres, rend le fond
          blanc et l'écriture nette, puis vérifie que la photo est lisible avant l'envoi.
        </p>
        <fieldset class="grid sm:grid-cols-2 gap-2 text-sm">
          <legend class="label">Scanner utilisé pour</legend>
          <label v-for="(label, key) in SCAN_PLACES" :key="key" class="flex items-center gap-2"><input v-model="form.photo_settings.scan[key]" type="checkbox"> {{ label }}</label>
        </fieldset>
        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label class="label" for="photo-mode">Rendu proposé</label>
            <select id="photo-mode" v-model="form.photo_settings.mode" class="input">
              <option value="color">Couleur (garde le tampon bleu ou rouge)</option>
              <option value="gray">Niveaux de gris</option>
              <option value="bw">Noir et blanc</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">Le livreur peut en choisir un autre pour chaque photo.</p>
          </div>
          <div>
            <label class="label" for="photo-check">Contrôle de qualité</label>
            <select id="photo-check" v-model="form.photo_settings.quality_check" class="input">
              <option value="block">Bloquant : une photo floue ou trop sombre est à reprendre</option>
              <option value="warn">Avertissement seulement</option>
              <option value="off">Aucun contrôle</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">Bloquant : après trois essais, le livreur peut l'envoyer quand même ; c'est signalé sur la course.</p>
          </div>
          <div>
            <label class="label" for="photo-resolution">Résolution</label>
            <select id="photo-resolution" v-model="form.photo_settings.resolution" class="input">
              <option value="standard">Standard : 2 000 pixels (scan A4 à 200 points par pouce, 300 à 500 Ko)</option>
              <option value="high">Haute : 3 000 pixels (envoi plus long)</option>
            </select>
          </div>
        </div>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.photo_settings.keep_original" type="checkbox" class="mt-1">
          <span>Garder aussi la photo d'origine<span class="block text-xs text-gray-500">Valeur de preuve : l'équipe peut la voir (« Voir l'original ») ; le client et Jibiat Marchand reçoivent la version nette.</span></span>
        </label>
      </section>

      <!-- Distribution et MAD : au choix de l'administrateur ; chaque contrat peut faire autrement -->
      <section v-if="form.activity_options" class="card p-4 space-y-4">
        <div>
          <h2 class="font-semibold">Distribution et MAD</h2>
          <p class="text-sm text-gray-600">
            Ce que le livreur doit relever à la remise, les messages aux destinataires et la réservation des livreurs en
            mise à disposition. Un contrat peut changer chacun de ces choix (Contrats › Modifier).
          </p>
        </div>
        <div v-for="a in OPTION_ACTIVITIES" :key="a.value" class="space-y-2">
          <h3 class="text-sm font-semibold">{{ a.label }}</h3>
          <ActivityOptionsFields v-model="form.activity_options[a.value]" :activity="a.value" />
        </div>
      </section>

      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import MoreHelp from '../../../components/settings/MoreHelp.vue'
import ActivityOptionsFields from '../../../components/settings/ActivityOptionsFields.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'
import { ACCEPT_MODES, AUTO_MODES, CUSTODY_MODES, DELIVERY_MODES, VALIDATION_MODES, WEEKDAYS } from './options'

const { form, dirty, saving, error, save, reset } = useCompanySettings([
  'validation_mode', 'daily_cutoff_time', 'default_max_attempts', 'require_delivery_code', 'offline_courier_actions', 'pickup_mission_custody', 'depot_closes_at', 'auto_pickup',
  'mission_acceptance', 'unreachable_minutes', 'late_pickup_alert_minutes', 'courier_available_alert',
  'auto_delivery', 'auto_delivery_max_cod', 'same_courier_delivery', 'cutoff_by_day', 'internal_delays',
  'activity_options', 'label_settings', 'photo_settings',
])
const SCAN_PLACES = {
  delivery_proof: 'Accusé de réception, décharge',
  receipts: 'Tickets d\'expédition, reçus de frais',
  returns: 'Remise des retours (bon signé)',
  vehicle: 'Reçus de carburant',
}
const LABEL_FIELDS = {
  cod: 'Montant à encaisser (e-commerce)',
  recipient_phone: 'Téléphone du destinataire',
  landmark: 'Repère de l\'adresse',
  description: 'Description du colis',
  logo: 'Logo de l\'entreprise',
}
const OPTION_ACTIVITIES = [
  { value: 'distribution', label: 'Distribution' },
  { value: 'mad', label: 'Mise à disposition (MAD)' },
]
const INTERNAL_ACTIVITIES = [
  { value: 'distribution', label: 'Distribution' },
  { value: 'mad', label: 'MAD' },
  { value: 'ecommerce', label: 'E-commerce' },
]
</script>
