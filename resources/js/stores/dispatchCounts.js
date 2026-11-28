import { defineStore } from 'pinia'
import http from '../bootstrap/axios'

// Files du dispatch : courses à valider, à ramasser, à livrer, et celles qui attendent
// un livreur au-delà du délai de l'entreprise (badge du menu « Courses »)
let inFlight = null
let again = null

export const useDispatchCountStore = defineStore('dispatchCounts', {
  state: () => ({
    counts: {
      to_confirm: 0, to_pickup: 0, to_deliver: 0, unassigned: 0, internal_late: 0,
      late: { pickup: 0, delivery: 0, total: 0 },
      waiting: { pickup: null, delivery: null },
      cutoff: null,
    },
    loaded: false,
    version: 0,
    // Jour affiché dans la liste des courses (AAAA-MM-JJ) et ses compteurs : dû ce jour-là, prévu plus tard
    day: null,
    dayCounts: null,
    // Activité choisie dans la liste des courses (vide : toutes) ; les pastilles du menu restent celles de toute l'entreprise
    activity: null,
  }),
  actions: {
    // Appels regroupés : pendant un chargement, les demandes suivantes attendent sa fin et n'en relancent
    // qu'un seul (le dernier état), au lieu d'empiler des requêtes /orders/counts identiques.
    fetchCounts() {
      if (inFlight) {
        again ??= inFlight.catch(() => {}).then(() => { again = null; return this.fetchCounts() })
        return again
      }
      const params = {}
      if (this.day) params.day = this.day
      if (this.activity) params.activity = this.activity
      inFlight = http.get('/orders/counts', { params })
        .then(({ data }) => {
          this.counts = data.data
          this.dayCounts = data.data.day || null
          this.loaded = true
        })
        .finally(() => { inFlight = null })
      return inFlight
    },
    // Alerte reçue en temps réel : les listes ouvertes se rafraîchissent
    received() {
      this.version++
      this.fetchCounts().catch(() => {})
    },
  },
})
