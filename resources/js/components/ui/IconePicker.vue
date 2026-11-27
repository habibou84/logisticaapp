<template>
  <!-- Choix d'une icône de la charte (plans d'entretien, types de remontée) : un bouton, puis une grille -->
  <div ref="root" class="relative">
    <button type="button" class="input grid place-items-center px-1" :aria-label="label" :aria-expanded="open" @click="open = !open">
      <Icone :nom="modelValue || 'alerte'" />
    </button>
    <div v-if="open" class="absolute left-0 top-full z-30 mt-1 grid w-64 grid-cols-6 gap-1 rounded-xl border border-bordure bg-white p-2 shadow-flottante" role="listbox" :aria-label="label">
      <button
        v-for="c in CHOIX" :key="c.nom" type="button" role="option" :aria-selected="modelValue === c.nom" :title="c.label" :aria-label="c.label"
        :class="['grid h-9 w-9 place-items-center rounded-lg hover:bg-slate-100', modelValue === c.nom ? 'bg-info-fond text-info' : 'text-slate-700']"
        @click="choose(c.nom)"
      >
        <Icone :nom="c.nom" />
      </button>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Icone from './Icone.vue'

defineProps({ modelValue: { type: String, default: '' }, label: { type: String, default: 'Icône' } })
const emit = defineEmits(['update:modelValue'])

const CHOIX = [
  { nom: 'alerte', label: 'Alerte' }, { nom: 'atelier', label: 'Atelier' }, { nom: 'reparation', label: 'Réparation' }, { nom: 'vidange', label: 'Vidange' },
  { nom: 'pneu', label: 'Pneu' }, { nom: 'freins', label: 'Freins' }, { nom: 'chaine', label: 'Chaîne' }, { nom: 'moteur', label: 'Moteur' },
  { nom: 'batterie', label: 'Batterie' }, { nom: 'eclairage', label: 'Éclairage' }, { nom: 'carburant', label: 'Carburant' }, { nom: 'carrosserie', label: 'Carrosserie' },
  { nom: 'accident', label: 'Accident' }, { nom: 'sinistre', label: 'Sinistre' }, { nom: 'police', label: 'Contrôle' }, { nom: 'amende', label: 'Amende' },
  { nom: 'verrou', label: 'Vol, sécurité' }, { nom: 'meteo', label: 'Météo' }, { nom: 'sante', label: 'Santé' }, { nom: 'mobile', label: 'Téléphone, matériel' },
  { nom: 'casque', label: 'Casque' }, { nom: 'coffre', label: 'Coffre' }, { nom: 'document', label: 'Papier' }, { nom: 'assurance', label: 'Assurance' },
  { nom: 'course', label: 'Colis' }, { nom: 'adresse', label: 'Adresse' }, { nom: 'heure', label: 'Heure' }, { nom: 'argent', label: 'Argent' },
  { nom: 'message', label: 'Message' }, { nom: 'modifier', label: 'Autre' },
]

const open = ref(false)
const root = ref(null)
function choose(nom) {
  emit('update:modelValue', nom)
  open.value = false
}
// Clic ailleurs : la grille se referme
const outside = (e) => { if (open.value && !root.value?.contains(e.target)) open.value = false }
onMounted(() => document.addEventListener('click', outside))
onBeforeUnmount(() => document.removeEventListener('click', outside))
</script>
