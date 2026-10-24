<template>
  <SettingsPage icon="🏍️" title="Véhicules" intro="Ce que l'application suit pour le parc, et ce qu'elle fait toute seule.">
    <template v-if="form?.vehicle_settings">
      <div class="grid gap-4 lg:grid-cols-2">
        <section class="card p-4 space-y-3">
          <h2 class="font-semibold">Prise de service</h2>
          <div>
            <p class="label">État des lieux au départ</p>
            <div class="flex flex-wrap gap-2">
              <button v-for="m in CHECK_MODES" :key="m.value" type="button" :class="['rounded-md px-3 py-1.5 text-sm', s.check_mode === m.value ? 'bg-slate-900 text-white' : 'border border-slate-300']" @click="s.check_mode = m.value">{{ m.label }}</button>
            </div>
            <p class="mt-1 text-xs text-gray-500">Compteur, essence, contrôle rapide ; un problème prévient l'équipe sans empêcher de partir.</p>
          </div>
          <template v-if="s.check_mode !== 'never'">
            <Opt v-model="s.check_before_service" label="Obligatoire avant de se mettre en service" hint="Le livreur ne peut pas passer « en service » sans l'avoir fait." />
            <Opt v-model="s.check_end" label="Aussi au retour" hint="Compteur et kilomètres GPS de la journée ; un véhicule pris pour la journée est rendu." />
            <Opt v-model="s.check_odometer_photo" label="Photo du compteur demandée" />
            <div>
              <p class="label">Points du contrôle rapide</p>
              <div class="grid grid-cols-2 gap-1">
                <label v-for="i in catalog.check_items" :key="i.value" class="flex items-center gap-2 text-sm"><input v-model="s.check_items" type="checkbox" :value="i.value"> {{ i.icon }} {{ i.label }}</label>
              </div>
            </div>
          </template>
          <Opt v-model="s.no_vehicle_no_missions" label="Sans véhicule en état, pas de missions" hint="Véhicule en panne, à l'entretien ou aux papiers dépassés (sauf livreurs à pied)." />
          <Opt v-model="s.capacity_assignment" label="Capacité du véhicule dans la livraison automatique" hint="Nombre de colis du véhicule, en plus de la limite du livreur." />
        </section>

        <section class="card p-4 space-y-3">
          <h2 class="font-semibold">Papiers et entretien</h2>
          <p class="text-sm">Rappels avant la date :
            <input v-model.number="reminders[0]" type="number" min="1" max="180" class="input px-1 text-center" style="display:inline-block;width:4.5rem" aria-label="Premier rappel (jours)"> et
            <input v-model.number="reminders[1]" type="number" min="1" max="180" class="input px-1 text-center" style="display:inline-block;width:4.5rem" aria-label="Second rappel (jours)"> jours
          </p>
          <Opt v-model="s.block_expired" label="Bloquer un véhicule dont un papier obligatoire est dépassé" hint="Il ne peut plus être confié, et son livreur ne reçoit plus de missions si l'option est cochée." />
          <div v-if="s.block_expired" class="flex flex-wrap gap-x-4 gap-y-1 pl-6">
            <label v-for="d in catalog.documents.filter((x) => x.value !== 'license')" :key="d.value" class="flex items-center gap-1.5 text-sm"><input v-model="s.blocking_documents" type="checkbox" :value="d.value"> {{ d.label }}</label>
          </div>
          <Opt v-model="s.track_license" label="Permis des livreurs suivi aussi" hint="Rappel au livreur et aux responsables." />
          <p class="text-sm">Rappel d'entretien à moins de
            <input v-model.number="s.maintenance_reminder_km" type="number" min="0" class="input px-1 text-center" style="display:inline-block;width:5.5rem" aria-label="Kilomètres avant l'entretien"> km ou
            <input v-model.number="s.maintenance_reminder_days" type="number" min="0" class="input px-1 text-center" style="display:inline-block;width:4.5rem" aria-label="Jours avant l'entretien"> jours de l'échéance
          </p>
          <details class="rounded border border-slate-200 p-2">
            <summary class="cursor-pointer text-sm font-medium">Plans d'entretien proposés pour les nouveaux véhicules</summary>
            <div v-for="type in ['moto', 'tricycle', 'voiture', 'velo']" :key="type" class="mt-3 space-y-1">
              <p class="text-sm font-semibold">{{ VEHICLE_TYPES[type].icon }} {{ VEHICLE_TYPES[type].label }}</p>
              <div v-for="(p, i) in s.default_plans[type]" :key="i" class="grid grid-cols-[3rem_1fr_5.5rem_4.5rem_1.5rem] items-center gap-1">
                <input v-model="p.icon" class="input px-1 text-center" :aria-label="`Icône ${p.label}`">
                <input v-model="p.label" class="input" :aria-label="`Entretien ${i + 1} (${type})`">
                <input v-model.number="p.every_km" type="number" min="1" class="input" placeholder="km" :aria-label="`km (${p.label})`">
                <input v-model.number="p.every_months" type="number" min="1" class="input" placeholder="mois" :aria-label="`mois (${p.label})`">
                <button type="button" class="text-red-600" :aria-label="`Retirer ${p.label}`" @click="s.default_plans[type].splice(i, 1)">✕</button>
              </div>
              <button type="button" class="text-xs text-blue-600" @click="s.default_plans[type].push({ icon: '🔧', label: '', every_km: null, every_months: null })">+ Ligne</button>
            </div>
          </details>
        </section>

        <section class="card p-4 space-y-3">
          <h2 class="font-semibold">Carburant</h2>
          <div class="flex flex-wrap gap-2">
            <button v-for="m in FUEL_MODES" :key="m.value" type="button" :class="['rounded-md px-3 py-1.5 text-sm text-left', s.fuel_mode === m.value ? 'bg-slate-900 text-white' : 'border border-slate-300']" @click="s.fuel_mode = m.value">{{ m.label }}</button>
          </div>
          <p class="text-xs text-gray-500">{{ FUEL_MODES.find((m) => m.value === s.fuel_mode)?.hint }}</p>
          <template v-if="s.fuel_mode === 'allowance'">
            <p class="text-sm">Dotation : <input v-model.number="s.fuel_allowance" type="number" min="0" step="100" class="input px-1 text-center" style="display:inline-block;width:6.5rem" aria-label="Dotation par jour"> F par jour travaillé, ajoutée à la paie</p>
            <Opt v-model="s.fuel_allowance_excess_refund" label="Rembourser ce qui dépasse la dotation du jour" hint="Sur les pleins déclarés avec un reçu." />
          </template>
          <Opt v-model="s.fuel_receipt_required" label="Photo du reçu obligatoire" />
          <p class="text-sm">Alerte de consommation au-delà de <input v-model.number="s.fuel_anomaly_percent" type="number" min="5" class="input px-1 text-center" style="display:inline-block;width:4.5rem" aria-label="Seuil de consommation (%)"> % de la moyenne du véhicule</p>
          <p class="text-sm">Écart compteur / GPS signalé au-delà de <input v-model.number="s.gps_gap_percent" type="number" min="5" class="input px-1 text-center" style="display:inline-block;width:4.5rem" aria-label="Seuil d'écart GPS (%)"> %</p>
        </section>

        <section class="card p-4 space-y-3">
          <h2 class="font-semibold">Argent et paie</h2>
          <Opt v-model="s.leases" label="Location-vente et location des véhicules" hint="Versements par jour, semaine ou mois, retenus sur la paie ou payés en caisse." />
          <p v-if="s.leases" class="text-sm pl-6">Retard : rappel au livreur après <input v-model.number="s.lease_late_courier_days" type="number" min="0" class="input px-1 text-center" style="display:inline-block;width:4.0rem" aria-label="Jours avant le rappel au livreur"> jours, au responsable après <input v-model.number="s.lease_late_manager_days" type="number" min="0" class="input px-1 text-center" style="display:inline-block;width:4.0rem" aria-label="Jours avant l'alerte au responsable"> jours</p>
          <p class="text-sm">Indemnité véhicule personnel : <input v-model.number="s.personal_allowance" type="number" min="0" step="100" class="input px-1 text-center" style="display:inline-block;width:6.5rem" aria-label="Indemnité par jour"> F par jour travaillé <span class="text-xs text-gray-500">(0 : aucune)</span></p>
          <Opt v-model="s.fines_charge_courier" label="Amendes retenues au livreur qui avait le véhicule" hint="Retrouvé par l'historique des remises ; décochable amende par amende." />
          <Opt v-model="s.damage_charge_courier" label="Part des dégâts d'un sinistre retenue au livreur responsable" />
          <p v-if="s.damage_charge_courier" class="text-sm pl-6">Plafond : <input v-model.number="s.damage_cap_month" type="number" min="0" step="1000" class="input px-1 text-center" style="display:inline-block;width:7.5rem" aria-label="Plafond par mois"> F par mois</p>
          <Opt v-model="s.link_field_events" label="Panne ou accident signalé : véhicule « en panne » et ligne au carnet" hint="Remis en service quand le livreur reprend (événements terrain)." />
          <Opt v-model="s.weekly_report" label="Dans le point de la semaine" hint="Véhicules immobilisés, échéances, location-vente en retard." />
        </section>
      </div>
      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, ref, watch } from 'vue'
