import { onBeforeUnmount, onMounted, ref } from 'vue'

// Largeur réelle du conteneur : le graphique est dessiné à sa taille (traits nets)
export function useWidth(el, fallback = 600) {
  const width = ref(fallback)
  let observer
  onMounted(() => {
    width.value = el.value?.clientWidth || fallback
    observer = new ResizeObserver(([entry]) => { width.value = Math.max(240, Math.floor(entry.contentRect.width)) })
    if (el.value) observer.observe(el.value)
  })
  onBeforeUnmount(() => observer?.disconnect())
  return width
}
