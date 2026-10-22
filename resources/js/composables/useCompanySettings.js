import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toasts'

// Champs qu'on peut vider (sinon un champ vide est ignoré) ; les heures vides deviennent null
const CLEARABLE = ['tagline', 'daily_cutoff_time', 'depot_closes_at', 'auto_delivery_max_cod']
const TIMES = ['daily_cutoff_time', 'depot_closes_at']

/**
 * Réglages de l'entreprise d'une page : chargement, modifications en cours, enregistrement et annulation.
 * Une page quittée avec des modifications non enregistrées demande confirmation.
 *
 * @param {string[]} fields champs de l'entreprise gérés par la page
 */
export function useCompanySettings(fields) {
  const auth = useAuthStore()
  const toasts = useToastStore()
  const form = ref(null)
  const original = ref(null)
  const company = ref(null)
  const saving = ref(false)
  const error = ref('')

  const dirty = computed(() => !!form.value && JSON.stringify(form.value) !== JSON.stringify(original.value))

  async function load() {
    const { data } = await http.get(`/companies/${auth.user.company_id}`)
    company.value = data.data
    form.value = Object.fromEntries(fields.map((f) => [f, data.data[f]]))
    original.value = JSON.parse(JSON.stringify(form.value))
  }

  async function save() {
    saving.value = true
    error.value = ''
    try {
      const payload = Object.fromEntries(Object.entries(form.value)
        .filter(([k, v]) => CLEARABLE.includes(k) || (v !== null && v !== ''))
        .map(([k, v]) => [k, TIMES.includes(k) || v === '' ? v || null : v]))
      await http.patch(`/companies/${auth.user.company_id}`, payload)
      original.value = JSON.parse(JSON.stringify(form.value))
      await auth.fetchUser()
      toasts.success('Réglages enregistrés.')
    } catch (e) {
      error.value = apiErrorMessage(e)
    } finally {
      saving.value = false
    }
  }

  function reset() {
    form.value = JSON.parse(JSON.stringify(original.value))
    error.value = ''
  }

  const warn = (e) => {
    if (!dirty.value) return
    e.preventDefault()
    e.returnValue = ''
  }
  onMounted(() => { load(); window.addEventListener('beforeunload', warn) })
  onBeforeUnmount(() => window.removeEventListener('beforeunload', warn))
  onBeforeRouteLeave(() => !dirty.value || window.confirm('Des modifications ne sont pas enregistrées. Quitter quand même ?'))

  return { form, company, dirty, saving, error, save, reset }
}
