import { defineStore } from 'pinia'
import http from '../bootstrap/axios'

// Files du dispatch : courses à valider, à ramasser, à livrer, et celles qui attendent
// un livreur au-delà du délai de l'entreprise (badge du menu « Courses »)
export const useDispatchCountStore = defineStore('dispatchCounts', {
  state: () => ({
    counts: {
      to_confirm: 0, to_pickup: 0, to_deliver: 0, unassigned: 0,
      late: { pickup: 0, delivery: 0, total: 0 },
      waiting: { pickup: null, delivery: null },
      cutoff: null,
    },
    loaded: false,
    version: 0,
    // Jour affiché dans la liste des courses (AAAA-MM-JJ) et ses compteurs : dû ce jour-là, prévu plus tard
    day: null,
    dayCounts: null,
  }),
  actions: {
    async fetchCounts() {
      const data = (await http.get('/orders/counts', { params: this.day ? { day: this.day } : {} })).data.data
      this.counts = data
      this.dayCounts = data.day || null
      this.loaded = true
    },
    // Alerte reçue en temps réel : les listes ouvertes se rafraîchissent
    received() {
      this.version++
      this.fetchCounts().catch(() => {})
    },
  },
})
