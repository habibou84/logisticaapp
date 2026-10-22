<template>
  <!-- Apparaît dès qu'un réglage change ; sur téléphone, la marge basse de la page la garde au-dessus des onglets -->
  <div v-if="dirty || error" class="sticky bottom-0 z-30 -mx-4 md:mx-0 border-t bg-white/95 px-4 py-3 shadow-[0_-2px_8px_rgba(0,0,0,0.06)] backdrop-blur md:rounded-lg md:border" role="region" aria-label="Enregistrer les réglages">
    <p v-if="error" class="field-error mb-2">{{ error }}</p>
    <div class="flex flex-wrap items-center justify-between gap-2">
      <span class="text-sm text-gray-600">{{ dirty ? 'Modifications non enregistrées' : '' }}</span>
      <span class="flex gap-2">
        <button type="button" class="btn-secondary" :disabled="saving || !dirty" @click="$emit('reset')">Annuler</button>
        <button type="button" class="btn-primary" :disabled="saving || !dirty" @click="$emit('save')">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </span>
    </div>
  </div>
</template>

<script setup>
defineProps({ dirty: Boolean, saving: Boolean, error: { type: String, default: '' } })
defineEmits(['save', 'reset'])
</script>
