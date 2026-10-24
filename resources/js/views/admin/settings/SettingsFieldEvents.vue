<template>
  <SettingsPage icon="🚨" title="Événements terrain" intro="Ce que les livreurs peuvent signaler sur la route (panne, accident, contrôle…) et ce que l'application fait automatiquement.">
    <template v-if="form?.field_event_settings">
      <section class="card p-4 space-y-3">
        <label class="flex items-start gap-2 text-sm">
          <input v-model="s.enabled" type="checkbox" class="mt-1">
          <span>Les livreurs peuvent signaler un événement<span class="block text-xs text-gray-500">Bouton « Signaler un problème » en haut de leurs missions, même sans réseau.</span></span>
        </label>
      </section>

      <section class="card p-4 space-y-3">
        <div class="flex items-center justify-between gap-2">
          <h2 class="font-semibold">Types proposés au livreur</h2>
          <button type="button" class="btn-secondary text-sm" @click="addType">+ Ajouter un type</button>
        </div>
        <p class="text-sm text-gray-600">
          La gravité règle l'alerte : un événement <strong>urgent</strong> sonne fort et prévient aussi les administrateurs (si coché plus bas).
          « Retards excusés » : les retards des courses touchées ne comptent ni dans la note automatique ni dans les primes.
        </p>
        <div class="divide-y border rounded">
          <div v-for="(t, i) in s.types" :key="i" :class="['p-2 space-y-2', t.active ? '' : 'bg-slate-50 opacity-70']">
            <div class="flex flex-wrap items-center gap-2">
              <input v-model="t.icon" class="input w-14 text-center px-1" maxlength="16" :aria-label="`Icône du type ${i + 1}`">
              <input v-model="t.label" class="input w-40 flex-1 min-w-[8rem]" maxlength="60" placeholder="Libellé" :aria-label="`Libellé du type ${i + 1}`">
              <input v-model="t.hint" class="input w-56 flex-[2] min-w-[10rem]" maxlength="120" placeholder="Précision : ex. moteur, crevaison" :aria-label="`Précision du type ${i + 1}`">
              <select v-model="t.severity" class="input w-32" :aria-label="`Gravité du type ${i + 1}`">
                <option v-for="(label, value) in SEVERITIES" :key="value" :value="value">{{ label }}</option>
              </select>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
              <label class="flex items-center gap-1.5"><input v-model="t.active" type="checkbox"> Proposé au livreur</label>
              <label class="flex items-center gap-1.5"><input v-model="t.photo" type="checkbox"> Photo obligatoire</label>
              <label class="flex items-center gap-1.5"><input v-model="t.gps" type="checkbox"> Position jointe</label>
              <label class="flex items-center gap-1.5"><input v-model="t.excuse" type="checkbox"> Retards excusés</label>
              <span class="ml-auto whitespace-nowrap">
                <button type="button" class="px-1 text-gray-500 disabled:opacity-30" :disabled="i === 0" :aria-label="`Monter ${t.label}`" @click="move(i, -1)">↑</button>
                <button type="button" class="px-1 text-gray-500 disabled:opacity-30" :disabled="i === s.types.length - 1" :aria-label="`Descendre ${t.label}`" @click="move(i, 1)">↓</button>
                <button v-if="!t.key" type="button" class="px-1 text-red-600" :aria-label="`Supprimer ${t.label || 'le type'}`" @click="s.types.splice(i, 1)">✕</button>
              </span>
            </div>
          </div>
        </div>
        <p class="text-xs text-gray-500">Un type enregistré ne se supprime pas (les anciens signalements le gardent) : décochez « Proposé » pour le retirer de l'application du livreur.</p>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Ce que le livreur peut joindre</h2>
        <label class="flex items-start gap-2 text-sm"><input v-model="s.voice_notes" type="checkbox" class="mt-1"><span>Note vocale<span class="block text-xs text-gray-500">Plus simple qu'écrire en roulant ou en cas d'urgence.</span></span></label>
        <div v-if="s.voice_notes"><label class="label" for="voice-max">Durée maximale (secondes)</label><input id="voice-max" v-model.number="s.voice_max_seconds" type="number" min="10" max="180" class="input w-28"></div>
        <label class="flex items-start gap-2 text-sm"><input v-model="s.courier_cost" type="checkbox" class="mt-1"><span>Coût du dépannage (réparation, essence…)<span class="block text-xs text-gray-500">Indicatif, pour suivre ce que coûtent les pannes ; il ne passe pas en caisse.</span></span></label>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Quand le livreur est bloqué ou arrête sa journée</h2>
        <label class="flex items-start gap-2 text-sm"><input v-model="s.pause_courier" type="checkbox" class="mt-1"><span>Le mettre hors service jusqu'à sa reprise<span class="block text-xs text-gray-500">Il ne reçoit plus de missions (ni du planning, ni de la livraison automatique). « C'est réglé, je reprends » le remet en service.</span></span></label>
        <label class="flex items-start gap-2 text-sm"><input v-model="s.release_pickups" type="checkbox" class="mt-1"><span>Lui retirer ses ramassages pas encore faits<span class="block text-xs text-gray-500">Ils vont à son remplaçant du planning s'il y en a un, sinon au dispatch (alertes « sans livreur »). Sinon, bouton « Retirer ses ramassages » sur l'événement.</span></span></label>
        <label class="flex items-start gap-2 text-sm"><input v-model="s.mark_planning" type="checkbox" class="mt-1"><span>Journée arrêtée : la noter au planning<span class="block text-xs text-gray-500">Absence du jour : son remplaçant prend ses règles. Retirée s'il reprend dans la journée.</span></span></label>
        <label class="flex items-start gap-2 text-sm"><input v-model="s.notify_clients" type="checkbox" class="mt-1"><span>Prévenir les clients des courses touchées<span class="block text-xs text-gray-500">Message neutre « votre course aura du retard à la suite d'un imprévu », sans détail. Modèle WhatsApp « course_retardee » à faire approuver. Sinon, bouton « Prévenir les clients » sur l'événement.</span></span></label>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Alertes et suivi</h2>
        <label class="flex items-start gap-2 text-sm"><input v-model="s.urgent_admins" type="checkbox" class="mt-1"><span>Événement urgent : prévenir aussi les administrateurs tout de suite<span class="block text-xs text-gray-500">Sinon le dispatch et les responsables d'activité seulement, puis les administrateurs après les relances.</span></span></label>
        <label class="flex items-start gap-2 text-sm"><input v-model="s.excuse_delays" type="checkbox" class="mt-1"><span>Excuser les retards dus aux événements<span class="block text-xs text-gray-500">Pour les types marqués « Retards excusés » : la note automatique (ramassages à l'heure, délai interne) n'en tient pas compte.</span></span></label>
        <p class="text-sm text-gray-600">
          Relances d'un événement que personne ne prend en charge : même délai que les remontées des courses
          (<RouterLink to="/admin/reglages/alertes" class="text-blue-600">Alertes et rappels</RouterLink>).
        </p>
      </section>

      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import { computed } from 'vue'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'

const SEVERITIES = { info: 'Info', hindrance: 'Gênant', blocking: 'Bloquant', urgent: 'Urgent' }

const { form, dirty, saving, error, save, reset } = useCompanySettings(['field_event_settings'])
const s = computed(() => form.value.field_event_settings)

function addType() {
  s.value.types.push({ key: '', label: '', icon: '⚠️', hint: '', severity: 'info', photo: false, gps: true, excuse: false, active: true })
}

function move(i, step) {
  const list = s.value.types
  ;[list[i], list[i + step]] = [list[i + step], list[i]]
}
</script>
