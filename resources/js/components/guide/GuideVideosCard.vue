<template>
  <section v-if="show" class="card p-4 space-y-3">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 class="font-semibold">🎬 Bien démarrer</h2>
        <p class="text-sm text-gray-600">
          Huit courtes vidéos, une par étape essentielle : regardez, puis faites-le vous-même.
          <span v-if="guide.essentials">Étapes faites : {{ guide.essentials.done }} sur {{ guide.essentials.total }}.</span>
        </p>
      </div>
      <div class="flex gap-3 text-sm">
        <RouterLink to="/admin/mise-en-route" class="text-blue-600 underline">Mise en route</RouterLink>
        <button type="button" class="text-gray-500 underline" :disabled="busy" @click="hideCard">Masquer</button>
      </div>
    </div>
    <ul class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <li v-for="(c, i) in GUIDE_CHAPTERS" :key="c.key">
        <button type="button" class="group w-full text-left space-y-1" @click="open(c.key)">
          <span class="relative block overflow-hidden rounded-lg ring-1 ring-slate-200 bg-slate-100 aspect-video">
            <img :src="guideFiles(c.key).poster" alt="" loading="lazy" class="h-full w-full object-cover transition group-hover:scale-105">
            <span class="absolute inset-0 grid place-items-center">
              <span class="grid h-9 w-9 place-items-center rounded-full bg-black/60 text-white text-sm" aria-hidden="true">▶</span>
            </span>
            <span v-if="steps[c.key]?.status === 'done'" class="absolute right-1 top-1 rounded-full bg-emerald-600 px-1.5 text-xs text-white">✓ fait</span>
          </span>
          <span class="block text-sm font-medium">{{ i + 1 }}. {{ c.title }}</span>
          <span v-if="videos.watched.includes(c.key)" class="block text-xs text-gray-500">Vidéo vue</span>
        </button>
      </li>
    </ul>
    <GuideVideoPlayer :chapter-key="playing" :steps="steps" :watched="videos.watched" @close="close" @select="open" @watched="watched" />
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import GuideVideoPlayer from './GuideVideoPlayer.vue'
import { GUIDE_CHAPTERS, guideFiles } from '../../utils/guideVideos'
import { useGuideVideos } from '../../composables/useGuideVideos'
import { useToastStore } from '../../stores/toasts'

/**
 * Carte « Bien démarrer » du tableau de bord (administrateurs) : affichée si l'entreprise l'a laissée active
 * (Réglages › Entreprise) et tant que l'administrateur ne l'a pas masquée.
 */
const guide = ref(null)
const busy = ref(false)
const toasts = useToastStore()
const { playing, steps, videos, open, close, watched, hide } = useGuideVideos(guide)
const show = computed(() => guide.value && videos.value.enabled && !videos.value.hidden)

async function hideCard() {
  busy.value = true
  try {
    await hide(true)
    toasts.success('Guide masqué. Vous le retrouvez dans Mise en route.')
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    guide.value = (await http.get('/setup')).data.data
  } catch {
    guide.value = null
  }
})
</script>
