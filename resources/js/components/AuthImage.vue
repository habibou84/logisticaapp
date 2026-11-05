<template>
  <img v-if="url" :src="url" :alt="alt">
  <slot v-else />
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import http from '../bootstrap/axios'

/**
 * Image servie par l'API (photo d'un véhicule, papier) : chargée avec la session, puis affichée.
 * Le contenu du slot s'affiche en attendant, ou si elle ne se charge pas.
 */
const props = defineProps({
  src: { type: String, default: null },
  alt: { type: String, default: '' },
})

const url = ref(null)

async function load(src) {
  if (url.value) URL.revokeObjectURL(url.value)
  url.value = null
  if (!src) return
  try {
    const { data } = await http.get(src, { responseType: 'blob' })
    url.value = URL.createObjectURL(data)
  } catch {
    url.value = null
  }
}

watch(() => props.src, load, { immediate: true })
onBeforeUnmount(() => url.value && URL.revokeObjectURL(url.value))
</script>
