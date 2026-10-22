import { defineStore } from 'pinia'
import http from '../bootstrap/axios'

export const useNotificationStore = defineStore('notifications', {
  state: () => ({ items: [], unread: 0, loaded: false }),
  actions: {
    async fetch() {
      const { data } = await http.get('/notifications')
      this.items = data.data
      this.unread = data.unread_count
      this.loaded = true
    },
    receive(notification) {
      this.items.unshift({ ...notification, read_at: null, created_at: new Date().toISOString() })
      this.items = this.items.slice(0, 50)
      this.unread++
    },
    async markAllRead() {
      const { data } = await http.post('/notifications/read')
      this.unread = data.unread_count
      this.items = this.items.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }))
    },
    // Notification ouverte : marquée comme lue (le compteur suit tout de suite, le serveur ensuite)
    markRead(notification) {
      if (!notification?.id || notification.read_at) return
      notification.read_at = new Date().toISOString()
      this.unread = Math.max(0, this.unread - 1)
      http.post('/notifications/read', { ids: [notification.id] })
        .then(({ data }) => { this.unread = data.unread_count })
        .catch(() => {})
    },
    reset() {
      this.items = []
      this.unread = 0
      this.loaded = false
    },
  },
})
