<template>
  <!-- Frais d'un colis retourné : rangé avec les tarifs -->
  <section v-if="form" class="card p-4 space-y-3">
    <h2 class="font-semibold">Retours</h2>
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="label" for="returnfee">Frais facturés au marchand pour un colis retourné (% des frais de livraison)</label>
        <input id="returnfee" v-model.number="form.return_fee_percent" type="number" min="0" max="100" class="input w-24">
      </div>
      <button type="button" class="btn-primary" :disabled="!dirty || saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
    </div>
    <p v-if="error" class="field-error">{{ error }}</p>
  </section>
</template>

<script setup>
import { useCompanySettings } from '../../composables/useCompanySettings'

const { form, dirty, saving, error, save } = useCompanySettings(['return_fee_percent'])
</script>
