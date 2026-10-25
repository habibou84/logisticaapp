<template>
  <Teleport to="body">
    <div v-if="chapter" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-0 sm:p-4" @click.self="$emit('close')">
      <div class="w-full max-w-5xl max-h-full overflow-y-auto bg-white sm:rounded-xl shadow-xl" role="dialog" aria-modal="true" :aria-label="`Bien démarrer : ${chapter.title}`">
        <div class="flex items-center justify-between gap-2 border-b px-4 py-3">
          <h2 class="font-semibold">🎬 Bien démarrer · {{ index + 1 }}. {{ chapter.title }}</h2>
          <button type="button" class="text-gray-400 hover:text-gray-700 text-xl leading-none" aria-label="Fermer" @click="$emit('close')">×</button>
        </div>
        <div class="grid lg:grid-cols-[1fr_15rem]">
          <div class="bg-black">
            <!-- Sous-titres affichés d'office : on regarde souvent sans le son -->
            <video
              :key="chapter.key" ref="player" class="w-full aspect-video" controls autoplay playsinline preload="metadata"
              :poster="files.poster" @ended="ended" @timeupdate="progress"
            >
              <source :src="files.video" type="video/mp4">
              <track kind="subtitles" srclang="fr" label="Français" :src="files.subtitles" default>
              Votre navigateur ne lit pas les vidéos.
            </video>
          </div>
          <ol class="divide-y text-sm max-h-80 lg:max-h-none overflow-y-auto">
            <li v-for="(c, i) in GUIDE_CHAPTERS" :key="c.key">
              <button
                type="button" :class="['flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50', c.key === chapter.key ? 'bg-amber-50 font-semibold' : '']"
                :aria-current="c.key === chapter.key ? 'true' : undefined" @click="$emit('select', c.key)"
              >
                <span class="w-5 text-center" aria-hidden="true">{{ statusIcon(c.key) }}</span>
                <span class="flex-1">{{ i + 1 }}. {{ c.title }}</span>
                <span v-if="watched.includes(c.key)" class="text-xs text-gray-500">vue</span>
              </button>
            </li>
          </ol>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 border-t px-4 py-3">
          <p class="text-sm text-gray-600">
            <span v-if="steps[chapter.key]?.status === 'done'">✅ Cette étape est déjà faite.</span>
            <span v-else>Regardez, puis faites-le vous-même.</span>
          </p>
          <div class="flex flex-wrap gap-2">
            <RouterLink v-if="steps[chapter.key]?.action" :to="{ path: steps[chapter.key].action.to, query: { depuis: 'mise-en-route' } }" class="btn-primary" @click="$emit('close')">
              Ouvrir ce réglage
            </RouterLink>
            <button v-if="nextChapter" type="button" class="btn-secondary" @click="$emit('select', nextChapter.key)">Chapitre suivant →</button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { GUIDE_CHAPTERS, guideFiles } from '../../utils/guideVideos'

/**
 * Lecteur du guide vidéo : le chapitre choisi, la liste des chapitres (✅ étape faite) et le réglage à ouvrir.
 * « watched » est émis quand le chapitre est regardé presque jusqu'au bout.
 */
const props = defineProps({
  chapterKey: { type: String, default: null },
  // Étapes de la mise en route par clé : statut et réglage à ouvrir
  steps: { type: Object, default: () => ({}) },
  watched: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'select', 'watched'])

const player = ref(null)
const index = computed(() => GUIDE_CHAPTERS.findIndex((c) => c.key === props.chapterKey))
const chapter = computed(() => GUIDE_CHAPTERS[index.value] ?? null)
const nextChapter = computed(() => GUIDE_CHAPTERS[index.value + 1] ?? null)
const files = computed(() => guideFiles(chapter.value.key))
const STATUS = { done: '✅', partial: '🟠', todo: '⭕' }
const statusIcon = (key) => STATUS[props.steps[key]?.status] ?? '▶'

let reported = null
function progress(e) {
  const v = e.target
  if (v.duration && v.currentTime / v.duration > 0.9 && reported !== chapter.value.key) {
    reported = chapter.value.key
    emit('watched', chapter.value.key)
  }
}
function ended() {
  if (reported !== chapter.value.key) emit('watched', chapter.value.key)
}

const onKey = (e) => { if (e.key === 'Escape') emit('close') }
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>
