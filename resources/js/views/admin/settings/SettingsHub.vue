<template>
  <div class="max-w-4xl space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">⚙️ Réglages</h1>
        <p class="text-sm text-gray-600">Tous les réglages de l'entreprise, rangés par thème.</p>
      </div>
      <input v-model="query" type="search" class="input sm:w-72" placeholder="Chercher un réglage (heure limite, logo…)" aria-label="Chercher un réglage">
    </div>

    <!-- Parcours de mise en route -->
    <RouterLink v-if="setup && !query" to="/admin/mise-en-route" :class="['card p-4 flex items-center gap-3 hover:border-slate-400 transition', setup.ready && !setup.health.length ? '' : 'border-amber-300 bg-amber-50']">
      <span class="text-2xl leading-none" aria-hidden="true">🧭</span>
      <span class="min-w-0 flex-1">
        <span class="block font-semibold">Mise en route</span>
        <span class="block text-sm text-gray-600">
          {{ setup.ready ? 'Étapes essentielles faites' : `Étapes essentielles : ${setup.essentials.done} sur ${setup.essentials.total}` }}
          · conseillées : {{ setup.recommended.done }} sur {{ setup.recommended.total }}
        </span>
        <span v-if="setup.health.length" class="block text-sm font-medium text-red-700">Bilan de santé : {{ setup.health.length }} point(s) à corriger</span>
      </span>
      <span class="text-sm font-medium text-blue-600 whitespace-nowrap">{{ setup.ready ? 'Revoir' : 'Continuer' }} →</span>
    </RouterLink>

    <p v-if="!cards.length" class="card p-6 text-center text-gray-500">Aucun réglage ne correspond à « {{ query }} ».</p>

    <div class="grid gap-3 sm:grid-cols-2">
      <component
        :is="card.to ? 'RouterLink' : 'div'"
        v-for="card in cards"
        :key="card.key"
        :to="card.to"
        :class="['card p-4 flex gap-3', card.to ? 'hover:border-slate-400 transition' : '']"
      >
        <span class="text-2xl leading-none" aria-hidden="true">{{ card.icon }}</span>
        <span class="min-w-0 flex-1 space-y-1">
          <span class="block font-semibold">{{ card.title }}</span>
          <span class="block text-sm text-gray-600">{{ card.description }}</span>
          <span v-if="card.summary" class="block text-xs font-medium text-slate-700">{{ card.summary }}</span>
          <span v-if="card.links" class="flex flex-wrap gap-x-3 gap-y-1 pt-1">
            <RouterLink v-for="l in card.links" :key="l.to" :to="l.to" class="text-sm font-medium text-blue-600">{{ l.label }} →</RouterLink>
          </span>
        </span>
      </component>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '../../../bootstrap/axios'
import { useAuthStore } from '../../../stores/auth'
import { ACCEPT_MODES, AUTO_MODES, DELIVERY_MODES, delay, VALIDATION_MODES } from './options'

/**
 * Page d'entrée des réglages : une carte par thème, avec l'essentiel de l'état actuel, et une recherche.
 * Une carte ou un lien sans le droit ou le module correspondant n'apparaît pas.
 */
const auth = useAuthStore()
const company = ref(null)
const query = ref('')
const setup = ref(null)

const allowed = (item) => !item.permission || auth.can(item.permission)
const onOff = (v) => (v ? 'oui' : 'non')

