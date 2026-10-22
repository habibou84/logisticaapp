import { defineStore } from 'pinia'
import http from '../bootstrap/axios'

// Colis encore chez les livreurs (badge du menu : colis en retard)
export const useHeldParcelStore = defineStore('heldParcels', {
  state: () => ({ counts: { total: 0, overdue: 0 }, version: 0 }),
  actions: {
    async fetchCounts() {
      this.counts = (await http.get('/parcels/held/counts')).data.data
    },
    setCounts(counts) {
      if (counts) this.counts = { total: counts.total, overdue: counts.overdue }
    },
    // Alerte reçue en temps réel : les écrans ouverts se rafraîchissent
    received() {
      this.version++
      this.fetchCounts().catch(() => {})
    },
  },
})
