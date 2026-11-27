import { ACTIVITY_TABS } from './courierActivity'

// Pages réunies sous une seule entrée du menu, en onglets (menu et composant PageTabs) : chacune avec son droit
export const FLEET_TABS = [
  { to: '/admin/vehicules', label: 'Véhicules', icon: 'vehicule', permission: 'fleet.manage' },
  { to: '/admin/atelier', label: 'Atelier', icon: 'atelier', permission: ['repairs.manage', 'repairs.purchases'] },
]

// Courses : une entrée du menu, quatre cartes en haut de chaque page (composant CoursesTabs)
export const COURSES_PAGES = [
  { to: '/admin/courses', label: 'Courses', icon: 'course', permission: 'orders.view' },
  { to: '/admin/activite', label: 'Activité des livreurs', icon: 'livreur', permission: ACTIVITY_TABS.map((t) => t.permission) },
  { to: '/admin/retours', label: 'Retours clients', icon: 'retour', permission: 'orders.dispatch' },
  { to: '/admin/courses/en-retard', label: 'Courses en retard', icon: 'retard', permission: 'orders.dispatch' },
]

export const TEAM_TABS = [
  { to: '/admin/utilisateurs', label: 'Utilisateurs', icon: 'utilisateurs', permission: 'users.view' },
  { to: '/admin/roles', label: 'Rôles', icon: 'securite', permission: 'users.view' },
]
