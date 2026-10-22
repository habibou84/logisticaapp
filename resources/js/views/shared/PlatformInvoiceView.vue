<template>
  <InvoiceDocument v-if="invoice" :invoice="invoice" :back="back" />
  <p v-else class="text-gray-500">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '../../bootstrap/axios'
import InvoiceDocument from '../../components/InvoiceDocument.vue'

// Facture de la plateforme : depuis la console (toutes) ou le back-office (celles de l'entreprise)
const route = useRoute()
const invoice = ref(null)
const fromConsole = computed(() => route.path.startsWith('/console'))
const back = computed(() => (fromConsole.value ? '/console/facturation' : '/admin/abonnement'))

onMounted(async () => {
  const url = fromConsole.value ? `/console/invoices/${route.params.id}` : `/subscription/invoices/${route.params.id}`
  invoice.value = (await http.get(url)).data.data
})
</script>