const ALL = computed(() => {
  const c = company.value
  const mode = VALIDATION_MODES.find((m) => m.value === c?.validation_mode)
  return [
    {
      key: 'company', icon: '🏢', title: 'Entreprise et marque', to: '/admin/reglages/entreprise', permission: 'settings.manage',
      description: 'Nom, coordonnées, logo, couleurs et icônes des applications, adresse de l\'espace, guide vidéo.',
      summary: c && `${c.name}${c.logo_url ? ' · logo en place' : ' · sans logo'} · guide vidéo : ${onOff(c.guide_videos)}`,
      keywords: 'nom téléphone adresse accroche logo couleurs apparence icône application domaine guide vidéo bien démarrer tutoriel',
    },
    {
      key: 'orders', icon: '📦', title: 'Courses et livraison', to: '/admin/reglages/courses', permission: 'settings.manage',
      description: 'Validation des courses, heure limite, tentatives, code de livraison, hors connexion, garde ou dépôt.',
      summary: c && `Validation ${mode?.short || '—'} · heure limite ${c.daily_cutoff_time?.slice(0, 5) || 'aucune'} · ${c.default_max_attempts} tentatives · ramassage automatique ${AUTO_MODES.find((m) => m.value === c.auto_pickup)?.short || 'désactivé'} · livraison automatique ${DELIVERY_MODES.find((m) => m.value === c.auto_delivery)?.short || 'désactivée'} · missions ${ACCEPT_MODES.find((m) => m.value === c.mission_acceptance)?.short || 'au choix du livreur'}`,
      keywords: 'validation heure limite journée tentatives code livraison hors connexion offline garde dépôt fermeture assignation automatique planning acceptation missions refus empêchement injoignable retard livraison jour même plafond',
    },
    {
      key: 'pricing', icon: '🗺️', title: 'Zones et tarifs',
      description: 'Zones desservies, grilles de prix, suppléments, frais des colis retournés.',
      summary: c && `Frais d'un retour : ${c.return_fee_percent} % des frais de livraison`,
      links: [{ to: '/admin/zones', label: 'Zones', permission: 'zones.manage' }, { to: '/admin/tarifs', label: 'Tarifs et retours', permission: 'pricing.manage' }],
      keywords: 'zones communes quartiers tarifs grille prix suppléments express fragile poids frais retour distribution intérieur villes pli colis chèque délai',
    },
    {
      key: 'alerts', icon: '⏰', title: 'Alertes et rappels', to: '/admin/reglages/alertes', permission: 'settings.manage',
      description: 'Quand prévenir le dispatch : courses sans livreur, colis gardés, remontées terrain, point de la semaine.',
      summary: c && `Ramassage ${delay(c.pickup_assign_alert_minutes)} · livraison ${delay(c.delivery_assign_alert_minutes)} · point de la semaine : ${onOff(c.weekly_report)}`,
      keywords: 'alerte délai sans livreur colis gardé livreur relance remontées terrain point semaine rapport',
    },
    {
      key: 'reasons', icon: '📝', title: 'Motifs et notes de suivi', to: '/admin/reglages/motifs', permission: 'settings.manage',
      description: 'Motifs de non-livraison et de ramassage du livreur (par famille et par activité), notes de suivi de l\'équipe.',
      keywords: 'motifs non livraison échec report injoignable absent fermé documents cachet signature tva retour note suivi attente réceptionne annuaire photo',
    },
    {
      key: 'messages', icon: '💬', title: 'Messages aux clients', to: '/admin/whatsapp', permission: 'messaging.manage',
      description: 'WhatsApp de l\'entreprise : numéro, envois, modèles, test ; notifications sur téléphone des clients.',
      keywords: 'whatsapp sms messages modèles envois test notifications push téléphone',
    },
    {
      key: 'merchants', icon: '🏪', title: 'Clients', to: '/admin/reglages/e-commercants', permission: 'settings.manage',
      description: 'Inscriptions en ligne des clients.',
      summary: c && (c.merchant_signup ? 'Inscriptions en ligne ouvertes' : 'Inscriptions en ligne fermées'),
      keywords: 'inscription en ligne créer compte client',
    },
    {
      key: 'ratings', icon: '⭐', title: 'Notation des livreurs', to: '/admin/reglages/notation', permission: 'ratings.manage',
      description: 'Notes des clients, de l\'équipe et automatique ; poids, alertes, ce que voit le livreur, choix des livreurs par les clients.',
      summary: c?.rating_settings && `Automatique ${c.rating_settings.weight_auto} % · clients ${c.rating_settings.weight_client} % · équipe ${c.rating_settings.weight_team} %`,
      keywords: 'note notation étoiles avis qualité livreur classement',
    },
    {
      key: 'field-events', icon: '🚨', title: 'Événements terrain', to: '/admin/reglages/evenements-terrain', permission: 'field_events.manage',
      description: 'Ce que les livreurs signalent sur la route (panne, accident, contrôle…) et les effets automatiques : pause, courses retirées, clients prévenus.',
      summary: c?.field_event_settings && (c.field_event_settings.enabled ? `${c.field_event_settings.types.filter((t) => t.active).length} types proposés · mise hors service : ${onOff(c.field_event_settings.pause_courier)} · clients prévenus : ${onOff(c.field_event_settings.notify_clients)}` : 'Désactivé'),
      keywords: 'événement terrain panne accident contrôle police vol agression météo signaler incident route pause',
    },
    {
      key: 'destinations', icon: '📇', title: 'Annuaire des destinations', to: '/admin/reglages/annuaire', permission: 'destinations.manage',
      description: 'Suggestions pendant la saisie, enrichissement par les courses, position GPS confirmée par les livreurs.',
      summary: c?.destination_settings && (c.destination_settings.enabled ? `Clients : ${onOff(c.destination_settings.clients_see)} · position ${{ free: 'au choix du livreur', prompt: 'proposée à chaque livraison', required: 'obligatoire si absente' }[c.destination_settings.courier_locate]}` : 'Désactivé'),
      keywords: 'annuaire destination suggestion adresse gps position livreur base clients numéro vérifier',
    },
    {
      key: 'departments', icon: '🏢', title: 'Départements des clients', to: '/admin/reglages/departements', permission: 'departments.manage',
      description: 'Banques, administrations : départements sur les courses, courrier interne, qui remet et qui reçoit, bordereaux, facture ventilée, quotas.',
      summary: c?.department_settings && (c.department_settings.enabled ? `Département ${c.department_settings.required ? 'obligatoire' : 'facultatif'} · facture ${{ detailed: 'ventilée', split: 'par département', none: 'sans détail' }[c.department_settings.invoice_mode]}` : 'Désactivé'),
      keywords: 'département service banque finance marketing courrier interne bordereau centre de coûts quota facture ventilée',
    },
    {
      key: 'vehicles', icon: '🏍️', title: 'Véhicules', to: '/admin/reglages/vehicules', permission: 'fleet.manage',
      description: 'État des lieux, papiers et entretien, carburant (dotation ou remboursement), location-vente, amendes, indemnité véhicule personnel.',
      summary: c?.vehicle_settings && `État des lieux ${{ daily: 'chaque jour', weekly: 'chaque lundi', never: 'désactivé' }[c.vehicle_settings.check_mode]} · carburant ${{ allowance: 'en dotation', refund: 'remboursé', courier: 'à la charge du livreur' }[c.vehicle_settings.fuel_mode]}`,
      keywords: 'véhicule moto parc assurance visite technique vignette carte grise permis entretien vidange carburant essence plein dotation location vente amende sinistre',
    },
    {
      key: 'team', icon: '🛵', title: 'Équipe et paie',
      description: 'Rémunération des livreurs, comptes et rôles de l\'équipe.',
      links: [{ to: '/admin/paie-livreurs', label: 'Paie des livreurs', permission: 'pay.manage' }, { to: '/admin/utilisateurs', label: 'Utilisateurs', permission: 'users.view' }, { to: '/admin/roles', label: 'Rôles et droits', permission: 'users.view' }],
      keywords: 'paie salaire plan rémunération primes utilisateurs rôles droits permissions équipe périmètre zones dépôts',
    },
    {
      key: 'integrations', icon: '🔌', title: 'Intégrations',
      description: 'Clés API et webhooks pour les boutiques en ligne, reprise de l\'ancienne application.',
      links: [
        { to: '/admin/integrations', label: 'API et webhooks', permission: 'integrations.manage' },
        { to: '/admin/reglages/reprise', label: 'Reprise de l\'ancienne application', permission: 'settings.manage' },
      ],
      keywords: 'api clé webhook boutique import reprise ancienne application',
    },
  ]
})

// Recherche sans accents ni majuscules
const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

const cards = computed(() => ALL.value
  .filter(allowed)
  .map((card) => ({ ...card, links: card.links?.filter(allowed) }))
  .filter((card) => card.to || card.links?.length)
  .filter((card) => {
    const q = norm(query.value.trim())
    if (!q) return true
    const text = norm([card.title, card.description, card.summary, card.keywords, ...(card.links || []).map((l) => l.label)].join(' '))
    return q.split(/\s+/).every((word) => text.includes(word))
  }))

onMounted(async () => {
  const [c, guide] = await Promise.all([
    http.get(`/companies/${auth.user.company_id}`).catch(() => null),
    auth.can('settings.manage') ? http.get('/setup').catch(() => null) : null,
  ])
  company.value = c?.data?.data ?? null
  setup.value = guide?.data?.data ?? null
})
</script>
