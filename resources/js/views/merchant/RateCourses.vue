<template>
  <!-- Courses récentes à noter, et le livreur attitré (MAD) de la période -->
  <div class="space-y-4">
    <p class="text-sm text-slate-600">Votre avis sur les livreurs aide l'agence à vous servir toujours mieux. Une note en quelques secondes.</p>

    <section v-if="mad.length" class="space-y-2">
      <h2 class="px-1 text-base font-bold text-slate-800">Mon livreur attitré</h2>
      <button v-for="m in mad" :key="m.courier_id" type="button" class="tap m-card w-full p-4 flex items-center gap-3 text-left active:bg-slate-50" @click="openMad(m)">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-700"><StatusIcon name="user" class="h-5 w-5" /></span>
        <span class="min-w-0 flex-1"><strong class="block">{{ m.name }}</strong><span class="text-sm text-slate-600">Note de {{ m.period }}</span></span>
        <StarRating v-if="m.rating" :model-value="m.rating.stars" readonly size="h-4 w-4" />
        <span v-else class="text-sm font-semibold text-[var(--app-color)]">Noter</span>
      </button>
    </section>

    <section class="space-y-2">
      <h2 class="px-1 text-base font-bold text-slate-800">Courses à noter</h2>
      <SkeletonList v-if="loading" :rows="3" />
      <button v-for="o in orders" :key="o.id" type="button" class="tap m-card w-full p-4 flex items-center gap-3 text-left active:bg-slate-50" @click="openOrder(o)">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><StatusIcon name="package" class="h-5 w-5" /></span>
        <span class="min-w-0 flex-1">
          <strong class="block truncate">{{ o.recipient_name || o.tracking_code }}</strong>
          <span class="block truncate text-sm text-slate-600">{{ o.zone_name }}<template v-if="o.courier"> · {{ o.courier }}</template></span>
        </span>
        <StarRating :model-value="0" readonly size="h-4 w-4" />
      </button>
      <EmptyState v-if="!loading && !orders.length" icon="check" title="Tout est noté" text="Merci ! Les prochaines courses terminées apparaîtront ici." />
    </section>

    <RatingSheet
      :open="rater.state.open" :criteria="rater.state.criteria" :rating="current?.rating" :activity="current?.activity || 'ecommerce'"
      :courier-id="current?.courierId" :preference="current?.preference" :saving="rater.state.saving" :error="rater.state.error"
      :title="current?.title || 'Noter le livreur'" :question="current?.question"
      @close="rater.state.open = false" @save="save" @preference="setPreference"
    />
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import StarRating from '../../components/StarRating.vue'
import RatingSheet from '../../components/client/RatingSheet.vue'
import StatusIcon from '../../components/mobile/StatusIcon.vue'
import SkeletonList from '../../components/mobile/SkeletonList.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { useCourierRating } from '../../composables/useCourierRating'
import { useToastStore } from '../../stores/toasts'

const toasts = useToastStore()
const rater = useCourierRating()
const orders = ref([])
const mad = ref([])
const loading = ref(true)
// Ce qu'on note : { url, title, question, rating, activity, courierId, preference }
const current = ref(null)

async function load() {
  try {
    const { data } = await http.get('/merchant/ratings/pending')
    orders.value = data.data
    mad.value = data.mad || []
  } finally {
    loading.value = false
  }
}

async function openOrder(o) {
  const info = (await http.get(`/orders/${o.id}/rating`)).data.data
  current.value = {
    url: `/orders/${o.id}/rating`,
    title: info.courier ? `Noter ${info.courier.name}` : 'Noter le livreur',
    question: `Course pour ${o.recipient_name || o.tracking_code}`,
    rating: info.rating,
    courierId: info.courier?.id,
    preference: info.preference,
  }
  rater.openSheet()
}

function openMad(m) {
  current.value = {
    url: `/merchant/couriers/${m.courier_id}/rating`,
    title: `Noter ${m.name}`,
    question: `Comment s'est passée la mise à disposition (${m.period}) ?`,
    rating: m.rating,
    activity: 'mad',
  }
  rater.openSheet()
}

async function save(payload) {
  if (await rater.save(current.value.url, payload)) {
    toasts.success('Merci pour votre note !')
    await load()
  }
}

async function setPreference(kind) {
  const result = await rater.prefer(current.value.courierId, kind)
  if (result !== undefined) current.value.preference = result
}

onMounted(load)
</script>
