<template>
  <SettingsPage icon="🏪" title="E-commerçants" intro="Comment les e-commerçants rejoignent votre entreprise.">
    <template v-if="form">
      <section class="card p-4 space-y-3">
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.merchant_signup" type="checkbox" class="mt-1">
          <span>Inscriptions en ligne ouvertes<span class="block text-gray-500">Les e-commerçants créent leur compte depuis la page d'accueil (« Créer mon compte »). Le compte est actif dès que le numéro est vérifié par WhatsApp ou SMS ; vous êtes prévenu à chaque inscription. Fermées : ils vous envoient une demande, que vous acceptez ou refusez dans E-commerçants.</span></span>
        </label>
      </section>

      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Annuaire des entreprises de livraison</h2>
        <p class="text-sm text-gray-600">
          Les e-commerçants de Jibiat Marchand et les visiteurs de jibiat.com/annuaire vous trouvent, voient vos villes, votre prix de départ
          et vos vrais résultats des 90 derniers jours (livraisons réussies, délai moyen), puis se connectent à vous en un clic.
        </p>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.directory_listed" type="checkbox" class="mt-1">
          <span>Figurer dans l'annuaire<span class="block text-gray-500">Vos chiffres n'apparaissent qu'à partir de 20 courses terminées. Vous pouvez vous retirer à tout moment.</span></span>
        </label>
        <div v-if="form.directory_listed">
          <label class="label" for="directory-pitch">Votre présentation en une phrase</label>
          <textarea id="directory-pitch" v-model="form.directory_pitch" class="input" rows="2" maxlength="240" placeholder="Ex. : Livraison le jour même dans tout Abidjan, reversement chaque soir par Wave." />
          <p class="text-xs text-gray-500 mt-1">{{ (form.directory_pitch || '').length }} / 240 caractères. Dites ce qui vous distingue : zones, rapidité, reversements, expédition à l'intérieur du pays.</p>
        </div>
      </section>

      <section v-if="inviteUrl" class="card p-4 space-y-3">
        <h2 class="font-semibold">Inviter vos e-commerçants sur Jibiat Marchand</h2>
        <p class="text-sm text-gray-600">
          Jibiat Marchand gère leurs commandes, leur stock et leur boutique en ligne, et vous envoie leurs courses toutes seules.
          Avec votre lien, ils ont <strong>un mois d'essai en plus</strong> de l'offre Pro, puis se connectent à vous en un clic.
        </p>
        <p v-if="!form.directory_listed" class="text-sm text-amber-700">Le mois en plus n'est offert que si votre entreprise figure dans l'annuaire.</p>
        <div class="flex flex-wrap items-center gap-2">
          <input :value="inviteUrl" class="input flex-1 min-w-0 text-sm" readonly aria-label="Lien d'invitation" @focus="$event.target.select()">
          <button type="button" class="btn-secondary" @click="copy">{{ copied ? 'Copié ✓' : 'Copier' }}</button>
          <a :href="whatsappUrl" target="_blank" rel="noopener" class="btn-primary">Envoyer par WhatsApp</a>
        </div>
      </section>
      <SaveBar :dirty="dirty" :saving="saving" :error="error" @save="save" @reset="reset" />
    </template>
  </SettingsPage>
</template>

<script setup>
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import SaveBar from '../../../components/settings/SaveBar.vue'
import { useCompanySettings } from '../../../composables/useCompanySettings'
import { computed, ref } from 'vue'
import { useAuthStore } from '../../../stores/auth'

const auth = useAuthStore()

// Lien d'invitation Jibiat Marchand (un mois d'essai en plus pour les e-commerçants invités)
const inviteUrl = computed(() => auth.user?.company?.jibiat_marchand_invite_url)
const whatsappUrl = computed(() => `https://wa.me/?text=${encodeURIComponent(`Gérez vos commandes, votre stock et votre boutique en ligne avec Jibiat Marchand, et envoyez-nous vos courses en un clic. Un mois d'essai en plus avec ce lien : ${inviteUrl.value}`)}`)
const copied = ref(false)
async function copy() {
  try {
    await navigator.clipboard.writeText(inviteUrl.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    // Copie refusée par le navigateur : le lien reste sélectionnable
  }
}

const { form, dirty, saving, error, save, reset } = useCompanySettings(['merchant_signup', 'directory_listed', 'directory_pitch'])
</script>
