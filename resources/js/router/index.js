import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { ACTIVITY_TABS } from '../utils/courierActivity'
import { SPACES } from '../roles'
import { allowedInApp, appLock } from '../native'
import { spaceForPath } from '../bootstrap/session'
import { SETTINGS_PERMISSIONS } from '../utils/permissions'

const shared = {
  dashboard: () => import('../views/shared/Dashboard.vue'),
  orders: () => import('../views/shared/OrderList.vue'),
  orderCreate: () => import('../views/shared/OrderCreate.vue'),
  orderDetail: () => import('../views/shared/OrderDetail.vue'),
  payoutDetail: () => import('../views/shared/PayoutDetail.vue'),
  clientInvoice: () => import('../views/shared/ClientInvoiceView.vue'),
}

const mobile = {
  notifications: () => import('../views/mobile/Notifications.vue'),
  profile: () => import('../views/mobile/Profile.vue'),
}

const routes = [
  // Bouton « Connecter WhatsApp » : fenêtre de Meta ouverte depuis l'adresse de l'application (ticket)
  { path: '/connecter-whatsapp', name: 'whatsapp-connect', component: () => import('../views/auth/WhatsAppConnect.vue'), meta: { public: true } },
  // Connexion en un clic d'une application (Jibiat Marchand) au compte du client : la page gère
  // elle-même la session de l'espace client (connexion, ouverture de compte ou demande, autorisation)
  { path: '/marchand/connecter', name: 'app-connect', component: () => import('../views/auth/AppConnect.vue'), meta: { public: true } },
  // Pages de connexion : clients à la racine (page d'accueil du site), équipe et livreurs dans leur espace.
  // Une fois connecté, chacun est renvoyé vers son espace, quelle que soit la page utilisée.
  { path: '/', name: 'home', component: () => import('../views/auth/MerchantHome.vue'), meta: { guest: true } },
  { path: '/admin/connexion', name: 'login-staff', component: () => import('../views/auth/StaffLogin.vue'), meta: { guest: true } },
  // Application client installée : sa connexion reste dans son espace (/marchand), sinon
  // Chrome la confond avec l'application livreur (une application couvrant « / » couvre aussi /livreur)
  { path: '/marchand/connexion', name: 'login-merchant-app', component: () => import('../views/auth/MerchantHome.vue'), meta: { guest: true } },
  { path: '/livreur/connexion', name: 'login-courier', component: () => import('../views/auth/CourierLogin.vue'), meta: { guest: true } },
  // Inscription des clients et mot de passe oublié (numéro vérifié par WhatsApp ou SMS)
  { path: '/inscription', name: 'signup', component: () => import('../views/auth/Signup.vue'), meta: { guest: true } },
  { path: '/mot-de-passe-oublie', name: 'forgot-password', component: () => import('../views/auth/ForgotPassword.vue'), meta: { guest: true } },
  // Ancienne adresse de connexion
  { path: '/login', redirect: (to) => ({ path: '/', query: to.query }) },
  // Pages publiques (destinataire)
  { path: '/b/:slug', name: 'shop', component: () => import('../views/shop/ShopPage.vue'), meta: { public: true } },
  { path: '/suivi/:code?', name: 'tracking', component: () => import('../views/Tracking.vue'), meta: { public: true } },
  {
    path: '/etiquette/:id',
    name: 'label',
    component: () => import('../views/Label.vue'),
    meta: { roles: [...SPACES.admin, ...SPACES.marchand] },
  },
  // Étiquette partagée par l'API publique (Jibiat Marchand) : lien signé, sans connexion
  { path: '/etiquette-partagee/:id(\\d+)/:expires(\\d+)/:token', name: 'shared-label', component: () => import('../views/Label.vue'), meta: { public: true } },
  {
    path: '/etiquettes',
    name: 'labels',
    component: () => import('../views/Label.vue'),
    meta: { roles: [...SPACES.admin, ...SPACES.marchand] },
  },
  {
    path: '/feuille-de-route',
    name: 'route-sheet',
    component: () => import('../views/RouteSheet.vue'),
    meta: { roles: SPACES.admin },
  },
  {
    path: '/bordereau/:id(\\d+)',
    name: 'department-slip',
    component: () => import('../views/DepartmentSlipPrint.vue'),
    meta: { roles: [...SPACES.admin, ...SPACES.marchand] },
  },
  {
    path: '/bon-de-retour/:id',
    name: 'return-slip',
    component: () => import('../views/ReturnSlipPrint.vue'),
    meta: { roles: [...SPACES.admin, ...SPACES.marchand, ...SPACES.livreur] },
  },
  {
    path: '/admin',
    component: () => import('../layouts/AdminLayout.vue'),
    meta: { roles: SPACES.admin },
    children: [
      { path: '', name: 'admin', component: shared.dashboard },
      { path: 'statistiques', component: () => import('../views/admin/Statistics.vue'), meta: { permission: 'orders.view' } },
      { path: 'courses', component: shared.orders, meta: { permission: 'orders.view' } },
      { path: 'courses/nouvelle', component: shared.orderCreate, meta: { permission: 'orders.create' } },
      { path: 'courses/import', component: () => import('../views/admin/OrderImport.vue'), meta: { permission: 'orders.create' } },
      { path: 'courses/:id(\\d+)', component: shared.orderDetail, meta: { permission: 'orders.view' } },
      { path: 'marchands', component: () => import('../views/admin/Merchants.vue'), meta: { permission: 'merchants.view' } },
      { path: 'marchands/:id(\\d+)/departements', component: () => import('../views/admin/MerchantDepartments.vue'), meta: { permission: 'merchants.view' } },
      { path: 'marchands/:id(\\d+)/comptes', component: () => import('../views/admin/MerchantAccountsPage.vue'), meta: { permission: 'merchants.view' } },
      // Activité des livreurs : suivi du jour et, en onglets, carte, disponibles, colis en main, remontées terrain, planning
      {
        path: 'activite',
        component: () => import('../views/admin/activity/CourierActivity.vue'),
        meta: { permission: ACTIVITY_TABS.map((t) => t.permission) },
        children: [
          {
            path: '',
            component: () => import('../views/admin/activity/DailyTracking.vue'),
            meta: { permission: 'orders.dispatch' },
            // Sans le suivi du jour (planning ou remontées seulement) : le premier onglet permis
            beforeEnter: () => {
              const auth = useAuthStore()
              return auth.can('orders.dispatch') ? true : (ACTIVITY_TABS.find((t) => auth.can(t.permission))?.to ?? '/admin')
            },
          },
          { path: 'carte', component: () => import('../views/admin/CourierMap.vue'), meta: { permission: 'orders.dispatch' } },
          { path: 'disponibles', component: () => import('../views/admin/CourierAvailability.vue'), meta: { permission: 'orders.dispatch' } },
          { path: 'colis', component: () => import('../views/admin/HeldParcels.vue'), meta: { permission: 'orders.dispatch' } },
          { path: 'remontees', component: () => import('../views/admin/FieldReports.vue'), meta: { permission: 'field_events.manage' } },
          { path: 'planning', component: () => import('../views/admin/Planning.vue'), meta: { permission: 'planning.manage' } },
        ],
      },
      { path: 'activite/mural', component: () => import('../views/admin/activity/WallScreen.vue'), meta: { permission: 'orders.dispatch' } },
      // Anciennes adresses (liens des notifications, favoris) : vers l'onglet, en gardant les paramètres
      { path: 'carte', redirect: (to) => ({ path: '/admin/activite/carte', query: to.query }) },
      { path: 'disponibilites', redirect: (to) => ({ path: '/admin/activite/disponibles', query: to.query }) },
      { path: 'colis-livreurs', redirect: (to) => ({ path: '/admin/activite/colis', query: to.query }) },
      { path: 'terrain', redirect: (to) => ({ path: '/admin/activite/remontees', query: to.query }) },
      { path: 'planning', redirect: (to) => ({ path: '/admin/activite/planning', query: to.query }) },
      { path: 'retours', component: () => import('../views/admin/MerchantReturns.vue'), meta: { permission: 'orders.dispatch' } },
      { path: 'livreurs', component: () => import('../views/admin/Couriers.vue'), meta: { permission: ['orders.dispatch', 'couriers.manage'] } },
      { path: 'utilisateurs', component: () => import('../views/admin/Users.vue'), meta: { permission: 'users.view' } },
      { path: 'roles', component: () => import('../views/admin/Roles.vue'), meta: { permission: 'users.view' } },
      { path: 'zones', component: () => import('../views/admin/Zones.vue'), meta: { permission: 'zones.manage' } },
      { path: 'tarifs', component: () => import('../views/admin/Pricing.vue'), meta: { permission: 'pricing.manage' } },
      { path: 'paie-livreurs', component: () => import('../views/admin/PayPlans.vue'), meta: { permission: 'pay.manage' } },
      { path: 'stock', component: () => import('../views/admin/Stock.vue'), meta: { permission: 'stock.manage' } },
      { path: 'caisse', component: () => import('../views/admin/Finance.vue'), meta: { permission: 'finance.view' } },
      { path: 'caisse/reversements/:id(\\d+)', component: shared.payoutDetail, meta: { permission: 'finance.view' } },
      // Factures du mois des clients sous formule (distribution, MAD)
      { path: 'factures', component: () => import('../views/admin/ClientInvoices.vue'), meta: { permission: 'invoices.view' } },
      { path: 'factures/:id(\\d+)', component: shared.clientInvoice, meta: { permission: 'invoices.view' } },
      // Chiffre d'affaires par activité (e-commerce, distribution, MAD)
      { path: 'chiffre-affaires', component: () => import('../views/admin/Revenue.vue'), meta: { permission: 'revenue.view' } },
      { path: 'annuaire', component: () => import('../views/admin/Destinations.vue'), meta: { permission: 'merchants.view' } },
      { path: 'contrats', component: () => import('../views/admin/Contracts.vue'), meta: { permission: 'merchants.view' } },
      { path: 'formules', component: () => import('../views/admin/ClientFormulas.vue'), meta: { permission: ['pricing.manage', 'rates.manage'] } },
      // Réglages par thèmes (l'ancienne page « Paramètres » y mène)
      { path: 'mise-en-route', component: () => import('../views/admin/SetupGuide.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages', component: () => import('../views/admin/settings/SettingsHub.vue'), meta: { permission: SETTINGS_PERMISSIONS } },
      { path: 'reglages/entreprise', component: () => import('../views/admin/settings/SettingsCompany.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/courses', component: () => import('../views/admin/settings/SettingsOrders.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/notation', component: () => import('../views/admin/settings/SettingsRatings.vue'), meta: { permission: 'ratings.manage' } },
      { path: 'reglages/evenements-terrain', component: () => import('../views/admin/settings/SettingsFieldEvents.vue'), meta: { permission: 'field_events.manage' } },
      { path: 'vehicules', component: () => import('../views/admin/Vehicles.vue'), meta: { permission: 'fleet.manage' } },
      { path: 'vehicules/echeances', component: () => import('../views/admin/VehicleDeadlines.vue'), meta: { permission: 'fleet.manage' } },
      { path: 'vehicules/:id(\\d+)', component: () => import('../views/admin/VehicleDetail.vue'), meta: { permission: 'fleet.manage' } },
      { path: 'reglages/annuaire', component: () => import('../views/admin/settings/SettingsDestinations.vue'), meta: { permission: 'destinations.manage' } },
      { path: 'reglages/departements', component: () => import('../views/admin/settings/SettingsDepartments.vue'), meta: { permission: 'departments.manage' } },
      { path: 'reglages/vehicules', component: () => import('../views/admin/settings/SettingsVehicles.vue'), meta: { permission: 'fleet.manage' } },
      { path: 'qualite-livreurs', component: () => import('../views/admin/CourierQuality.vue'), meta: { permission: ['orders.dispatch', 'ratings.manage'] } },
      { path: 'reglages/facturation', component: () => import('../views/admin/settings/SettingsInvoicing.vue'), meta: { permission: ['settings.manage', 'invoices.manage'] } },
      { path: 'reglages/motifs', component: () => import('../views/admin/settings/SettingsReasons.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/alertes', component: () => import('../views/admin/settings/SettingsAlerts.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/e-commercants', component: () => import('../views/admin/settings/SettingsMerchants.vue'), meta: { permission: 'settings.manage' } },
      { path: 'reglages/reprise', component: () => import('../views/admin/settings/SettingsLegacy.vue'), meta: { permission: 'settings.manage' } },
      { path: 'parametres', redirect: '/admin/reglages' },
      { path: 'integrations', component: () => import('../views/admin/Integrations.vue'), meta: { permission: 'integrations.manage' } },
      { path: 'whatsapp', component: () => import('../views/admin/WhatsApp.vue'), meta: { permission: 'messaging.manage' } },
      { path: 'whatsapp/simulateur', component: () => import('../views/admin/WhatsAppSimulator.vue'), meta: { permission: 'messaging.manage' } },
      { path: 'messages', component: () => import('../views/admin/Messages.vue'), meta: { permission: 'orders.dispatch' } },
    ],
  },
  // Application livreur (PWA mobile)
  {
    path: '/livreur',
    component: () => import('../views/courier/CourierApp.vue'),
    meta: { roles: SPACES.livreur },
    children: [
      { path: '', name: 'livreur', component: () => import('../views/courier/Missions.vue'), meta: { title: 'Mes missions' } },
      { path: 'missions/:id(\\d+)', component: () => import('../views/courier/MissionDetail.vue'), meta: { title: 'Mission', back: true } },
      { path: 'ramassage/:key', component: () => import('../views/courier/PickupGroup.vue'), meta: { title: 'Ramassage', back: true } },
      { path: 'stock', component: () => import('../views/admin/Stock.vue'), meta: { permission: 'stock.manage' } },
      { path: 'caisse', component: () => import('../views/courier/Wallet.vue'), meta: { title: 'Ma caisse' } },
      { path: 'messages', component: () => import('../views/courier/Messages.vue'), meta: { title: 'Consignes de l\'agence', back: true } },
      { path: 'retours/:id(\\d+)', component: () => import('../views/courier/ReturnHandOver.vue'), meta: { title: 'Remise au client', back: true } },
      { path: 'evenements/:id(\\d+)', component: () => import('../views/courier/FieldEvent.vue'), meta: { title: 'Mon signalement', back: true } },
      { path: 'vehicule', component: () => import('../views/courier/MyVehicle.vue'), meta: { title: 'Mon véhicule', back: true } },
      { path: 'vehicule/etat-des-lieux', component: () => import('../views/courier/VehicleCheck.vue'), meta: { title: 'État des lieux', back: true } },
      { path: 'notifications', component: mobile.notifications, meta: { title: 'Notifications', back: true } },
      { path: 'profil', component: mobile.profile, meta: { title: 'Mon profil' } },
      // Les notifications pointent vers /livreur/courses/:id : on renvoie vers la liste des missions
      { path: 'courses/:id', redirect: '/livreur' },
    ],
  },
  // Application client (PWA mobile)
  {
    path: '/marchand',
    component: () => import('../views/merchant/MerchantApp.vue'),
    meta: { roles: SPACES.marchand },
    children: [
      { path: '', name: 'marchand', component: () => import('../views/merchant/Home.vue') },
      { path: 'courses', component: () => import('../views/merchant/Orders.vue'), meta: { title: 'Mes courses' } },
      { path: 'statistiques', component: () => import('../views/merchant/Stats.vue'), meta: { title: 'Mes statistiques', back: true } },
      { path: 'courses/nouvelle', component: () => import('../views/merchant/NewOrder.vue'), meta: { title: 'Nouvelle course', back: true, permission: 'orders.create', hideTabs: true } },
      { path: 'courses/import', component: () => import('../views/merchant/OrderImport.vue'), meta: { title: 'Importer des courses', back: true, permission: 'orders.create', hideTabs: true } },
      { path: 'boutique', component: () => import('../views/merchant/Shop.vue'), meta: { title: 'Ma boutique en ligne', back: true, permission: 'integrations.manage' } },
      { path: 'integrations', component: () => import('../views/merchant/Integrations.vue'), meta: { title: 'Intégrations', back: true, permission: 'integrations.manage' } },
      { path: 'courses/:id(\\d+)', component: () => import('../views/merchant/OrderView.vue'), meta: { title: 'Course', back: true } },
      { path: 'stock', component: () => import('../views/merchant/Stock.vue'), meta: { title: 'Mon stock' } },
      { path: 'departements', component: () => import('../views/merchant/Departments.vue'), meta: { title: 'Mes départements', back: true } },
      { path: 'equipe', component: () => import('../views/merchant/Team.vue'), meta: { title: 'Mon équipe', back: true, roles: ['merchant_owner', 'merchant_manager'] } },
      { path: 'lieu-de-ramassage', component: () => import('../views/merchant/PickupLocation.vue'), meta: { title: 'Lieu de ramassage', back: true } },
      { path: 'paiements', component: () => import('../views/merchant/Payments.vue'), meta: { title: 'Mon argent', permission: 'finance.view' } },
      { path: 'paiements/:id(\\d+)', component: () => import('../views/merchant/PayoutView.vue'), meta: { title: 'Relevé', back: true, permission: 'finance.view' } },
      { path: 'factures', component: () => import('../views/merchant/Invoices.vue'), meta: { title: 'Mes factures', back: true, permission: 'finance.view' } },
      { path: 'factures/:id(\\d+)', component: shared.clientInvoice, meta: { title: 'Facture', back: true, permission: 'finance.view' } },
      { path: 'notifications', component: mobile.notifications, meta: { title: 'Notifications', back: true } },
      { path: 'a-noter', component: () => import('../views/merchant/RateCourses.vue'), meta: { title: 'Noter les livreurs', back: true } },
      { path: 'mes-livreurs', component: () => import('../views/merchant/MyCouriers.vue'), meta: { title: 'Mes livreurs', back: true } },
      { path: 'mes-notifications', component: () => import('../views/merchant/NotificationSettings.vue'), meta: { title: 'Mes notifications', back: true } },
      { path: 'profil', component: mobile.profile, meta: { title: 'Mon profil' } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// Page de connexion de l'espace demandé, avec retour à la page voulue après connexion
function loginFor(to) {
  const login = appLock()?.login || (to.path.startsWith('/admin') ? '/admin/connexion' : to.path.startsWith('/livreur') ? '/livreur/connexion' : '/')
  return { path: login, query: to.path !== login && !['/admin', '/livreur', '/marchand'].includes(to.path) ? { redirect: to.fullPath } : {} }
}

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // Application (Android, ou installée depuis le navigateur) : seulement les pages de son espace
  const lock = appLock()
  if (lock) {
    if (!allowedInApp(to.path)) return auth.isAuthenticated ? lock.home : lock.login
    if (to.path === '/' && lock.login !== '/') return auth.isAuthenticated ? lock.home : lock.login
  }

  // Chaque espace a sa propre session (client, livreur, équipe)
  auth.useSpace(spaceForPath(to.path))

  if (to.meta.public) return true

  if (auth.isAuthenticated && !auth.user) {
    try {
      await auth.fetchUser()
    } catch {
      auth.clear()
    }
  }

  if (!auth.user) {
    return to.meta.guest ? true : loginFor(to)
  }

  // Rôles autorisés : on regarde toute la chaîne de routes (layout + page)
  const roles = to.matched.map((r) => r.meta.roles).filter(Boolean).at(-1)
  const permission = to.meta.permission

  if (to.meta.guest || to.path === '/' || (roles && !roles.includes(auth.role)) || (permission && !auth.can(permission))) {
    return auth.homeRoute === to.path ? true : auth.homeRoute
  }

  return true
})

// Nouvelle version mise en ligne pendant que la page était ouverte : les fichiers des pages pas encore visitées
// ont changé de nom et l'ancien n'existe plus. Sans cela, le clic dans le menu ne fait rien jusqu'au rafraîchissement.
// On recharge donc la page demandée (une seule fois par minute, pour ne jamais tourner en boucle).
const RELOAD_KEY = 'reloaded-for-new-version'
function reloadForNewVersion(path) {
  try {
    const last = Number(sessionStorage.getItem(RELOAD_KEY) || 0)
    if (Date.now() - last < 60000) return false
    sessionStorage.setItem(RELOAD_KEY, String(Date.now()))
  } catch {
    // Stockage indisponible : on recharge quand même
  }
  window.location.assign(path)
  return true
}
const isStaleChunk = (error) => /dynamically imported module|Importing a module script failed|error loading dynamically imported module|Failed to load module script/i.test(error?.message || '')

router.onError((error, to) => {
  if (isStaleChunk(error) && reloadForNewVersion(to?.fullPath || window.location.pathname)) return
  console.error(error)
})
export default router
