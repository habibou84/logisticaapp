<template>
  <SettingsPage icon="🧾" title="Facturation et TVA" intro="Identité fiscale imprimée sur les factures et les avoirs, et TVA des factures du mois des clients (distribution, MAD).">
    <section v-if="identity.form.value" class="card p-4 space-y-3">
      <h2 class="font-semibold">Identité fiscale de l'entreprise</h2>
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2"><label class="label" for="legal-name">Raison sociale</label><input id="legal-name" v-model="identity.form.value.legal_name" class="input" maxlength="255" :placeholder="identity.company.value?.name"></div>
        <div><label class="label" for="ncc">NCC (numéro de compte contribuable)</label><input id="ncc" v-model="identity.form.value.ncc" class="input" maxlength="30"></div>
        <div><label class="label" for="rccm">RCCM</label><input id="rccm" v-model="identity.form.value.rccm" class="input" maxlength="60"></div>
        <div><label class="label" for="regime">Régime d'imposition</label><input id="regime" v-model="identity.form.value.tax_regime" class="input" maxlength="60" placeholder="Ex. : Réel normal (RNI)"></div>
        <div><label class="label" for="office">Centre des impôts</label><input id="office" v-model="identity.form.value.tax_office" class="input" maxlength="120"></div>
      </div>
      <SaveBar :dirty="identity.dirty.value" :saving="identity.saving.value" :error="identity.error.value" @save="identity.save" @reset="identity.reset" />
    </section>

    <section v-if="vat.form.value" class="card p-4 space-y-3">
      <h2 class="font-semibold">TVA</h2>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="vat.form.value.vat_settings.enabled" type="checkbox" class="mt-1">
        <span>Facturer la TVA<span class="block text-gray-500">Sur les factures calculées ou émises à partir de maintenant ; les factures déjà émises ne changent pas.</span></span>
      </label>
      <template v-if="vat.form.value.vat_settings.enabled">
        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label class="label" for="vat-rate">Taux (%)</label>
            <input id="vat-rate" v-model.number="vat.form.value.vat_settings.rate" type="number" min="0" max="50" step="0.01" class="input w-28">
          </div>
          <fieldset>
            <legend class="label">Vos prix (formules, tarifs, suppléments, lignes à la main) sont</legend>
            <label class="flex items-center gap-2 text-sm"><input v-model="vat.form.value.vat_settings.prices" type="radio" value="ht"> hors taxe : la TVA s'ajoute</label>
            <label class="flex items-center gap-2 text-sm"><input v-model="vat.form.value.vat_settings.prices" type="radio" value="ttc"> toutes taxes comprises : la TVA est dedans</label>
          </fieldset>
        </div>
        <p class="text-sm rounded bg-slate-50 p-2">Exemple, forfait de 50 000 F : {{ example }}</p>
        <div>
          <label class="label" for="vat-mention">Mention sur la facture d'un client exonéré</label>
          <input id="vat-mention" v-model="vat.form.value.vat_settings.exemption_mention" class="input" maxlength="160">
          <p class="text-xs text-gray-500 mt-1">Suivie de la référence de son attestation. L'exonération se règle sur la fiche du client (Clients › Modifier › Facturation).</p>
        </div>
      </template>
      <SaveBar :dirty="vat.dirty.value" :saving="vat.saving.value" :error="vat.error.value" @save="vat.save" @reset="vat.reset" />
    </section>
  </SettingsPage>
</template>

<script setup>
import { computed } from 'vue'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'
import { money } from '../../../utils/format'

// Identité : réglages généraux ; TVA : droit des factures
const identity = useCompanySettings(['legal_name', 'ncc', 'rccm', 'tax_regime', 'tax_office'])
const vat = useCompanySettings(['vat_settings'])

const example = computed(() => {
  const s = vat.form.value?.vat_settings
  const rate = Number(s?.rate) || 0
  if (s?.prices === 'ttc') {
    const ht = Math.round(50000 * 100 / (100 + rate))
    return `${money(ht)} HT + ${money(50000 - ht)} de TVA = ${money(50000)} TTC`
  }
  const tva = Math.round(50000 * rate / 100)
  return `${money(50000)} HT + ${money(tva)} de TVA = ${money(50000 + tva)} TTC`
})
</script>
