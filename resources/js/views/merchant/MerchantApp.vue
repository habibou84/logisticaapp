<template>
  <MerchantShell base="/marchand" :color="spaceColor('marchand')" :app-name="shopName" :initials="initials" :tabs="tabs" :sidebar="sidebar" :action-count="toDecide" />
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import MerchantShell from '../../components/mobile/MerchantShell.vue'
import { BoxIcon, FileIcon, HomeIcon, UserIcon, WalletIcon } from '../../components/mobile/icons'
import { loadBranding, spaceColor } from '../../composables/useBranding'
import { cachedGet } from '../../composables/useCachedApi'
import { orderChanges } from '../../composables/useRealtime'
import { useAuthStore } from '../../stores/auth'
import { useClientSpaceStore } from '../../stores/clientSpace'

const auth = useAuthStore()
const space = useClientSpaceStore()

const shopName = computed(() => auth.user?.merchant?.business_name || 'Mes livraisons')
// « Boutique Chic Abidjan » → « BC »
const initials = computed(() => shopName.value.split(/\s+/).filter((w) => /^[\p{L}\d]/u.test(w)).slice(0, 2).map((w) => w[0].toUpperCase()).join(''))
const has = (activity) => space.activities.some((a) => a.value === activity)
const several = computed(() => space.activities.length > 1)
const TAGS = {
  ecommerce: ['E-commerce', 'bg-[#FFE6D6] text-[#B4470E]'],
  distribution: ['Distribution', 'bg-[#E3EDFF] text-[#1D5BC7]'],
  mad: ['MAD', 'bg-[#EDE7FF] text-[#5B3FC4]'],
}
const tag = (activity) => (several.value ? { tag: TAGS[activity][0], tagClass: TAGS[activity][1] } : {})

// Bouton ＋ : « Que voulez-vous envoyer ? », les choix des activités du client
const sendMenu = computed(() => [
  ...(has('ecommerce') ? [{ to: '/marchand/courses/nouvelle?activite=ecommerce', color: 'colis', label: 'Un colis à livrer', text: 'Encaissement à la livraison possible', ...tag('ecommerce') }] : []),
  ...(has('distribution') ? [{ to: '/marchand/courses/nouvelle?activite=distribution', color: 'pli', label: 'Des plis ou des chèques', text: 'Avec accusé de réception', ...tag('distribution') }] : []),
  ...(has('mad') ? [{ to: '/marchand/courses/nouvelle?activite=mad', color: 'mad', label: 'Un livreur à disposition', text: 'À la journée ou à l\'heure', ...tag('mad') }] : []),
  { to: '/marchand/courses/import', color: 'excel', label: 'Plusieurs courses d\'un coup', text: 'Depuis un fichier Excel' },
  { to: '/marchand/courses/nouvelle?coller=1', color: 'coller', label: 'Coller un message WhatsApp', text: 'L\'adresse et le téléphone sont repris' },
])

// Argent (reversements e-commerce) ; sans e-commerce, les factures du mois
const moneyTab = computed(() => {
  if (!auth.can('finance.view')) return null
  return space.hasEcommerce
    ? { to: '/marchand/paiements', label: 'Argent', icon: WalletIcon, color: 'argent' }
    : { to: '/marchand/factures', label: 'Factures', icon: FileIcon, color: 'facture' }
})

const tabs = computed(() => [
  { to: '/marchand', label: 'Accueil', icon: HomeIcon, color: 'accueil', exact: true },
  { to: '/marchand/courses', label: 'Courses', icon: BoxIcon, color: 'colis', exact: true },
  // Pas pour le lecteur
  ...(auth.can('orders.create') ? [{ to: '/marchand/courses/nouvelle', label: 'Nouvelle course', sheetTitle: 'Que voulez-vous envoyer ?', primary: true, menu: sendMenu.value }] : []),
  ...(moneyTab.value ? [moneyTab.value] : []),
  { to: '/marchand/profil', label: 'Moi', icon: UserIcon, color: 'profil' },
])

// Ordinateur : les rubriques utiles à portée de main
const sidebar = computed(() => [
  { to: '/marchand', label: 'Accueil', color: 'accueil', exact: true },
  { to: '/marchand/courses', label: 'Mes courses', color: 'colis', exact: true, badge: toDecide.value || null },
  ...(auth.can('finance.view') && space.hasEcommerce ? [{ to: '/marchand/paiements', label: 'Mon argent', color: 'argent' }] : []),
  ...(auth.can('finance.view') && space.hasBilled ? [{ to: '/marchand/factures', label: 'Mes factures', color: 'facture' }] : []),
  ...(space.hasBilled ? [{ to: '/marchand/departements', label: 'Mes départements', color: 'departement' }] : []),
  ...(has('mad') ? [{ to: '/marchand/mes-livreurs', label: 'Mes livreurs', color: 'mad' }] : []),
  { to: '/marchand/statistiques', label: 'Statistiques', color: 'stats' },
  { to: '/marchand/profil', label: 'Moi', color: 'profil' },
])

// Colis qui attendent une décision : seul nombre affiché sur la cloche
const toDecide = ref(0)
async function loadToDecide() {
  try {
    const { data } = await cachedGet('/orders', { params: { queue: 'to_decide', per_page: 1 } })
    toDecide.value = data.meta?.total ?? data.data.length
  } catch {
    // Hors ligne : la cloche garde son dernier nombre
  }
}
watch(orderChanges, loadToDecide)
onMounted(loadToDecide)

// Couleur et logo choisis par l'entreprise (Paramètres › Apparence)
loadBranding()
</script>
