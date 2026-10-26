<template>
  <SettingsPage icon="🧾" title="Facturation et TVA" intro="Identité fiscale imprimée sur les factures et les avoirs, TVA des factures du mois des clients (distribution, MAD) et facture normalisée électronique (FNE).">
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

    <!-- Facture normalisée électronique : procédure d'interfaçage par API de la DGI -->
    <section v-if="fne.form.value" class="card p-4 space-y-3">
      <div>
        <h2 class="font-semibold">Facture normalisée électronique (FNE)</h2>
        <p class="text-sm text-gray-600">Chaque facture du mois est certifiée par la DGI à l'émission, chaque avoir aussi : la référence FNE et le QR code de vérification sont imprimés dessus.</p>
      </div>
      <label class="flex items-center gap-2 text-sm"><input v-model="fne.form.value.fne_settings.enabled" type="checkbox"> Certifier les factures et les avoirs</label>
      <div class="grid sm:grid-cols-2 gap-3">
        <fieldset>
          <legend class="label">Environnement</legend>
          <label class="flex items-center gap-2 text-sm"><input v-model="fne.form.value.fne_settings.mode" type="radio" value="test"> Test (spécimens à envoyer à la DGI)</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="fne.form.value.fne_settings.mode" type="radio" value="production"> Production</label>
        </fieldset>
        <div v-if="fne.form.value.fne_settings.mode === 'production'">
          <label class="label" for="fne-prod">URL de production (donnée par la DGI)</label>
          <input id="fne-prod" v-model="fne.form.value.fne_settings.production_url" class="input" placeholder="https://…">
        </div>
        <div v-else>
          <label class="label" for="fne-test">URL de test</label>
          <input id="fne-test" v-model="fne.form.value.fne_settings.test_url" class="input">
        </div>
        <div><label class="label" for="fne-pos">Point de vente</label><input id="fne-pos" v-model="fne.form.value.fne_settings.point_of_sale" class="input" maxlength="100"></div>
        <div><label class="label" for="fne-est">Établissement</label><input id="fne-est" v-model="fne.form.value.fne_settings.establishment" class="input" maxlength="150"></div>
        <div>
          <label class="label" for="fne-pay">Mode de paiement déclaré</label>
          <select id="fne-pay" v-model="fne.form.value.fne_settings.payment_method" class="input">
            <option value="deferred">À terme (facture payée après émission)</option>
            <option value="transfer">Virement</option>
            <option value="mobile-money">Mobile Money</option>
            <option value="check">Chèque</option>
            <option value="cash">Espèces</option>
            <option value="card">Carte bancaire</option>
          </select>
        </div>
        <div><label class="label" for="fne-msg">Message commercial (facultatif)</label><input id="fne-msg" v-model="fne.form.value.fne_settings.commercial_message" class="input" maxlength="255"></div>
        <div class="sm:col-span-2"><label class="label" for="fne-footer">Pied de facture (facultatif)</label><input id="fne-footer" v-model="fne.form.value.fne_settings.footer" class="input" maxlength="255"></div>
      </div>
      <SaveBar :dirty="fne.dirty.value" :saving="fne.saving.value" :error="fne.error.value" @save="fne.save" @reset="fne.reset" />

      <div class="border-t pt-3 space-y-2">
        <label class="label" for="fne-key">Clé API (espace FNE › Paramétrage)</label>
        <div class="flex gap-2">
          <input id="fne-key" v-model="apiKey" type="password" class="input" autocomplete="off" :placeholder="fne.company.value?.fne_api_key_set ? 'Clé enregistrée : saisissez-en une autre pour la remplacer' : 'Collez la clé API'">
          <button type="button" class="btn-secondary whitespace-nowrap" :disabled="!apiKey || savingKey" @click="saveKey">Enregistrer la clé</button>
        </div>
        <p class="text-sm" :class="fne.company.value?.fne_enabled ? 'text-emerald-700' : 'text-gray-500'">
          {{ fne.company.value?.fne_enabled ? `✓ Certification active (${fne.company.value.fne_settings.mode === 'production' ? 'production' : 'test'})` : 'Certification inactive : cochez la case, enregistrez la clé et l\'URL.' }}
          <template v-if="fne.company.value?.fne_balance_sticker != null"> · Stickers restants : <b :class="fne.company.value.fne_warning ? 'text-red-600' : ''">{{ fne.company.value.fne_balance_sticker }}</b></template>
        </p>
      </div>
    </section>
  </SettingsPage>
</template>

<script setup>
import { computed, ref } from 'vue'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useAuthStore } from '../../../stores/auth'
import { useToastStore } from '../../../stores/toasts'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'
import { money } from '../../../utils/format'

// Identité : réglages généraux ; TVA : droit des factures
const identity = useCompanySettings(['legal_name', 'ncc', 'rccm', 'tax_regime', 'tax_office'])
const vat = useCompanySettings(['vat_settings'])
const fne = useCompanySettings(['fne_settings'])
const auth = useAuthStore()
const toasts = useToastStore()
const apiKey = ref('')
const savingKey = ref(false)

// La clé n'est jamais renvoyée par le serveur : seulement « enregistrée » ou non
async function saveKey() {
  savingKey.value = true
  try {
    const { data } = await http.patch(`/companies/${auth.user.company_id}`, { fne_api_key: apiKey.value.trim() })
    fne.company.value = data.data
    apiKey.value = ''
    toasts.success('Clé API enregistrée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    savingKey.value = false
  }
}

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
