<template>
  <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', LOOK[status].cls]" :title="title"><Icone :nom="LOOK[status].icon" taille="petite" />{{ LOOK[status][merchant ? 'merchant' : 'staff'] }}</span>
</template>

<script setup>
import Icone from '../ui/Icone.vue'
// Statut d'un moyen de paiement : vérifié par l'agence, à vérifier, refusé
defineProps({
  status: { type: String, required: true },
  merchant: { type: Boolean, default: false },
  title: { type: String, default: null },
})

const LOOK = {
  verified: { cls: 'bg-emerald-100 text-emerald-800', icon: 'valider', merchant: 'Vérifié par l\'agence', staff: 'Vérifié' },
  pending: { cls: 'bg-amber-100 text-amber-800', icon: 'sablier', merchant: 'À vérifier par l\'agence', staff: 'À vérifier' },
  rejected: { cls: 'bg-red-100 text-red-700', icon: 'refus', merchant: 'Refusé par l\'agence', staff: 'Refusé' },
}
</script>
