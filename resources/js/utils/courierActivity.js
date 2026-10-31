// Onglets de l'activité des livreurs (menu et routeur) : chacun avec son droit
export const ACTIVITY_TABS = [
  { to: '/admin/activite', label: 'Suivi du jour', exact: true, permission: 'orders.dispatch' },
  { to: '/admin/activite/carte', label: 'Carte', permission: 'orders.dispatch' },
  { to: '/admin/activite/disponibles', label: 'Disponibles', permission: 'orders.dispatch' },
  { to: '/admin/activite/colis', label: 'Colis en main', permission: 'orders.dispatch' },
  { to: '/admin/activite/remontees', label: 'Remontées terrain', permission: 'field_events.manage' },
  { to: '/admin/activite/planning', label: 'Planning', permission: 'planning.manage' },
]
