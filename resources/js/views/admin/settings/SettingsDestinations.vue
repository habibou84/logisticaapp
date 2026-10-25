<template>
  <SettingsPage icon="📇" title="Annuaire des destinations" intro="Destinations partagées par tous les clients : suggérées pendant la saisie d'une course, enrichies par chaque course, positions confirmées sur place par les livreurs.">
    <template v-if="form?.destination_settings">
      <div class="grid gap-4 lg:grid-cols-2">
        <section class="card p-4 space-y-3">
          <h2 class="font-semibold">Suggestions</h2>
          <Opt v-model="s.enabled" label="Utiliser l'annuaire" />
          <div>
            <p class="label">Pour les courses</p>
            <div class="flex flex-wrap gap-x-4">
              <label v-for="a in ACTIVITIES" :key="a.value" class="flex items-center gap-1.5 text-sm"><input v-model="s.activities" type="checkbox" :value="a.value"> {{ a.label }}</label>
            </div>
          </div>
          <Opt v-model="s.clients_see" label="Suggestions aussi dans l'application des clients" hint="Sinon seulement au back-office." />
          <div>
            <p class="label">Destination nouvelle saisie sur une course</p>
            <select v-model="s.auto_add" class="input">
              <option value="on">Ajoutée à l'annuaire</option>
              <option value="pending">Ajoutée, à valider par l'équipe</option>
              <option value="off">Pas ajoutée</option>
            </select>
          </div>
          <Opt v-model="s.auto_update" label="Corriger l'annuaire avec les courses" hint="Un bon numéro saisi remplace les numéros « à vérifier » ; une adresse ou une commune qui manquait est ajoutée." />
        </section>
        <section class="card p-4 space-y-3">
          <h2 class="font-semibold">Position GPS par le livreur</h2>
          <div>
            <p class="label">Enregistrer la position de l'adresse</p>
            <select v-model="s.courier_locate" class="input">
              <option value="free">Quand le livreur veut (bouton sur la mission)</option>
              <option value="prompt">Proposé à chaque livraison</option>
              <option value="required">Obligatoire si l'adresse n'a pas encore de position</option>
            </select>
          </div>
          <p class="text-sm">Précision demandée : ± <input v-model.number="s.min_accuracy" type="number" min="5" max="500" class="input px-1 text-center" style="display:inline-block;width:5rem" aria-label="Précision (m)"> m</p>
          <p class="text-sm">Écart à vérifier par l'équipe au-delà de <input v-model.number="s.conflict_distance" type="number" min="20" max="5000" class="input px-1 text-center" style="display:inline-block;width:5.5rem" aria-label="Écart (m)"> m</p>
          <Opt v-model="s.courier_overwrite" label="Une nouvelle position éloignée remplace l'ancienne sans attendre l'équipe" />
          <Opt v-model="s.use_location" label="Donner la position confirmée au livreur sur les courses suivantes" hint="Le bouton Itinéraire mène directement à la porte." />
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
import { useCompanySettings } from '../../../composables/useCompanySettings'

const Opt = defineComponent({
  props: { modelValue: Boolean, label: String, hint: String },
  emits: ['update:modelValue'],
  setup: (props, { emit }) => () => h('label', { class: 'flex items-start gap-2 text-sm' }, [
    h('input', { type: 'checkbox', class: 'mt-1', checked: props.modelValue, onChange: (e) => emit('update:modelValue', e.target.checked) }),
    h('span', [props.label, props.hint ? h('span', { class: 'block text-xs text-gray-500' }, props.hint) : null]),
  ]),
})
const ACTIVITIES = [{ value: 'distribution', label: 'Distribution' }, { value: 'mad', label: 'MAD' }, { value: 'ecommerce', label: 'E-commerce' }]
const { form, dirty, saving, error, save, reset } = useCompanySettings(['destination_settings'])
const s = computed(() => form.value.destination_settings)
</script>
