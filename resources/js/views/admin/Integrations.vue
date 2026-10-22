<template>
  <div class="max-w-4xl space-y-4">
    <h1 class="text-xl font-bold">Intégrations</h1>
    <div class="card p-4">
      <label class="label" for="int-merchant">E-commerçant</label>
      <select id="int-merchant" v-model="merchantId" class="input">
        <option :value="null">Tous (consultation)</option>
        <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.business_name }}</option>
      </select>
      <p class="text-xs text-gray-500 mt-1">Choisissez un e-commerçant pour lui créer une clé API ou une adresse webhook.</p>
    </div>
    <Integrations :merchant-id="merchantId" staff />
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import Integrations from '../../components/Integrations.vue'

const merchants = ref([])
const merchantId = ref(null)

onMounted(async () => {
  merchants.value = (await http.get('/merchants', { params: { per_page: 200 } })).data.data
})
</script>
