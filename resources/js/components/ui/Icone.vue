<template>
  <!-- Pas encore dans le dictionnaire (ancien emoji) : affiché tel quel -->
  <span v-if="!ICONES[nom]" aria-hidden="true">{{ nom }}</span>
  <!-- Icône de la charte : au trait, couleur du texte voisin ; décorative (le texte à côté dit la même chose) -->
  <component
    :is="ICONES[nom]"
    v-else
    :size="SIZES[taille]"
    :stroke-width="2"
    class="shrink-0"
    aria-hidden="true"
    focusable="false"
  />
</template>

<script setup>
import { ICONES } from '../../utils/icones'

/** Icône du dictionnaire de la charte (resources/js/utils/icones.js). Tailles : petite 16, normale 20, grande 24. */
defineProps({
  nom: { type: String, required: true },
  taille: { type: String, default: 'normale', validator: (v) => ['petite', 'normale', 'grande'].includes(v) },
})

const SIZES = { petite: 16, normale: 20, grande: 24 }
</script>
