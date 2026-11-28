<template>
  <div class="space-y-4">
    <section class="card space-y-3 p-4">
      <div>
        <h2 class="font-semibold">Rapport du mois</h2>
        <p class="text-sm text-slate-600">Un document de quelques pages, mis en page, à imprimer ou à enregistrer en PDF depuis le navigateur.</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <div>
          <label class="label" for="rp-month">Mois</label>
          <input id="rp-month" v-model="month" type="month" class="input w-auto" :max="thisMonth">
        </div>
        <a :href="`/admin/rapport-mensuel?mois=${month}`" target="_blank" class="btn-primary">Ouvrir le rapport</a>
      </div>
    </section>
    <!-- Rapports mensuels des clients sous contrat : relus puis envoyés -->
    <IntegrationsTab :only="['reports']" />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import IntegrationsTab from '../stats/IntegrationsTab.vue'

const ym = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
const now = new Date()
const thisMonth = ym(now)
// Le mois dernier par défaut : le dernier mois terminé
const month = ref(ym(new Date(now.getFullYear(), now.getMonth() - 1, 1)))
</script>
