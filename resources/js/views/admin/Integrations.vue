<template>
  <div class="max-w-4xl space-y-4">
    <h1 class="text-xl font-bold">Intégrations</h1>
    <div class="card p-4">
      <label class="label" for="int-merchant">Client</label>
      <SearchSelect
        id="int-merchant" v-model="merchantId" placeholder="Tous (consultation)" :empty-value="null"
        :options="merchants.map((m) => ({ value: m.id, label: m.business_name, search: m.phone }))"
      />
      <p class="text-xs text-slate-600 mt-1">Choisissez un client pour lui créer une clé API ou une adresse webhook.</p>
    </div>
    <Integrations :merchant-id="merchantId" staff />
  </div>
</template>

<script setup>
import SearchSelect from '../../components/SearchSelect.vue'
import { onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import Integrations from '../../components/Integrations.vue'

const merchants = ref([])
const merchantId = ref(null)

onMounted(async () => {
  merchants.value = (await http.get('/merchants', { params: { per_page: 200 } })).data.data
})
</script>
