<template>
  <div class="space-y-4 max-w-3xl">
    <RouterLink to="/admin/marchands" class="text-sm text-blue-600">← Clients</RouterLink>
    <h1 class="text-xl font-bold">{{ merchant?.business_name || 'Client' }} · Comptes</h1>
    <MerchantAccounts :merchant-id="merchantId" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '../../bootstrap/axios'
import MerchantAccounts from '../../components/merchant/MerchantAccounts.vue'

const route = useRoute()
const merchantId = computed(() => Number(route.params.id))
const merchant = ref(null)

onMounted(async () => {
  merchant.value = (await http.get(`/merchants/${merchantId.value}`)).data.data
})
</script>
