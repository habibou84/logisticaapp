<template>
  <SettingsPage icon="✋" title="Pointage des livreurs" intro="Chaque livreur pointe son arrivée sur son lieu de début, et seulement sur place. Le pointage le met en service. En MAD : chez le client, ou à la première étape de son parcours (fiche du contrat). Distribution et e-commerce : à l'agence.">
    <template v-if="form?.shift_settings">
      <div class="grid gap-4 lg:grid-cols-2">
        <section class="card p-4 space-y-3">
          <h2 class="font-semibold">Agence</h2>
          <Opt v-model="s.enabled" label="Pointage des livreurs activé" />
          <label class="block">
            <span class="label">Nom</span>
            <input v-model="s.agency_name" type="text" maxlength="120" class="input" placeholder="Agence">
          </label>
          <label class="block">
            <span class="label">Adresse</span>
            <input v-model="s.agency_address" type="text" maxlength="255" class="input" placeholder="Ex. : Cocody Riviera 2, rue des Jardins">
          </label>
          <div>
            <p class="label">Position sur la carte</p>
            <LocationPicker v-model="point" allow-link height="h-56" gps-label="📍 Je suis à l'agence : utiliser ma position" />
            <p v-if="!point" class="mt-1 text-xs text-amber-700">Sans position, les livreurs ne peuvent pas pointer à l'agence.</p>
          </div>
          <div class="flex flex-wrap items-end gap-4">
            <label class="block">
              <span class="label">Heure de début</span>
              <input v-model="s.agency_start" type="time" class="input w-auto">
            </label>
            <div>
              <p class="label">Jours de travail</p>
              <div class="flex flex-wrap gap-x-3">
                <label v-for="d in DAYS" :key="d.value" class="flex items-center gap-1 text-sm"><input v-model="s.agency_days" type="checkbox" :value="d.value"> {{ d.short }}</label>
              </div>
            </div>
          </div>
        </section>
        <section class="card p-4 space-y-3">
          <h2 class="font-semibold">Règles</h2>
          <p class="text-sm">Distance autorisée autour du lieu : <Num v-model="s.radius" :min="10" :max="2000" label="Distance (m)" /> m</p>
          <p class="text-sm">Précision GPS demandée : ± <Num v-model="s.min_accuracy" :min="5" :max="500" label="Précision (m)" /> m</p>
          <p class="text-xs text-gray-500">
            Le téléphone se trompe souvent de quelques dizaines de mètres : le livreur est accepté si sa distance au lieu,
            moins l'imprécision de son GPS, reste dans la distance autorisée. Une position moins précise est refusée
            (« sortez à découvert et attendez quelques secondes »).
          </p>
          <p class="text-sm">Pointage possible <Num v-model="s.early_minutes" :min="0" :max="600" label="Minutes avant" /> min avant l'heure prévue</p>
          <p class="text-sm">Retard noté au-delà de <Num v-model="s.late_tolerance" :min="0" :max="240" label="Tolérance (min)" /> min</p>
          <p class="text-sm">Dispatch prévenu si pas de pointage <Num v-model="s.alert_minutes" :min="0" :max="600" label="Alerte (min)" /> min après l'heure <span class="text-gray-500">(0 : jamais)</span></p>
          <p class="text-xs text-gray-500">Un responsable peut toujours pointer un livreur à la main, avec un motif (Activité des livreurs › Pointages).</p>
        </section>
      </div>
      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import { computed, defineComponent, h } from 'vue'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import LocationPicker from '../../../components/LocationPicker.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'
import { DAYS } from '../../../utils/shifts'

const Opt = defineComponent({
  props: { modelValue: Boolean, label: String },
  emits: ['update:modelValue'],
  setup: (props, { emit }) => () => h('label', { class: 'flex items-start gap-2 text-sm' }, [
    h('input', { type: 'checkbox', class: 'mt-1', checked: props.modelValue, onChange: (e) => emit('update:modelValue', e.target.checked) }),
    h('span', props.label),
  ]),
})
const Num = defineComponent({
  props: { modelValue: Number, min: Number, max: Number, label: String },
  emits: ['update:modelValue'],
  setup: (props, { emit }) => () => h('input', {
    type: 'number', min: props.min, max: props.max, value: props.modelValue, 'aria-label': props.label,
    class: 'input px-1 text-center', style: 'display:inline-block;width:5rem',
    onInput: (e) => emit('update:modelValue', e.target.value === '' ? null : Number(e.target.value)),
  }),
})

const { form, dirty, saving, error, save, reset } = useCompanySettings(['shift_settings'])
const s = computed(() => form.value.shift_settings)
const point = computed({
  get: () => (s.value?.agency_lat != null && s.value?.agency_lng != null ? { lat: s.value.agency_lat, lng: s.value.agency_lng } : null),
  set: (p) => {
    s.value.agency_lat = p?.lat ?? null
    s.value.agency_lng = p?.lng ?? null
  },
})
</script>
