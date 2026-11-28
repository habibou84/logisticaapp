import { defineStore } from 'pinia'
import { useAuthStore } from './auth'
import { myActivities } from '../utils/activities'

// Activité choisie dans les écrans Courses (Courses, Activité des livreurs, Retours clients, Courses en retard) :
// la même sur les quatre écrans, gardée pendant la session ; vide : toutes. Le menu de gauche n'en tient pas compte.
const KEY = 'courses.activity'

function read() {
  try {
    return sessionStorage.getItem(KEY) || ''
  } catch {
    return ''
  }
}

export const useActivityFilterStore = defineStore('activityFilter', {
  state: () => ({ value: read() }),
  getters: {
    // Activités entre lesquelles choisir : le périmètre du compte (aucun choix s'il n'en a qu'une)
    choices: () => myActivities(useAuthStore().user),
    // Activité réellement appliquée : seulement si elle fait partie du périmètre et qu'il y a un choix
    current(state) {
      return this.choices.length > 1 && this.choices.some((a) => a.value === state.value) ? state.value : ''
    },
  },
  actions: {
    set(value) {
      this.value = value || ''
      try {
        if (this.value) sessionStorage.setItem(KEY, this.value)
        else sessionStorage.removeItem(KEY)
      } catch {
        // Stockage refusé (navigation privée) : le choix vaut jusqu'au rechargement de la page
      }
    },
  },
})
