<template>
  <!-- Commune ou ville, puis un quartier de celle-ci seulement (obligatoire quand elle en a) -->
  <div :class="['grid gap-3', stacked ? '' : 'sm:grid-cols-2']">
    <div>
      <label :class="mobile ? 'm-label' : 'label'" :for="`${id}-commune`">{{ communeLabel }}<span v-if="required" class="text-red-600"> *</span></label>
      <SearchSelect
        :id="`${id}-commune`" :model-value="communeId" :options="communeOptions" placeholder="— Choisir —" :empty-value="null"
        :allow-empty="!required" :threshold="0" search-placeholder="Commune ou ville…" :input-class="inputClass"
        :required="required" :disabled="disabled" :aria-label="communeLabel" @update:model-value="pickCommune"
      />
    </div>
    <div>
      <label :class="mobile ? 'm-label' : 'label'" :for="`${id}-quartier`">Quartier<span v-if="quartierRequired" class="text-red-600"> *</span></label>
      <SearchSelect
        v-if="quartiers.length" :id="`${id}-quartier`" :model-value="quartierId" :options="quartierOptions"
        :placeholder="`— Quartier de ${commune.name} —`" :empty-value="null" :allow-empty="false" :threshold="8"
        :search-placeholder="`Quartier de ${commune.name}…`" :input-class="inputClass" :required="quartierRequired" :disabled="disabled"
        aria-label="Quartier" @update:model-value="pickQuartier"
      />
      <p v-else :class="['py-2 text-sm', mobile ? 'text-slate-500' : 'text-slate-600']">
        {{ !commune ? 'Choisissez d\'abord la commune ou la ville.' : commune.is_shipping ? 'Expédition : pas de quartier à choisir.' : `Pas de quartier dans la liste : ${shortName(commune)} suffit.` }}
      </p>
      <button v-if="canAdd && commune && !commune.is_shipping && !disabled" type="button" class="mt-1 text-xs font-medium text-blue-700 hover:underline" @click="addQuartier">
        + Ajouter un quartier à {{ commune.name }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { promptDialog } from '../composables/useDialog'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toasts'
import SearchSelect from './SearchSelect.vue'

/**
 * Lieu d'une adresse en deux temps : la commune (ou la ville), puis un quartier de cette commune seulement. Une commune
 * qui a des quartiers ne vaut pas adresse tant que le quartier n'est pas choisi (valeur vide) ; une commune ou une
 * ville sans quartier dans la liste suffit. Le modèle est l'identifiant de la zone : le quartier, ou la commune seule.
 * Un compte qui gère les zones peut ajouter un quartier manquant à la commune choisie.
 *
 * Zones attendues : { id, parent_id, name, short_name?, city?, is_shipping? } (les zones de l'entreprise, ou les
 * listes des pages publiques).
 */
const props = defineProps({
  modelValue: { type: Number, default: null },
  zones: { type: Array, default: () => [] },
  id: { type: String, default: 'lieu' },
  required: { type: Boolean, default: false },
  communeLabel: { type: String, default: 'Commune ou ville' },
  inputClass: { type: [String, Array], default: 'input' },
  // Téléphone (espace e-commerçant, boutique) : styles mobiles
  mobile: { type: Boolean, default: false },
  // Commune et quartier l'un sous l'autre (fenêtre étroite)
  stacked: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  // Ajout d'un quartier manquant (seulement si le compte gère les zones)
  allowAdd: { type: Boolean, default: true },
})
const emit = defineEmits(['update:modelValue', 'added'])

const auth = useAuthStore()
const toasts = useToastStore()
const canAdd = computed(() => props.allowAdd && auth.can('zones.manage'))

const added = ref([])
const all = computed(() => [...props.zones, ...added.value.filter((a) => !props.zones.some((z) => z.id === a.id))])
const byId = computed(() => Object.fromEntries(all.value.map((z) => [z.id, z])))
const shortName = (z) => z.short_name ?? z.name
const plain = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

// Communes d'Abidjan, puis villes de l'intérieur, puis zones d'expédition
const rank = (z) => (z.is_shipping ? 2 : (z.city && z.city !== 'Abidjan' ? 1 : 0))
const communes = computed(() => all.value.filter((z) => !z.parent_id)
  .sort((a, b) => rank(a) - rank(b) || plain(shortName(a)).localeCompare(plain(shortName(b)))))
const communeOptions = computed(() => communes.value.map((z) => ({
  value: z.id,
  label: shortName(z) + (z.is_shipping ? ' (expédition)' : rank(z) === 1 ? ' (intérieur)' : ''),
  search: shortName(z),
})))

const communeId = ref(null)
const commune = computed(() => byId.value[communeId.value] ?? null)
const quartiers = computed(() => (communeId.value === null ? [] : all.value.filter((z) => z.parent_id === communeId.value)
  .sort((a, b) => plain(shortName(a)).localeCompare(plain(shortName(b))))))
const quartierOptions = computed(() => quartiers.value.map((z) => ({ value: z.id, label: shortName(z) })))
const quartierId = computed(() => (byId.value[props.modelValue]?.parent_id ? props.modelValue : null))
const quartierRequired = computed(() => quartiers.value.length > 0 && (props.required || communeId.value !== null))

// Valeur reçue (quartier ou commune) : la commune affichée suit ; une valeur vidée garde la commune choisie
// Une commune qui a des quartiers (reçue d'un message lu, d'une ancienne course) n'est pas un lieu complet : la valeur
// est vidée, la commune reste affichée et le quartier est à choisir
watch(() => [props.modelValue, props.zones.length], () => {
  const zone = byId.value[props.modelValue]
  if (!zone) return
  communeId.value = zone.parent_id ?? zone.id
  if (!zone.parent_id && quartiers.value.length) emit('update:modelValue', null)
}, { immediate: true })

function pickCommune(id) {
  communeId.value = id
  // Commune sans quartier : elle suffit ; sinon, le quartier reste à choisir
  emit('update:modelValue', id !== null && !quartiers.value.length ? id : null)
}

function pickQuartier(id) {
  emit('update:modelValue', id)
}

async function addQuartier() {
  const parent = commune.value
  const name = (await promptDialog(`Nouveau quartier dans ${shortName(parent)} :`))?.trim()
  if (!name) return
  try {
    const { data } = await http.post('/zones', { name, parent_id: parent.id, ...(parent.city ? { city: parent.city } : {}) })
    const zone = { ...data.data, short_name: data.data.name }
    added.value = [...added.value, zone]
    emit('added', data.data)
    emit('update:modelValue', zone.id)
    toasts.success(`Quartier « ${name} » ajouté à ${shortName(parent)}.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}
</script>
