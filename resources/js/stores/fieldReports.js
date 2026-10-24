import { defineStore } from 'pinia'
import http from '../bootstrap/axios'

// Remontées terrain à traiter (badge du menu du back-office)
export const useFieldReportStore = defineStore('fieldReports', {
  // events : événements terrain (panne, accident…) que personne n'a encore pris en charge
  state: () => ({ open: { total: 0, incident: 0, note: 0, refusal: 0, expense: 0 }, events: 0, version: 0, eventsVersion: 0 }),
  getters: {
    badge: (state) => state.open.total + state.events,
  },
  actions: {
    async fetchCounts() {
      const [reports, events] = await Promise.all([
        http.get('/field-reports/counts'),
        http.get('/field-events/counts').catch(() => null),
      ])
      this.open = reports.data.data
      if (events) this.events = events.data.data.open
    },
    setEvents(open) {
      if (open !== undefined && open !== null) this.events = open
    },
    // Nouvel événement terrain (ou message du livreur) reçu en temps réel
    eventReceived(isNew) {
      if (isNew) this.events++
      this.eventsVersion++
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
