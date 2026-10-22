<template>
  <MobileLayout base="/marchand" :color="spaceColor('marchand')" :app-name="shopName" :initials="initials" colorful :tabs="tabs" />
</template>

<script setup>
import { computed } from 'vue'
import MobileLayout from '../../components/mobile/MobileLayout.vue'
import { loadBranding, spaceColor } from '../../composables/useBranding'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()

const shopName = computed(() => auth.user?.merchant?.business_name || 'Mes livraisons')
// « Boutique Chic Abidjan » → « BC »
const initials = computed(() => shopName.value.split(/\s+/).filter((w) => /^[\p{L}\d]/u.test(w)).slice(0, 2).map((w) => w[0].toUpperCase()).join(''))

const tabs = computed(() => [
  { to: '/marchand', label: 'Accueil', emoji: '🏠', exact: true },
  { to: '/marchand/courses', label: 'Courses', emoji: '📦', exact: true },
  {
    to: '/marchand/courses/nouvelle',
    label: 'Nouvelle course',
    primary: true,
    menu: auth.can('orders.create') ? [
      { to: '/marchand/courses/nouvelle', icon: '＋', label: 'Une nouvelle course', text: 'Le client, la commune, le montant' },
      { to: '/marchand/courses/nouvelle?coller=1', icon: '💬', label: 'Coller un message WhatsApp', text: 'La course se remplit toute seule' },
      { to: '/marchand/courses/import', icon: '📥', label: 'Importer un fichier Excel', text: 'Beaucoup de courses d\'un coup' },
    ] : null,
  },
  ...(auth.can('finance.view') ? [{ to: '/marchand/paiements', label: 'Argent', emoji: '💰' }] : []),
  { to: '/marchand/profil', label: 'Moi', emoji: '👤' },
])

// Couleur et logo choisis par l'entreprise (Paramètres › Apparence)
loadBranding()
</script>
