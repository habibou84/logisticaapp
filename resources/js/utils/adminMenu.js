import { SETTINGS_PERMISSIONS } from './permissions'
import { COURSES_PAGES, FLEET_TABS, TEAM_TABS } from './pageTabs'

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
        // Une entrée : courses, activité des livreurs, retours clients et courses en retard, en cartes en haut des pages
        { icon: 'course', label: 'Courses', routes: COURSES_PAGES, badge: (badges.late || 0) + (badges.untreated || 0) + (badges.fieldReports || 0) + (badges.heldParcels || 0) },
      ],
    },
    {
      // Où on livre et à quel prix, ce qui se fait tout seul, qui travaille où et quand
      id: 'livraison',
      label: 'Livraison',
      items: [
        // Communes, prix au départ, suppléments, grilles négociées, tarifs de la distribution et de la MAD
        { to: '/admin/zones', icon: 'carte', label: 'Zones et tarifs', permission: ['zones.manage', 'pricing.manage', 'rates.manage'] },
        { to: '/admin/automatisation', icon: 'automatisation', label: 'Automatisation', permission: 'settings.manage' },
        { to: '/admin/activite/planning', icon: 'planning', label: 'Planning des livreurs', permission: 'planning.manage' },
        // Routine du matin des livreurs en mise à disposition, et lieu où ils pointent
        { to: '/admin/parcours', icon: 'activite', label: 'Parcours', permission: ['rates.manage', 'orders.dispatch', 'merchants.view'], activities: ['mad'], tags: ['mad'] },
      ],
    },
    {
      id: 'clients',
      label: 'Clients',
      items: [
        { to: '/admin/marchands', icon: 'clients', label: 'Clients', permission: 'merchants.view' },
        // Clients sous contrat : forfait du mois, zones couvertes, livreurs attitrés
        { to: '/admin/contrats', icon: 'contrat', label: 'Contrats', permission: 'merchants.view', activities: DM, tags: DM },
        { to: '/admin/annuaire', icon: 'annuaire', label: 'Annuaire', permission: 'merchants.view', activities: DM, tags: DM },
        { to: '/admin/stock', icon: 'stock', label: 'Stock', permission: 'stock.manage', tags: ['ecommerce'] },
      ],
    },
    {
      id: 'finances',
      label: 'Finances',
      items: [
        { to: '/admin/caisse', icon: 'caisse', label: 'Caisse', permission: 'finance.view' },
        { to: '/admin/factures', icon: 'facture', label: 'Factures', permission: 'invoices.view', activities: DM, tags: DM },
        { to: '/admin/chiffre-affaires', icon: 'tendance', label: 'Chiffre d\'affaires', permission: 'revenue.view' },
        // Loyer, équipe, téléphone… : comptés dans la marge nette des statistiques
        { to: '/admin/frais-fixes', icon: 'facture', label: 'Frais fixes', permission: 'fixed_costs.manage' },
        { to: '/admin/paie-livreurs', icon: 'paie', label: 'Paie des livreurs', permission: 'pay.manage' },
      ],
    },
    {
      id: 'messages',
      label: 'Messages',
      items: [
        { to: '/admin/messages', icon: 'message', label: 'Messages', permission: 'orders.dispatch', badge: badges.inbox },
        // Connexion du numéro, messages automatiques, options d'envoi (l'entrée reste allumée sur ses onglets)
        { to: '/admin/whatsapp', icon: 'whatsapp', label: 'WhatsApp', permission: 'messaging.manage', also: ['/admin/whatsapp'] },
      ],
    },
    {
      id: 'equipe',
      label: 'Équipe',
      items: [
        { to: '/admin/livreurs', icon: 'livreur', label: 'Livreurs', permission: ['orders.dispatch', 'couriers.manage', 'ratings.manage'] },
        // Une entrée, deux onglets (utils/pageTabs) : elle mène au premier onglet permis
        { icon: 'vehicule', label: 'Véhicules et atelier', routes: FLEET_TABS, badge: (badges.vehicles || 0) + (badges.workshop || 0) },
        { icon: 'utilisateurs', label: 'Utilisateurs et rôles', routes: TEAM_TABS },
      ],
    },
    {
      id: 'configuration',
      label: 'Configuration',
      // Intégrations, entreprise, motifs… : la page Réglages, rangée par thèmes
      items: [
        // Point rouge : alertes du bilan de santé
        { to: '/admin/reglages', icon: 'reglages', label: 'Réglages', permission: SETTINGS_PERMISSIONS, badge: badges.health },
      ],
    },
  ]
}

// Poste de pilotage pour le dispatch (à faire maintenant, point du jour), tableau de bord pour les autres
export const adminMenuTop = (can = () => true) => [
  { to: '/admin', icon: can('orders.dispatch') ? 'pilotage' : 'accueil', label: can('orders.dispatch') ? 'Poste de pilotage' : 'Tableau de bord' },
  { to: '/admin/statistiques', icon: 'statistiques', label: 'Statistiques', permission: 'orders.view' },
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
    if (!tabs.length) return null
    // Première page pas permise : l'entrée prend le nom et l'icône de celle où elle mène
    const first = tabs[0] === item.routes[0] ? {} : { label: tabs[0].label, ...(tabs[0].icon ? { icon: tabs[0].icon } : {}) }
    return { ...item, ...first, to: tabs[0].to, also: tabs.map((t) => t.to) }
  }

  return {
    top: adminMenuTop(can).map(resolve).filter(Boolean),
    groups: adminMenuGroups(badges).map((g) => ({ ...g, items: g.items.map(resolve).filter(Boolean) })).filter((g) => g.items.length),
  }
}
