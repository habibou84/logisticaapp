<template>
  <SettingsPage icon="🏢" title="Départements des clients" intro="Pour les clients de distribution et de MAD qui envoient ou reçoivent pour plusieurs départements (une banque : Finance, Marketing, Gestion…). Chaque client peut changer ces réglages sur sa fiche.">
    <template v-if="form?.department_settings">
      <section class="card p-4">
        <label class="flex items-start gap-2 text-sm">
          <input v-model="s.enabled" type="checkbox" class="mt-1">
          <span>Proposer les départements<span class="block text-xs text-gray-500">Clients › Départements : nom, responsable, emplacement, autre site, centre de coûts, quota du mois, comptes rattachés.</span></span>
        </label>
      </section>
      <div v-if="s.enabled" class="grid gap-4 lg:grid-cols-2">
        <section v-for="g in groups" :key="g" class="card p-4 space-y-3">
          <h2 class="font-semibold">{{ g }}</h2>
          <template v-for="o in DEPARTMENT_OPTIONS.filter((x) => x.group === g)" :key="o.key">
            <label v-if="o.type === 'bool'" class="flex items-start gap-2 text-sm">
              <input v-model="s[o.key]" type="checkbox" class="mt-1">
              <span>{{ o.label }}<span v-if="o.hint" class="block text-xs text-gray-500">{{ o.hint }}</span></span>
            </label>
            <div v-else>
              <label class="block text-sm" :for="`ds-${o.key}`">{{ o.label }}</label>
              <select :id="`ds-${o.key}`" v-model="s[o.key]" class="input mt-1">
                <option v-for="(label, value) in optionChoices(o)" :key="value" :value="value">{{ label }}</option>
              </select>
              <p v-if="o.hint" class="mt-0.5 text-xs text-gray-500">{{ o.hint }}</p>
            </div>
          </template>
        </section>
      </div>
      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import { computed } from 'vue'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'
import { DEPARTMENT_OPTIONS, optionChoices } from '../../../utils/departments'

const { form, dirty, saving, error, save, reset } = useCompanySettings(['department_settings'])
const s = computed(() => form.value.department_settings)
const groups = [...new Set(DEPARTMENT_OPTIONS.map((o) => o.group))]
</script>
