import { ref } from 'vue'

/**
 * Clavier du téléphone ouvert : la partie visible de l'écran a nettement rapetissé.
 * Sert à cacher les barres fixées en bas, qui mangeraient la place du champ et des listes.
 * (La hauteur, plutôt que le focus : fermer le clavier par « retour » laisse le champ actif.)
 */
export const keyboardOpen = ref(false)

const viewport = window.visualViewport
let tallest = viewport?.height || 0

function measure() {
  // Rotation ou barre d'adresse : la hauteur de référence suit la plus grande vue
  tallest = Math.max(tallest, viewport.height)
  keyboardOpen.value = viewport.height < tallest - 150
}

viewport?.addEventListener('resize', measure)
window.addEventListener('orientationchange', () => {
  tallest = 0
  setTimeout(measure, 300)
})
