<template>
  <span :class="['shrink-0 grid place-items-center rounded-xl font-bold text-white', small ? 'h-8 w-8 text-xs' : 'h-11 w-11 text-sm', STYLE[type]?.bg || 'bg-slate-500']">{{ STYLE[type]?.text || '?' }}</span>
</template>

<script setup>
// Pastille de couleur d'un moyen de paiement (Wave, Orange Money, MTN, Moov, banque)
defineProps({ type: { type: String, required: true }, small: { type: Boolean, default: false } })

const STYLE = {
  wave: { bg: 'bg-sky-500', text: 'W' },
  orange_money: { bg: 'bg-orange-500', text: 'OM' },
  mtn_momo: { bg: 'bg-yellow-400 !text-slate-900', text: 'MTN' },
  moov_money: { bg: 'bg-blue-700', text: 'Moov' },
  bank: { bg: 'bg-slate-700', text: '🏦' },
}
</script>
