import { SETTINGS_PERMISSIONS } from './permissions'
import { ACTIVITY_TABS } from './courierActivity'
import { FLEET_TABS, TEAM_TABS } from './pageTabs'

/**
 * Menu de l'espace équipe, en rubriques. Partagé par le menu lui-même et par l'aperçu « Voir comme ce compte »
 * (Utilisateurs) : une entrée sans droit ni activité disparaît, une rubrique vide aussi.
 *
 * @param badges compteurs affichés à côté des entrées (absents dans l'aperçu)
 */
/** Pastilles d'activité d'une entrée : la page ne sert qu'à ces activités (couleurs de utils/activities) */
const DM = ['distribution', 'mad']

export function adminMenuGroups(badges = {}) {
  return [
    {
      id: 'operations',
      label: 'Opérations',
      items: [
        { to: '/admin/courses', icon: '📦', label: 'Courses', permission: 'orders.view', badge: badges.late },
        // Jour de livraison dépassé : pas ramassées (à reporter), ramassées pas livrées, toutes, en onglets
        { to: '/admin/courses/en-retard', icon: '⏳', label: 'Courses en retard', permission: 'orders.dispatch', badge: badges.untreated },
        // Suivi du jour, carte, disponibles, colis en main, remontées terrain et planning, en onglets
        { to: '/admin/activite', icon: '🛵', label: 'Activité des livreurs', permission: ACTIVITY_TABS.map((t) => t.permission), badge: (badges.fieldReports || 0) + (badges.heldParcels || 0) },
        { to: '/admin/retours', icon: '📮', label: 'Retours clients', permission: 'orders.dispatch' },
      ],
    },
    {
      // Où on livre et à quel prix, ce qui se fait tout seul, qui travaille où et quand
      id: 'livraison',
      label: 'Livraison',
      items: [
        { to: '/admin/zones', icon: '🗺️', label: 'Zones', permission: 'zones.manage' },
        { to: '/admin/tarifs', icon: '🏷️', label: 'Tarifs de livraison', permission: 'pricing.manage' },
        // Distribution à l'unité, villes de l'intérieur, prix négociés
        { to: '/admin/formules', icon: '🧮', label: 'Tarifs', permission: ['pricing.manage', 'rates.manage'], activities: DM, tags: DM },
        { to: '/admin/automatisation', icon: '⚡', label: 'Automatisation', permission: 'settings.manage' },
        { to: '/admin/activite/planning', icon: '🗓️', label: 'Planning des livreurs', permission: 'planning.manage' },
        // Routine du matin des livreurs en mise à disposition, et lieu où ils pointent
        { to: '/admin/parcours', icon: '🧭', label: 'Parcours', permission: ['rates.manage', 'orders.dispatch', 'merchants.view'], activities: ['mad'], tags: ['mad'] },
      ],
    },
    {
      id: 'clients',
      label: 'Clients',
      items: [
        { to: '/admin/marchands', icon: '🏪', label: 'Clients', permission: 'merchants.view' },
        // Clients sous contrat : forfait du mois, zones couvertes, livreurs attitrés
        { to: '/admin/contrats', icon: '📑', label: 'Contrats', permission: 'merchants.view', activities: DM, tags: DM },
        { to: '/admin/annuaire', icon: '📇', label: 'Annuaire', permission: 'merchants.view', activities: DM, tags: DM },
        { to: '/admin/stock', icon: '🏬', label: 'Stock', permission: 'stock.manage', tags: ['ecommerce'] },
      ],
    },
    {
      id: 'finances',
      label: 'Finances',
      items: [
        { to: '/admin/caisse', icon: '💰', label: 'Caisse', permission: 'finance.view' },
        { to: '/admin/factures', icon: '📄', label: 'Factures', permission: 'invoices.view', activities: DM, tags: DM },
        { to: '/admin/chiffre-affaires', icon: '📈', label: 'Chiffre d\'affaires', permission: 'revenue.view' },
        { to: '/admin/paie-livreurs', icon: '🧾', label: 'Paie des livreurs', permission: 'pay.manage' },
      ],
    },
    {
      id: 'messages',
      label: 'Messages',
      items: [
        { to: '/admin/messages', icon: '💬', label: 'Messages', permission: 'orders.dispatch' },
        // Connexion du numéro et messages prédéfinis
        { to: '/admin/whatsapp', icon: '🟢', label: 'WhatsApp', permission: 'messaging.manage' },
      ],
    },
    {
      id: 'equipe',
      label: 'Équipe',
      items: [
        { to: '/admin/livreurs', icon: '🛵', label: 'Livreurs', permission: ['orders.dispatch', 'couriers.manage', 'ratings.manage'] },
        // Une entrée, deux onglets (utils/pageTabs) : elle mène au premier onglet permis
        { icon: '🏍️', label: 'Véhicules et atelier', routes: FLEET_TABS, badge: (badges.vehicles || 0) + (badges.workshop || 0) },
        { icon: '👥', label: 'Utilisateurs et rôles', routes: TEAM_TABS },
      ],
    },
    {
      id: 'configuration',
      label: 'Configuration',
      // Intégrations, entreprise, motifs… : la page Réglages, rangée par thèmes
      items: [
        // Point rouge : alertes du bilan de santé
        { to: '/admin/reglages', icon: '⚙️', label: 'Réglages', permission: SETTINGS_PERMISSIONS, badge: badges.health },
      ],
    },
  ]
}

// Poste de pilotage pour le dispatch (à faire maintenant, point du jour), tableau de bord pour les autres
export const adminMenuTop = (can = () => true) => [
  { to: '/admin', icon: can('orders.dispatch') ? '🎛️' : '🏠', label: can('orders.dispatch') ? 'Poste de pilotage' : 'Tableau de bord' },
  { to: '/admin/statistiques', icon: '📊', label: 'Statistiques', permission: 'orders.view' },
]

/**
 * Entrées visibles pour un jeu de permissions et d'activités. Une entrée à onglets (routes) mène au premier
 * onglet permis et reste allumée sur les autres (also).
 *
 * @param can (permission | liste) => bool
 * @param activities valeurs des activités vues par le compte
 */
export function filterMenu(can, activities, badges = {}) {
  const allowed = (item) => (!item.permission || can(item.permission))
    && (!item.activities || activities.some((a) => item.activities.includes(a)))
  const resolve = (item) => {
    if (!item.routes) return allowed(item) ? item : null
    const tabs = item.routes.filter(allowed)
    return tabs.length ? { ...item, to: tabs[0].to, also: tabs.map((t) => t.to) } : null
  }

  return {
    top: adminMenuTop(can).map(resolve).filter(Boolean),
    groups: adminMenuGroups(badges).map((g) => ({ ...g, items: g.items.map(resolve).filter(Boolean) })).filter((g) => g.items.length),
  }
}
