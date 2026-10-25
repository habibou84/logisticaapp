import { SETTINGS_PERMISSIONS } from './permissions'

/**
 * Menu de l'espace équipe, en rubriques. Partagé par le menu lui-même et par l'aperçu « Voir comme ce compte »
 * (Utilisateurs) : une entrée sans droit ni activité disparaît, une rubrique vide aussi.
 *
 * @param badges compteurs affichés à côté des entrées (absents dans l'aperçu)
 */
export function adminMenuGroups(badges = {}) {
  return [
    {
      id: 'operations',
      label: 'Opérations',
      items: [
        { to: '/admin/courses', icon: '📦', label: 'Courses', permission: 'orders.view', badge: badges.late },
        { to: '/admin/carte', icon: '📍', label: 'Carte des livreurs', permission: 'orders.dispatch' },
        { to: '/admin/disponibilites', icon: '🟢', label: 'Livreurs disponibles', permission: 'orders.dispatch' },
        { to: '/admin/planning', icon: '📅', label: 'Planning des livreurs', permission: 'planning.manage' },
        { to: '/admin/terrain', icon: '📣', label: 'Remontées terrain', permission: 'field_events.manage', badge: badges.fieldReports },
        { to: '/admin/colis-livreurs', icon: '🎒', label: 'Colis chez les livreurs', permission: 'orders.dispatch', badge: badges.heldParcels },
        { to: '/admin/retours', icon: '📮', label: 'Retours clients', permission: 'orders.dispatch' },
      ],
    },
    {
      id: 'finances',
      label: 'Finances',
      items: [
        { to: '/admin/caisse', icon: '💰', label: 'Caisse', permission: 'finance.view' },
        { to: '/admin/chiffre-affaires', icon: '📈', label: 'Chiffre d\'affaires', permission: 'revenue.view' },
        { to: '/admin/paie-livreurs', icon: '🧾', label: 'Paie des livreurs', permission: 'pay.manage' },
      ],
    },
    {
      // Clients sous contrat : forfait du mois, tarifs, factures
      id: 'contrats',
      label: 'Distribution et MAD',
      items: [
        { to: '/admin/contrats', icon: '📑', label: 'Contrats', permission: 'merchants.view', activities: ['distribution', 'mad'] },
        { to: '/admin/annuaire', icon: '📇', label: 'Annuaire', permission: 'merchants.view', activities: ['distribution', 'mad'] },
        { to: '/admin/formules', icon: '🧮', label: 'Tarifs', permission: ['pricing.manage', 'rates.manage'], activities: ['distribution', 'mad'] },
        { to: '/admin/factures', icon: '📄', label: 'Factures', permission: 'invoices.view', activities: ['distribution', 'mad'] },
      ],
    },
    {
      id: 'clients',
      label: 'Clients',
      items: [
        { to: '/admin/marchands', icon: '🏪', label: 'Clients', permission: 'merchants.view' },
        { to: '/admin/stock', icon: '🏬', label: 'Stock', permission: 'stock.manage' },
        { to: '/admin/messages', icon: '💬', label: 'Messages', permission: 'orders.dispatch' },
      ],
    },
    {
      id: 'equipe',
      label: 'Équipe',
      items: [
        { to: '/admin/livreurs', icon: '🛵', label: 'Livreurs', permission: ['orders.dispatch', 'couriers.manage'] },
        { to: '/admin/vehicules', icon: '🏍️', label: 'Véhicules', permission: 'fleet.manage', badge: badges.vehicles },
        { to: '/admin/qualite-livreurs', icon: '⭐', label: 'Qualité des livreurs', permission: ['orders.dispatch', 'ratings.manage'] },
        { to: '/admin/utilisateurs', icon: '👥', label: 'Utilisateurs', permission: 'users.view' },
      ],
    },
    {
      id: 'configuration',
      label: 'Configuration',
      // Zones, tarifs, WhatsApp, intégrations… : tout passe par la page Réglages, rangée par thèmes
      items: [
        // Point rouge : alertes du bilan de santé
        { to: '/admin/reglages', icon: '⚙️', label: 'Réglages', permission: SETTINGS_PERMISSIONS, badge: badges.health },
      ],
    },
  ]
}

export const adminMenuTop = () => [
  { to: '/admin', icon: '🏠', label: 'Tableau de bord' },
  { to: '/admin/statistiques', icon: '📊', label: 'Statistiques', permission: 'orders.view' },
]

/**
 * Entrées visibles pour un jeu de permissions et d'activités.
 *
 * @param can (permission | liste) => bool
 * @param activities valeurs des activités vues par le compte
 */
export function filterMenu(can, activities, badges = {}) {
  const visible = (item) => (!item.permission || can(item.permission))
    && (!item.activities || activities.some((a) => item.activities.includes(a)))

  return {
    top: adminMenuTop().filter(visible),
    groups: adminMenuGroups(badges).map((g) => ({ ...g, items: g.items.filter(visible) })).filter((g) => g.items.length),
  }
}
