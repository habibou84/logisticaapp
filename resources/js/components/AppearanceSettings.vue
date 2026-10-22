<template>
  <section v-if="look" class="card p-4 space-y-4">
    <div>
      <h2 class="font-semibold">Apparence des espaces</h2>
      <p class="text-sm text-gray-600">Couleurs de l'espace e-commerçant, de l'espace livreur et de l'espace équipe, nom et icône des applications installées sur les téléphones. Le logo ci-dessus s'affiche dans chaque espace.</p>
    </div>

    <label class="flex items-center gap-2 text-sm font-medium">
      <input v-model="look.same" type="checkbox">
      Même apparence pour tous les espaces (couleur et icône de l'espace e-commerçant partout)
    </label>

    <div class="grid gap-4 md:grid-cols-3">
      <div v-for="s in SPACES" :key="s.key" :class="['rounded-lg border p-3 space-y-3', look.same && s.key !== 'marchand' ? 'opacity-60' : '']">
        <h3 class="font-semibold">{{ s.label }}</h3>

        <!-- Aperçu : barre du haut de l'espace et icône du téléphone -->
        <div class="rounded-lg overflow-hidden border">
          <div class="h-9 px-2 flex items-center gap-2 text-white text-sm font-semibold" :style="{ backgroundColor: colorOf(s.key) }">
            <img v-if="branding.logo_url" :src="branding.logo_url" alt="" class="h-6 w-6 rounded bg-white object-contain p-0.5">
            <span class="truncate">{{ s.preview }}</span>
          </div>
          <div class="p-2 bg-slate-50 flex items-center gap-3">
            <button type="button" class="rounded-md px-3 py-1 text-xs font-semibold text-white" :style="{ backgroundColor: colorOf(s.key) }">Bouton</button>
            <div v-if="s.app" class="flex flex-col items-center ml-auto">
              <img v-if="iconPreview(s.key)" :src="iconPreview(s.key)" alt="" class="h-11 w-11 rounded-xl object-cover shadow">
              <span v-else class="h-11 w-11 rounded-xl grid place-items-center text-white shadow" :style="{ backgroundColor: colorOf(s.key) }">{{ s.emoji }}</span>
              <span class="text-[10px] text-slate-700 mt-0.5 max-w-16 truncate">{{ look.spaces[s.key].app_name || look.spaces[s.key].default_app_name || s.defaultName }}</span>
            </div>
          </div>
        </div>

        <fieldset :disabled="look.same && s.key !== 'marchand'" class="space-y-3">
          <div>
            <p class="label">Couleur</p>
            <div class="flex flex-wrap items-center gap-1.5">
              <button
                v-for="c in PRESETS" :key="c" type="button"
                :class="['h-7 w-7 rounded-full ring-offset-2', look.spaces[s.key].color === c ? 'ring-2 ring-slate-900' : '']"
                :style="{ backgroundColor: c }" :aria-label="`Couleur ${c}`"
                @click="look.spaces[s.key].color = c"
              />
              <input v-model="look.spaces[s.key].color" type="color" class="h-7 w-9 cursor-pointer rounded border" :aria-label="`Autre couleur pour ${s.label}`">
            </div>
            <p v-if="tooLight(look.spaces[s.key].color)" class="mt-1 text-xs text-amber-700">⚠️ Couleur claire : le texte blanc sera difficile à lire. Choisissez une couleur plus foncée.</p>
          </div>
        </fieldset>

        <template v-if="s.app">
          <div>
            <label class="label" :for="`app-name-${s.key}`">Nom sous l'icône du téléphone</label>
            <input :id="`app-name-${s.key}`" v-model="look.spaces[s.key].app_name" class="input" maxlength="30" :placeholder="look.spaces[s.key].default_app_name || s.defaultName">
          </div>
          <fieldset :disabled="look.same && s.key !== 'marchand'" class="space-y-1">
            <p class="label">Icône de l'application</p>
            <p class="text-xs text-gray-500">{{ look.spaces[s.key].custom_icon ? 'Image carrée envoyée.' : (branding.logo_url ? 'Logo sur la couleur de l\'espace.' : 'Icône standard (envoyez un logo ou une image).') }}</p>
            <div class="flex flex-wrap gap-2">
              <label class="btn-secondary cursor-pointer text-xs">
                Envoyer une image carrée
                <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="uploadIcon(s.key, $event)">
              </label>
              <button v-if="look.spaces[s.key].custom_icon" type="button" class="btn-secondary text-xs text-red-700" @click="removeIcon(s.key)">Retirer</button>
            </div>
          </fieldset>
        </template>
      </div>
    </div>

    <p class="text-xs text-gray-500">
      Téléphones où l'application est déjà installée : le nom et l'icône se mettent à jour d'eux-mêmes sous 24 à 48 h sur Android ; sur iPhone, supprimez puis réinstallez l'application.
      Les applications Android téléchargées (APK) gardent leur icône, mais leurs écrans prennent la couleur et le logo.
    </p>
    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    <button type="button" class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer l\'apparence' }}</button>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { branding, loadBranding } from '../composables/useBranding'
