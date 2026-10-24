import { reactive } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'

// Critères et réglages de la notation : lus une fois
let criteriaPromise = null
export function ratingCriteria() {
  criteriaPromise ??= http.get('/ratings/criteria').then((r) => r.data.data).catch(() => {
    criteriaPromise = null
    return { client: [], reasons: [], team: [], settings: {} }
  })
  return criteriaPromise
}

/**
 * Client : noter le livreur d'une course (ou son livreur attitré en MAD) et le préférer ou l'écarter.
 */
export function useCourierRating() {
  const state = reactive({ open: false, saving: false, error: '', criteria: { client: [], reasons: [], settings: {} } })

  async function openSheet() {
    state.criteria = await ratingCriteria()
    state.error = ''
    state.open = true
  }

  async function save(url, payload) {
    state.saving = true
    state.error = ''
    try {
      const { data } = await http.post(url, payload)
      state.open = false
      return data.data
    } catch (e) {
      state.error = apiErrorMessage(e)
      return null
    } finally {
      state.saving = false
    }
  }

  async function prefer(courierId, kind) {
    try {
      await http.put(`/merchant/courier-preferences/${courierId}`, { kind })
      return kind
    } catch (e) {
      state.error = apiErrorMessage(e)
      return undefined
    }
  }

  return { state, openSheet, save, prefer }
}
