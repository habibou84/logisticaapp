<template>
  <span :class="['inline-block rounded-full px-2 py-0.5 text-xs font-medium', LOOK[status].cls]" :title="title">{{ LOOK[status][merchant ? 'merchant' : 'staff'] }}</span>
</template>

<script setup>
// Statut d'un moyen de paiement : vérifié par l'agence, à vérifier, refusé
defineProps({
  status: { type: String, required: true },
  merchant: { type: Boolean, default: false },
  title: { type: String, default: null },
})

const LOOK = {
  verified: { cls: 'bg-emerald-100 text-emerald-800', merchant: '✔ Vérifié par l\'agence', staff: '✔ Vérifié' },
  pending: { cls: 'bg-amber-100 text-amber-800', merchant: '⏳ À vérifier par l\'agence', staff: '⏳ À vérifier' },
  rejected: { cls: 'bg-red-100 text-red-700', merchant: '✕ Refusé par l\'agence', staff: '✕ Refusé' },
}
</script>
