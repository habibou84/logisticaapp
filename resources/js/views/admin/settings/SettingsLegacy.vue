<template>
  <SettingsPage icon="📥" title="Reprise de l'ancienne application" intro="Récupérer les comptes de l'ancienne application.">
    <LegacyImportSettings />
  </SettingsPage>
</template>

<script setup>
import SettingsPage from '../../../components/settings/SettingsPage.vue'
import LegacyImportSettings from '../../../components/LegacyImportSettings.vue'
</script>
