<template>
  <SettingsPage icon="🏢" title="Entreprise et marque" intro="Ce que voient vos clients, vos livreurs et votre équipe : nom, coordonnées, logo, couleurs, adresse de l'espace.">
    <section v-if="form" class="card p-4 space-y-3">
      <h2 class="font-semibold">Identité</h2>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label" for="name">Nom</label><input id="name" v-model="form.name" class="input" required></div>
        <div><label class="label" for="phone">Téléphone</label><input id="phone" v-model="form.phone" class="input"></div>
        <div class="sm:col-span-2"><label class="label" for="address">Adresse</label><input id="address" v-model="form.address" class="input"></div>
        <div class="sm:col-span-2">
          <label class="label" for="tagline">Accroche de la page d'accueil des clients</label>
          <input id="tagline" v-model="form.tagline" class="input" maxlength="160" placeholder="Vos colis livrés vite, vos clients satisfaits.">
        </div>
      </div>
      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </section>

    <!-- Paiement des factures clients (distribution, MAD) : au choix de l'entreprise -->
    <section v-if="payments.form.value" class="card p-4 space-y-3">
      <div>
        <h2 class="font-semibold">Paiement des factures clients</h2>
        <p class="text-sm text-gray-600">
          Les moyens que vous acceptez : comptes bancaires, comptes Mobile Money, chèque, espèces… Ceux « proposés aux clients »
          sont imprimés sur les factures du mois et affichés dans l'application du client, avec un bouton pour copier l'IBAN ou le numéro.
        </p>
      </div>
      <InvoicePaymentMethods v-model="payments.form.value.invoice_payment_methods" />
      <div>
        <label class="label" for="payment-info">Note pour le client (facultatif)</label>
        <textarea id="payment-info" v-model="payments.form.value.invoice_payment_info" rows="2" class="input" maxlength="1000" placeholder="Ex. : envoyez la preuve de paiement sur WhatsApp au 07 00 00 00 00" />
      </div>
      <SaveBar :dirty="payments.dirty.value" :saving="payments.saving.value" :error="payments.error.value" @save="payments.save" @reset="payments.reset" />
    </section>

    <!-- Identité visuelle : enregistrée dès l'envoi -->
    <section v-if="company" class="card p-4 space-y-3">
      <h2 class="font-semibold">Logo</h2>
      <div class="flex flex-wrap items-center gap-4">
        <div class="h-20 w-40 rounded-lg border border-dashed border-slate-300 grid place-items-center bg-slate-50 overflow-hidden">
          <img v-if="logoUrl" :src="logoUrl" alt="Logo actuel" class="max-h-20 max-w-40 object-contain">
          <span v-else class="text-xs text-gray-500">Aucun logo</span>
        </div>
        <div class="space-y-2">
          <label class="btn-secondary cursor-pointer inline-block">
            {{ logoUrl ? 'Changer le logo' : 'Envoyer un logo' }}
            <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" :disabled="uploading" @change="uploadLogo">
          </label>
          <button v-if="logoUrl" type="button" class="btn-secondary text-red-700 ml-2" @click="removeLogo">Retirer</button>
          <p class="text-xs text-gray-500">PNG, JPEG ou WebP, 2 Mo au plus. Fond transparent conseillé (PNG).</p>
        </div>
      </div>
      <MoreHelp>Affiché sur la page d'accueil des clients (adresse du site), les pages de connexion, l'en-tête de chaque espace et l'icône des applications.</MoreHelp>
    </section>

    <AppearanceSettings v-if="company" />

    <!-- Guide vidéo « Bien démarrer » sur le tableau de bord -->
    <section v-if="guide.form.value" class="card p-4 space-y-3">
      <h2 class="font-semibold">Guide vidéo</h2>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="guide.form.value.guide_videos" type="checkbox" class="mt-1">
        <span>Afficher « Bien démarrer » sur le tableau de bord des administrateurs<span class="block text-gray-500">Huit vidéos, une par étape essentielle de la mise en route. Chacun peut aussi la masquer pour lui ; elle reste toujours dans Mise en route.</span></span>
      </label>
      <SaveBar :dirty="guide.dirty.value" :saving="guide.saving.value" :error="guide.error.value" @save="guide.save" @reset="guide.reset" />
    </section>
  </SettingsPage>
</template>

<script setup>
import { ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import MoreHelp from '../../../components/settings/MoreHelp.vue'
import AppearanceSettings from '../../../components/AppearanceSettings.vue'
import InvoicePaymentMethods from '../../../components/settings/InvoicePaymentMethods.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'
import { useToastStore } from '../../../stores/toasts'
import { loadBranding } from '../../../composables/useBranding'

const { form, company, dirty, saving, error, save, reset } = useCompanySettings(['name', 'phone', 'address', 'tagline'])
// Moyens de paiement des factures : enregistrés à part
const payments = useCompanySettings(['invoice_payment_methods', 'invoice_payment_info'])
const guide = useCompanySettings(['guide_videos'])
const toasts = useToastStore()
const logoUrl = ref(null)
const uploading = ref(false)
watch(company, (c) => { logoUrl.value = c?.logo_url ?? null })

async function uploadLogo(event) {
  const file = event.target.files[0]
  event.target.value = ''
  if (!file) return
  uploading.value = true
  try {
    const body = new FormData()
    body.append('logo', file)
    logoUrl.value = (await http.post('/company/logo', body)).data.data.logo_url
    loadBranding(true)
    toasts.success('Logo enregistré.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    uploading.value = false
  }
}

async function removeLogo() {
  await http.delete('/company/logo')
  logoUrl.value = null
  loadBranding(true)
}
</script>
