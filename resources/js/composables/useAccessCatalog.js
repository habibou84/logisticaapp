import { computed, reactive } from 'vue'
import http from '../bootstrap/axios'

/**
 * Catalogue des droits (GET /roles) : rôles intégrés et sur mesure, permissions par domaine, activités, modèles de
 * rôle, droits que le compte connecté peut donner ; zones (communes) et dépôts pour le périmètre.
 */
const state = reactive({ loaded: false, roles: [], permissions: [], domains: [], activities: [], templates: [], grantable: [], zones: [], hubs: [] })

export function useAccessCatalog() {
  async function load() {
    const [roles, zones, hubs] = await Promise.all([
      http.get('/roles'),
      http.get('/zones').catch(() => ({ data: { data: [] } })),
      http.get('/hubs').catch(() => ({ data: { data: [] } })),
    ])
    Object.assign(state, {
      loaded: true,
      roles: roles.data.data,
      permissions: roles.data.permissions,
      domains: roles.data.domains,
      activities: roles.data.activities,
      templates: roles.data.templates,
      grantable: roles.data.grantable,
      // Périmètre : par commune (ses quartiers suivent)
      zones: zones.data.data.filter((z) => !z.parent_id),
      hubs: hubs.data.data,
    })
  }

  const role = (value) => state.roles.find((r) => r.value === value)
  const permissionsByDomain = computed(() => state.domains.map((d) => ({ ...d, permissions: state.permissions.filter((p) => p.domain === d.value) })))
  // Équipe : rôles intégrés de l'équipe et rôles sur mesure (pas les livreurs)
  const isStaffRole = (value) => !!value && value !== 'courier' && !!role(value)

  return { catalog: state, load, role, permissionsByDomain, isStaffRole }
}