import { useToastStore } from '../stores/toasts'

/**
 * Paramètres › Apparence : couleur de chaque espace, nom et icône des applications installables.
 */
const SPACES = [
  { key: 'marchand', label: 'Espace e-commerçant', preview: 'Mes livraisons', app: true, defaultName: 'Nom de l\'entreprise', emoji: '📦' },
  { key: 'livreur', label: 'Espace livreur', preview: 'Mes missions', app: true, defaultName: 'Livreur', emoji: '🛵' },
  { key: 'admin', label: 'Espace équipe', preview: 'Administration', app: false },
]
const PRESETS = ['#047857', '#1d4ed8', '#0f172a', '#7c3aed', '#be123c', '#c2410c', '#0e7490', '#4d7c0f']

const toasts = useToastStore()
const look = ref(null)
const saving = ref(false)
const error = ref('')

const colorOf = (space) => (look.value.same ? look.value.spaces.marchand.color : look.value.spaces[space].color)
// Icône générée par le serveur (après enregistrement) ; sinon aperçu de la couleur
const iconPreview = (space) => (look.value.spaces[space].icon_url?.startsWith('/branding/') ? look.value.spaces[space].icon_url : null)

// Texte blanc lisible ? (luminance relative, seuil proche du contraste 3:1)
function tooLight(hex) {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
  return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.3
}

function apply(data) {
  // Champ vide quand le nom par défaut s'applique (nom de l'entreprise pour l'e-commerçant) : il suit
  // alors un changement de nom de l'entreprise au lieu d'être enregistré tel quel
  for (const s of SPACES.filter((x) => x.app)) {
    const space = data.spaces[s.key]
    if (space.app_name === space.default_app_name) space.app_name = ''
  }
  look.value = data
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    const spaces = Object.fromEntries(SPACES.map((s) => [s.key, { color: look.value.spaces[s.key].color, app_name: look.value.spaces[s.key].app_name || null }]))
    apply((await http.put('/company/appearance', { same: look.value.same, spaces })).data.data)
    await loadBranding(true)
    toasts.success('Apparence enregistrée.')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function uploadIcon(space, event) {
  const file = event.target.files[0]
  event.target.value = ''
  if (!file) return
  try {
    const body = new FormData()
    body.append('icon', file)
    apply((await http.post(`/company/appearance/icons/${space}`, body)).data.data)
    loadBranding(true)
    toasts.success('Icône enregistrée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function removeIcon(space) {
  apply((await http.delete(`/company/appearance/icons/${space}`)).data.data)
  loadBranding(true)
}

onMounted(async () => {
  apply((await http.get('/company/appearance')).data.data)
})
</script>
