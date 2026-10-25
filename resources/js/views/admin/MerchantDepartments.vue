<template>
  <div class="space-y-4">
    <RouterLink to="/admin/marchands" class="text-sm text-blue-600">← Clients</RouterLink>
    <div>
      <h1 class="text-xl font-bold">{{ merchant?.business_name || 'Client' }} · Départements</h1>
      <p class="text-sm text-gray-600">Qui envoie, qui reçoit, qui gère quoi : chaque département est connu sur les courses, les bordereaux et la facture.</p>
    </div>
    <DepartmentsManager :merchant-id="merchantId" staff />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '../../bootstrap/axios'
import DepartmentsManager from '../../components/departments/DepartmentsManager.vue'

const route = useRoute()
const merchantId = computed(() => Number(route.params.id))
const merchant = ref(null)

onMounted(async () => {
  merchant.value = (await http.get(`/merchants/${merchantId.value}`)).data.data
})
</script>
