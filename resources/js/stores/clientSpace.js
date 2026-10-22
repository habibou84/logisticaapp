import { defineStore } from 'pinia'
import { cachedGet } from '../composables/useCachedApi'
import { ACTIVITIES } from '../utils/activities'
import { useAuthStore } from './auth'

const KEY = 'client-activity'
const read = () => { try { return localStorage.getItem(KEY) || '' } catch { return '' } }

/**
 * Application du client : ses activités (e-commerce, distribution, MAD), celle qu'il regarde,
 * le forfait du mois de ses formules, ses factures à payer et ses livreurs attitrés (MAD).
 */
export const useClientSpaceStore = defineStore('clientSpace', {
  state: () => ({
    space: null,
    // Activité choisie en haut des écrans ('' : toutes)
    selected: read(),
  }),

  getters: {
    activities: () => {
      const own = useAuthStore().user?.merchant?.activities || ['ecommerce']
      return ACTIVITIES.filter((a) => own.includes(a.value))
    },
    // Activité affichée : celle choisie, ou la seule du client
    current(state) {
      if (this.activities.length === 1) return this.activities[0].value
      return this.activities.some((a) => a.value === state.selected) ? state.selected : ''
    },
    hasEcommerce() { return this.activities.some((a) => a.value === 'ecommerce') },
    hasBilled() { return this.activities.some((a) => a.value !== 'ecommerce') },
    // E-commerce visible (argent, reversements) selon l'activité choisie
    showsEcommerce() { return this.hasEcommerce && (!this.current || this.current === 'ecommerce') },
    showsBilled() { return this.hasBilled && this.current !== 'ecommerce' },
    plans(state) {
      return (state.space?.plans || []).filter((p) => !this.current || p.activity === this.current)
    },
    planFor: (state) => (activity) => (state.space?.plans || []).find((p) => p.activity === activity) || null,
  },

  actions: {
    select(value) {
      this.selected = value
      try { localStorage.setItem(KEY, value) } catch { /* stockage indisponible */ }
    },
    async load() {
      if (!this.hasBilled) return
      try {
        this.space = (await cachedGet('/merchant/space')).data.data
      } catch {
        // Tableau de bord facultatif : l'application reste utilisable sans
      }
    },
  },
})
