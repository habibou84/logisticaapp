<template>
  <!-- Page WhatsApp : brancher le numéro, ce qui part tout seul, comment ça part -->
  <nav aria-label="WhatsApp" class="flex flex-wrap gap-1 border-b border-slate-300">
    <RouterLink
      v-for="t in TABS" :key="t.to" :to="t.to"
      class="-mb-px flex min-h-11 items-center border-b-2 border-transparent px-4 text-sm font-semibold text-slate-600 hover:text-slate-900"
      exact-active-class="!border-slate-900 !text-slate-900"
    >
      {{ t.label }}
    </RouterLink>
  </nav>
</template>

<script setup>
const TABS = [
  { to: '/admin/whatsapp', label: 'Connexion' },
  { to: '/admin/whatsapp/messages', label: 'Messages automatiques' },
  { to: '/admin/whatsapp/options', label: 'Options d\'envoi' },
]
</script>
