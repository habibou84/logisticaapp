import { defineStore } from 'pinia'

// Consignes de l'agence non lues (application livreur)
export const useCourierMessageStore = defineStore('courierMessages', {
  state: () => ({ unread: 0, version: 0 }),
  actions: {
    setUnread(count) {
      this.unread = count || 0
    },
    received() {
      this.unread++
      this.version++
    },
  },
})
