import { computed, ref, watch } from 'vue'
import http from '../bootstrap/axios'

// Départements d'un client pour une nouvelle course : ceux pour lesquels ce compte commande, tous ceux du client
// (courrier interne), réglages du client
export function useDepartments(merchantId, { mine = false } = {}) {
  const all = ref([])
  const settings = ref({})
  const scope = ref(null)
  const loaded = ref(false)

  async function load() {
    all.value = []
    loaded.value = false
    if (!merchantId.value) return
    try {
      const { data } = await http.get(mine ? '/merchant/departments' : `/merchants/${merchantId.value}/departments`)
      all.value = data.enabled ? data.data.filter((d) => d.active) : []
      settings.value = data.settings || {}
      scope.value = data.mine
    } catch {
      all.value = []
    } finally {
      loaded.value = true
    }
  }
  watch(merchantId, load, { immediate: true })

  // Départements pour lesquels ce compte peut commander
  const senders = computed(() => (scope.value ? all.value.filter((d) => scope.value.includes(d.id)) : all.value))
  return { all, senders, settings, scope, loaded, load }
}
