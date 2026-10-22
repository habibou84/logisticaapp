import { defineStore } from 'pinia'

let nextId = 1

export const useToastStore = defineStore('toasts', {
  state: () => ({ items: [] }),
  actions: {
    push(message, type = 'info', { title = null, timeout = 5000, to = null, href = null } = {}) {
      const id = nextId++
      this.items.push({ id, message, type, title, to, href })
      if (timeout) setTimeout(() => this.dismiss(id), timeout)
    },
    success(message, opts) { this.push(message, 'success', opts) },
    error(message, opts) { this.push(message, 'error', opts) },
    dismiss(id) { this.items = this.items.filter((t) => t.id !== id) },
  },
})
