import { defineStore } from 'pinia'

let nextId = 1
const timers = new Map()

// Téléphone : alertes plus courtes (les urgentes restent un peu plus)
const isPhone = () => typeof window !== 'undefined' && window.matchMedia?.('(max-width: 639px)').matches
const PHONE_TIMEOUT = { info: 6000, success: 6000, error: 10000 }

export const useToastStore = defineStore('toasts', {
  // bellOpen : demande d'ouverture de la cloche (« + N autres alertes ») ; bellMounted : la cloche est à l'écran
  state: () => ({ items: [], bellOpen: 0, bellMounted: false }),
  actions: {
    /**
     * Les alertes du même genre (même groupe, sinon même titre) n'en font qu'une, avec un compteur.
     */
    push(message, type = 'info', { title = null, timeout = 5000, to = null, href = null, group = null } = {}) {
      if (timeout && isPhone()) timeout = Math.min(timeout, PHONE_TIMEOUT[type] ?? 6000)
      const key = group || (title ? `${type}:${title}` : null)
      const same = key && this.items.find((t) => t.key === key)
      if (same) {
        Object.assign(same, { message, to, href, count: same.count + 1, timeout })
        // La plus récente passe devant
        this.items = [same, ...this.items.filter((t) => t !== same)]
        this.schedule(same.id, timeout)
        return
      }
      const id = nextId++
      this.items.unshift({ id, key, message, type, title, to, href, count: 1, timeout })
      this.schedule(id, timeout)
    },
    success(message, opts) { this.push(message, 'success', opts) },
    error(message, opts) { this.push(message, 'error', opts) },
    schedule(id, ms) {
      clearTimeout(timers.get(id)?.handle)
      if (!ms) return timers.delete(id)
      timers.set(id, { handle: setTimeout(() => this.dismiss(id), ms), until: Date.now() + ms })
    },
    // Doigt (ou souris) sur l'alerte : le temps s'arrête, puis reprend où il en était
    pause(id) {
      const t = timers.get(id)
      if (!t || t.paused) return
      clearTimeout(t.handle)
      timers.set(id, { paused: true, left: Math.max(1500, t.until - Date.now()) })
    },
    resume(id) {
      const t = timers.get(id)
      if (t?.paused) this.schedule(id, t.left)
    },
    dismiss(id) {
      clearTimeout(timers.get(id)?.handle)
      timers.delete(id)
      this.items = this.items.filter((t) => t.id !== id)
    },
    clear() {
      for (const t of this.items) this.dismiss(t.id)
    },
    openBell() { this.bellOpen++ },
  },
})
