<template>
  <section v-if="state" class="card p-4 space-y-3">
    <div>
      <h2 class="font-semibold">Reprise de l'ancienne application</h2>
      <p class="text-sm text-gray-600">
        Vos e-commerçants et livreurs arrivent depuis l'ancienne application avec un bouton, en gardant leur numéro
        et leur mot de passe. Les commandes ne sont pas reprises, ni les comptes de l'équipe : recréez-les dans Utilisateurs.
      </p>
    </div>

    <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-700">
      <li>
        Copiez le module dans l'ancienne application, en suivant les explications en tête du fichier :
        <button type="button" class="text-blue-600" @click="downloadModule">télécharger JibiatMigrationController.php</button>.
      </li>
      <li>Créez une clé de reprise ci-dessous et collez-la, avec l'adresse de votre espace, dans sa page « Migration vers Jibiat ».</li>
      <li>Cliquez sur « Simuler », vérifiez le résultat, puis sur « Envoyer ». Relancer ne crée pas de doublon.</li>
    </ol>

    <!-- Clé affichée une seule fois -->
    <div v-if="key" class="space-y-2 rounded-lg bg-amber-50 p-3 text-sm">
      <p class="font-medium">Copiez la clé maintenant : elle ne sera plus affichée.</p>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-gray-600">Adresse :</span><code class="break-all">{{ origin }}</code>
        <button type="button" class="text-xs text-blue-600" @click="copy(origin)">Copier</button>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-gray-600">Clé :</span><code class="break-all">{{ key }}</code>
        <button type="button" class="text-xs text-blue-600" @click="copy(key)">Copier</button>
      </div>
    </div>

    <p class="text-sm">
      <template v-if="state.active">✅ Clé active jusqu'au {{ dateTime(state.expires_at) }}.</template>
      <template v-else>Aucune clé active.</template>
      <span v-if="state.used_at" class="text-gray-500"> Dernier envoi reçu le {{ dateTime(state.used_at) }}.</span>
    </p>

    <div class="flex flex-wrap gap-2">
      <button type="button" class="btn-primary" :disabled="busy" @click="createKey">{{ state.active ? 'Créer une nouvelle clé' : 'Créer une clé de reprise' }}</button>
      <button v-if="state.active" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="revoke">Désactiver la clé</button>
    </div>
    <p class="text-xs text-gray-500">
      La clé est valable 7 jours ; une nouvelle clé remplace la précédente. Désactivez-la une fois la reprise terminée.
      Créez d'abord vos zones : la commune de chaque e-commerçant sert à choisir sa zone de ramassage.
    </p>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { dateTime } from '../utils/format'

/**
 * Paramètres › Reprise de l'ancienne application (Laravel 5.8) : clé à coller dans son bouton d'envoi.
 */
const toasts = useToastStore()
const state = ref(null)
const key = ref('')
const busy = ref(false)
const origin = window.location.origin

async function createKey() {
  if (state.value.active && !window.confirm('Créer une nouvelle clé ? L\'ancienne ne fonctionnera plus.')) return
  busy.value = true
  try {
    const { data } = await http.post('/company/legacy-import/key')
    key.value = data.data.key
    state.value = data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function revoke() {
  busy.value = true
  try {
    state.value = (await http.delete('/company/legacy-import/key')).data.data
    key.value = ''
    toasts.success('Clé désactivée.')
  } finally {
    busy.value = false
  }
}

async function downloadModule() {
  const { data } = await http.get('/company/legacy-import/module', { responseType: 'blob' })
  const url = URL.createObjectURL(data)
  const link = Object.assign(document.createElement('a'), { href: url, download: 'JibiatMigrationController.php' })
  link.click()
  URL.revokeObjectURL(url)
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Copié.')
  } catch {
    // presse-papiers indisponible
  }
}

onMounted(async () => {
  try {
    state.value = (await http.get('/company/legacy-import')).data.data
  } catch {
    state.value = null
  }
})
</script>
