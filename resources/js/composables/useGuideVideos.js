import { computed, ref } from 'vue'
import http from '../bootstrap/axios'

/**
 * Guide vidéo « Bien démarrer » autour du parcours de mise en route (GET /setup) : chapitre ouvert, étapes par clé,
 * chapitres regardés, carte masquée ou non sur le tableau de bord.
 */
export function useGuideVideos(guide) {
  const playing = ref(null)
  const steps = computed(() => Object.fromEntries((guide.value?.steps ?? []).map((s) => [s.key, s])))
  const videos = computed(() => guide.value?.videos ?? { enabled: false, hidden: false, watched: [] })

  async function update(payload) {
    const { data } = await http.post('/setup/videos', payload)
    if (guide.value) guide.value = { ...guide.value, videos: data.data }
  }

  return {
    playing,
    steps,
    videos,
    open: (key) => { playing.value = key },
    close: () => { playing.value = null },
    watched: (key) => update({ watched: key }).catch(() => {}),
    hide: (hidden) => update({ hidden }),
  }
}
