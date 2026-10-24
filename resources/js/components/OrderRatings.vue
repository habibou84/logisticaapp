<template>
  <!-- Fiche course (équipe) : notes du livreur sur cette course, et la note de l'équipe -->
  <div class="card p-4 space-y-3 text-sm">
    <div class="flex items-center justify-between gap-2">
      <h2 class="font-semibold">⭐ Notes du livreur</h2>
      <RouterLink v-if="couriers.length" :to="{ path: '/admin/qualite-livreurs', query: { livreur: couriers[0].id } }" class="text-blue-600">Sa note globale</RouterLink>
    </div>
    <ul v-if="ratings.length" class="divide-y">
      <li v-for="r in ratings" :key="r.id" :class="['py-1.5', r.neutralized_at ? 'opacity-60' : '']">
        <div class="flex flex-wrap items-center gap-2">
          <StarRating :model-value="r.stars" readonly size="h-4 w-4" />
          <span class="text-xs rounded bg-slate-100 px-1.5">{{ SOURCES[r.source] }}</span>
          <span class="text-xs text-gray-600">{{ r.courier.name }} · {{ r.author || r.merchant || '' }}</span>
          <span v-if="r.neutralized_at" class="text-xs text-gray-500">neutralisée</span>
        </div>
        <p v-if="r.comment" class="text-gray-700">« {{ r.comment }} »</p>
      </li>
    </ul>
    <p v-else class="text-gray-500">Aucune note sur cette course.</p>

    <form v-if="couriers.length" class="flex flex-wrap items-center gap-2 border-t pt-2" @submit.prevent="save">
      <select v-if="couriers.length > 1" v-model="form.courier_id" class="input w-auto" aria-label="Livreur">
        <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <span v-else class="text-gray-600">Votre note pour {{ couriers[0].name }} :</span>
      <StarRating v-model="form.stars" size="h-6 w-6" label="Note de l'équipe" />
      <input v-model="form.comment" class="input flex-1 min-w-40" placeholder="Commentaire (équipe)">
      <button class="btn-secondary" :disabled="!form.stars || saving">Noter</button>
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import StarRating from './StarRating.vue'
import { useToastStore } from '../stores/toasts'

const props = defineProps({ order: { type: Object, required: true } })
const toasts = useToastStore()
const SOURCES = { client: 'Client', team: 'Équipe', recipient: 'Destinataire' }
const ratings = ref([])
const saving = ref(false)
// Livreurs de la course : livraison d'abord
const couriers = computed(() => [props.order.delivery_courier, props.order.pickup_courier, props.order.return_courier]
  .filter(Boolean).filter((c, i, list) => list.findIndex((x) => x.id === c.id) === i))
const form = reactive({ courier_id: null, stars: null, comment: '' })

async function load() {
  ratings.value = (await http.get(`/orders/${props.order.id}/ratings`).catch(() => ({ data: { data: [] } }))).data.data
}

async function save() {
  saving.value = true
  try {
    await http.post(`/couriers/${form.courier_id || couriers.value[0].id}/ratings`, { order_id: props.order.id, stars: form.stars, comment: form.comment || null })
    toasts.success('Note enregistrée.')
    Object.assign(form, { stars: null, comment: '' })
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  form.courier_id = couriers.value[0]?.id ?? null
  load()
})
</script>
