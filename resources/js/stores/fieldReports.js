import { defineStore } from 'pinia'
import http from '../bootstrap/axios'

// Remontées terrain à traiter (badge du menu du back-office)
export const useFieldReportStore = defineStore('fieldReports', {
  state: () => ({ open: { total: 0, incident: 0, note: 0, refusal: 0, expense: 0 }, version: 0 }),
  actions: {
    async fetchCounts() {
      this.open = (await http.get('/field-reports/counts')).data.data
    },
    setCounts(open) {
      if (open) this.open = open
    },
    // Nouvelle remontée reçue en temps réel : les écrans ouverts se rafraîchissent
    received() {
      this.open.total++
      this.version++
    },
  },
})
