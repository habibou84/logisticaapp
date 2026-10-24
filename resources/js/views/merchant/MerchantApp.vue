<template>
  <MobileLayout base="/marchand" :color="spaceColor('marchand')" :app-name="shopName" :initials="initials" :tabs="tabs" :sidebar="sidebar" />
</template>

<script setup>
import { computed } from 'vue'
import MobileLayout from '../../components/mobile/MobileLayout.vue'
import { BoxIcon, FileIcon, HomeIcon, UserIcon, WalletIcon } from '../../components/mobile/icons'
import { loadBranding, spaceColor } from '../../composables/useBranding'
import { useAuthStore } from '../../stores/auth'
import { useClientSpaceStore } from '../../stores/clientSpace'

const auth = useAuthStore()
const space = useClientSpaceStore()

const shopName = computed(() => auth.user?.merchant?.business_name || 'Mes livraisons')
// « Boutique Chic Abidjan » → « BC »
const initials = computed(() => shopName.value.split(/\s+/).filter((w) => /^[\p{L}\d]/u.test(w)).slice(0, 2).map((w) => w[0].toUpperCase()).join(''))

// Argent (reversements e-commerce) ; sans e-commerce, les factures du mois
const moneyTab = computed(() => {
  if (!auth.can('finance.view')) return null
  return space.hasEcommerce ? { to: '/marchand/paiements', label: 'Argent', icon: WalletIcon } : { to: '/marchand/factures', label: 'Factures', icon: FileIcon }
})

const tabs = computed(() => [
  { to: '/marchand', label: 'Accueil', icon: HomeIcon, exact: true },
  { to: '/marchand/courses', label: 'Courses', icon: BoxIcon, exact: true },
  {
    to: '/marchand/courses/nouvelle',
    label: 'Nouvelle course',
    primary: true,
    menu: auth.can('orders.create') ? [
      { to: '/marchand/courses/nouvelle', icon: 'plus', label: 'Une nouvelle course', text: space.hasEcommerce ? 'Le destinataire, la commune, le montant' : 'Le destinataire, la commune, l\'envoi' },
      { to: '/marchand/courses/nouvelle?coller=1', icon: 'message', label: 'Coller un message WhatsApp', text: 'La course se remplit toute seule' },
      { to: '/marchand/courses/import', icon: 'upload', label: 'Importer un fichier Excel', text: 'Beaucoup de courses d\'un coup' },
    ] : null,
  },
  ...(moneyTab.value ? [moneyTab.value] : []),
  { to: '/marchand/profil', label: 'Moi', icon: UserIcon },
])

// Ordinateur : les mêmes rubriques, plus les factures et les statistiques
const sidebar = computed(() => [
  { to: '/marchand', label: 'Accueil', icon: HomeIcon, exact: true },
  { to: '/marchand/courses', label: 'Mes courses', icon: BoxIcon, exact: true },
  ...(auth.can('finance.view') && space.hasEcommerce ? [{ to: '/marchand/paiements', label: 'Mon argent', icon: WalletIcon }] : []),
  ...(auth.can('finance.view') && space.hasBilled ? [{ to: '/marchand/factures', label: 'Mes factures', icon: FileIcon }] : []),
  { to: '/marchand/statistiques', label: 'Statistiques', icon: 'chart' },
  { to: '/marchand/profil', label: 'Moi', icon: UserIcon },
])

// Couleur et logo choisis par l'entreprise (Paramètres › Apparence)
loadBranding()
</script>
