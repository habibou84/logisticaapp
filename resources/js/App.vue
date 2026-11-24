<template>
  <router-view />
  <InstallPrompt />
  <AppDialog />
</template>

<script setup>
import AppDialog from './components/ui/AppDialog.vue'
import InstallPrompt from './components/InstallPrompt.vue'
</script>
