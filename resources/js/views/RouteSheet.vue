<template>
  <!-- Feuille de route (distribution) : une ligne par course, à faire signer à chaque remise -->
  <div class="route-page bg-white p-6 text-black">
    <div class="no-print mb-4 flex flex-wrap items-center gap-2">
      <button class="btn-primary" :disabled="!orders.length" @click="print">Imprimer</button>
      <span class="text-sm text-gray-500">Format A4 paysage · {{ orders.length }} course(s)</span>
    </div>

    <p v-if="loading">Chargement…</p>
    <template v-else>
      <header class="mb-3 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="text-xl font-bold">Feuille de route</h1>
          <p class="text-sm">{{ auth.user?.company?.name }} · {{ longDate }}</p>
          <p v-if="clients" class="text-sm">Client{{ clients.includes(',') ? 's' : '' }} : <strong>{{ clients }}</strong></p>
        </div>
        <div class="text-sm space-y-1 text-right">
          <p>Livreur : <strong>{{ courier || '……………………………' }}</strong></p>
          <p>Départ : ……… h ……… · Retour : ……… h ………</p>
        </div>
      </header>

      <table class="w-full border-collapse text-xs">
        <thead>
          <tr class="bg-gray-100">
            <th class="cell w-8">N°</th>
            <th class="cell">Destinataire</th>
            <th class="cell">Adresse</th>
            <th class="cell w-28">Suivi</th>
            <th class="cell w-40">Reçu par (nom, fonction)</th>
            <th class="cell w-36">Signature / cachet</th>
            <th class="cell w-16">Heure</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(o, i) in orders" :key="o.id" class="align-top">
            <td class="cell text-center">{{ i + 1 }}</td>
            <td class="cell">
              <strong>{{ o.recipient.name || '—' }}</strong>
              <span class="block">{{ o.recipient.phone }}</span>
              <span v-if="o.package?.description" class="block text-gray-600">{{ o.package.description }}</span>
            </td>
            <td class="cell">
              <strong>{{ o.delivery.zone_name }}</strong>
              <span class="block">{{ o.delivery.address }}</span>
              <span v-if="o.delivery.landmark" class="block text-gray-600">Repère : {{ o.delivery.landmark }}</span>
            </td>
            <td class="cell font-mono">{{ o.tracking_code }}<span v-if="o.status === 'delivered'" class="block font-sans text-gray-600">remise : {{ o.received_by_name || 'oui' }}</span></td>
            <td class="cell h-14" />
            <td class="cell" />
            <td class="cell" />
          </tr>
        </tbody>
      </table>

      <footer class="mt-6 grid grid-cols-2 gap-8 text-sm">
        <p>Signature du livreur : ………………………………</p>
        <p>Contrôle au retour (nom, signature) : ………………………………</p>
      </footer>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const auth = useAuthStore()
const orders = ref([])
const loading = ref(true)
const longDate = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

// Un seul livreur ou un seul client pour toutes les courses : indiqué en tête
const unique = (values) => [...new Set(values.filter(Boolean))]
const courier = computed(() => {
  const names = unique(orders.value.map((o) => o.delivery_courier?.name))
  return names.length === 1 ? names[0] : ''
})
const clients = computed(() => unique(orders.value.map((o) => o.merchant?.business_name)).join(', '))

function print() {
  window.print()
}

onMounted(async () => {
  const ids = String(route.query.ids || '').split(',').map(Number).filter(Boolean)
  try {
    if (ids.length) {
      const { data } = await http.get('/orders', { params: { ids, per_page: Math.min(ids.length, 200) } })
      // Dans l'ordre choisi dans la liste
      orders.value = ids.map((id) => data.data.find((o) => o.id === id)).filter(Boolean)
    }
  } finally {
    loading.value = false
  }
})
</script>

<style>
.route-page .cell { border: 1px solid #000; padding: 4px 6px; text-align: left; }
@media print {
  .no-print { display: none; }
  .route-page { padding: 0; }
  @page { size: A4 landscape; margin: 10mm; }
}
</style>
