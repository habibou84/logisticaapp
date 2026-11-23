// Pages réunies sous une seule entrée du menu, en onglets (menu et composant PageTabs) : chacune avec son droit
export const FLEET_TABS = [
  { to: '/admin/vehicules', label: '🏍️ Véhicules', permission: 'fleet.manage' },
  { to: '/admin/atelier', label: '🛠️ Atelier', permission: ['repairs.manage', 'repairs.purchases'] },
]

export const TEAM_TABS = [
  { to: '/admin/utilisateurs', label: '👥 Utilisateurs', permission: 'users.view' },
  { to: '/admin/roles', label: '🔐 Rôles', permission: 'users.view' },
]