import http from '../../../bootstrap/axios'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'
import { VEHICLE_TYPES } from '../../../utils/vehicles'

// Case à cocher avec sa précision
const Opt = defineComponent({
  props: { modelValue: Boolean, label: String, hint: String },
  emits: ['update:modelValue'],
  setup: (props, { emit }) => () => h('label', { class: 'flex items-start gap-2 text-sm' }, [
    h('input', { type: 'checkbox', class: 'mt-1', checked: props.modelValue, onChange: (e) => emit('update:modelValue', e.target.checked) }),
    h('span', [props.label, props.hint ? h('span', { class: 'block text-xs text-gray-500' }, props.hint) : null]),
  ]),
})

const CHECK_MODES = [{ value: 'daily', label: 'Chaque jour' }, { value: 'weekly', label: 'Chaque lundi' }, { value: 'never', label: 'Jamais' }]
const FUEL_MODES = [
  { value: 'allowance', label: 'Dotation', hint: 'Un montant par jour travaillé, ajouté à la paie ; les pleins sont enregistrés pour suivre la consommation.' },
  { value: 'refund', label: 'Remboursement sur reçu', hint: 'Chaque plein déclaré par le livreur lui est remboursé sur sa paie.' },
  { value: 'courier', label: 'À la charge du livreur', hint: 'Rien sur la paie ; les pleins servent seulement à suivre la consommation.' },
]

const { form, dirty, saving, error, save, reset } = useCompanySettings(['vehicle_settings'])
const s = computed(() => form.value.vehicle_settings)
const catalog = ref({ check_items: [], documents: [] })
const reminders = ref([30, 7])
// Deux rappels : copie locale, renvoyée seulement si elle change (pas de va-et-vient entre les deux)
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b)
watch(() => s.value?.reminder_days, (v) => {
  const next = v ? [v[0] ?? 30, v[1] ?? 7] : [30, 7]
  if (!same(next, reminders.value)) reminders.value = next
}, { immediate: true })
// Un plan (même vide) pour chaque type de véhicule
watch(s, (v) => { if (v) ['moto', 'tricycle', 'voiture', 'velo'].forEach((t) => { v.default_plans[t] ||= [] }) }, { immediate: true })
watch(reminders, (v) => {
  const next = v.filter(Boolean)
  if (s.value && !same(next, s.value.reminder_days)) s.value.reminder_days = next
}, { deep: true })

onMounted(async () => {
  catalog.value = (await http.get('/vehicles/catalog')).data.data
})
</script>
