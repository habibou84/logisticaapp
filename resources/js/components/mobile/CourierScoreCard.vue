<template>
  <!-- Livreur : sa note, ses points forts et à améliorer, les commentaires (selon les réglages) -->
  <section v-if="data" class="m-card p-4 space-y-3">
    <div class="flex items-center gap-3">
      <span class="text-3xl font-bold" :class="data.score === null ? 'text-slate-400' : data.score >= 4 ? 'text-emerald-700' : data.score >= 3 ? 'text-amber-700' : 'text-red-700'">{{ data.score?.toFixed(1) ?? '—' }}</span>
      <div>
        <p class="font-semibold">Ma note</p>
        <StarRating :model-value="data.score" readonly size="h-5 w-5" />
        <p v-if="data.new" class="text-xs text-slate-500">Pas encore assez de courses notées</p>
      </div>
    </div>
    <div v-if="strengths.length">
      <p class="text-sm font-medium text-slate-700">Mes points forts</p>
      <p class="flex flex-wrap gap-1 mt-1"><span v-for="s in strengths" :key="s" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800">{{ s }}</span></p>
    </div>
    <div v-if="improve.length">
      <p class="text-sm font-medium text-slate-700">À améliorer</p>
      <p class="flex flex-wrap gap-1 mt-1"><span v-for="s in improve" :key="s" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-800">{{ s }}</span></p>
    </div>
    <div v-if="data.comments?.length" class="space-y-1">
      <p class="text-sm font-medium text-slate-700">Ce que disent les clients</p>
      <div v-for="(c, i) in data.comments.slice(0, 5)" :key="i" class="rounded-xl bg-slate-50 p-2 text-sm">
        <StarRating :model-value="c.stars" readonly size="h-3.5 w-3.5" />
        <p>« {{ c.comment }} »<span v-if="c.from" class="text-slate-500"> · {{ c.from }}</span></p>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import StarRating from '../StarRating.vue'
import { ratingCriteria } from '../../composables/useCourierRating'

const data = ref(null)
const labels = ref({ client: [], reasons: [] })
const label = (group, key) => labels.value[group]?.find((c) => c.value === key)?.label || key

// Bons points des clients les plus cités ; motifs des mauvaises notes et indicateurs sous 80 %
const strengths = computed(() => Object.keys(data.value?.client?.criteria || {}).slice(0, 4).map((k) => label('client', k)))
const improve = computed(() => [
  ...Object.keys(data.value?.client?.reasons || {}).slice(0, 3).map((k) => label('reasons', k)),
  ...(data.value?.auto?.indicators || []).filter((i) => i.value !== null && i.value < 80).map((i) => i.label),
])

onMounted(async () => {
  labels.value = await ratingCriteria()
  data.value = (await http.get('/courier/rating').catch(() => null))?.data.data ?? null
})
</script>
