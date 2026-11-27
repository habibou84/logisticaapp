<template>
  <div class="flex flex-wrap gap-2">
    <div v-for="p in shown" :key="p.id" class="relative h-20 w-20 overflow-hidden rounded-xl bg-slate-200 ring-1 ring-slate-200">
      <img v-if="p.src" :src="p.src" :alt="`Photo ${kind === 'before' ? 'avant' : 'après'} réparation`" class="h-full w-full object-cover">
      <button v-if="editable" type="button" class="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-black/60 text-xs text-white" aria-label="Retirer la photo" @click="remove(p)"><Icone nom="fermer" taille="petite" /></button>
    </div>
    <PhotoCapture v-if="editable" class="grid h-20 w-20 place-items-center rounded-xl border-2 border-dashed border-slate-300 bg-white text-2xl text-slate-600" @captured="upload">
      <template #default="{ busy }">
        <span v-if="busy || uploading" class="text-sm">…</span>
        <span v-else aria-label="Prendre une photo"><Icone nom="photo" taille="grande" /></span>
      </template>
    </PhotoCapture>
  </div>
</template>

<script setup>
import Icone from '../ui/Icone.vue'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import PhotoCapture from '../mobile/PhotoCapture.vue'
import { authImage } from '../../composables/useAuthImage'
import { useToastStore } from '../../stores/toasts'

const props = defineProps({
  repair: { type: Object, required: true },
  kind: { type: String, required: true }, // before, after
  editable: { type: Boolean, default: false },
})
const emit = defineEmits(['updated'])
const toasts = useToastStore()
const sources = ref({})
const uploading = ref(false)

const shown = computed(() => (props.repair.files || []).filter((f) => f.kind === props.kind).map((f) => ({ ...f, src: sources.value[f.id] })))

watch(() => props.repair.files, async (files) => {
  for (const f of (files || []).filter((x) => x.kind === props.kind && !sources.value[x.id])) {
    sources.value = { ...sources.value, [f.id]: await authImage(f.url).catch(() => null) }
  }
}, { immediate: true })
onBeforeUnmount(() => Object.values(sources.value).forEach((s) => s && URL.revokeObjectURL(s)))

async function upload({ file }) {
  uploading.value = true
  try {
    const form = new FormData()
    form.append('kind', props.kind)
    form.append('photos[]', file)
    emit('updated', (await http.post(`/mechanic/repairs/${props.repair.id}/photos`, form)).data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e, 'Photo pas envoyée. Vérifiez votre réseau et réessayez.'))
  } finally {
    uploading.value = false
  }
}

async function remove(photo) {
  try {
    emit('updated', (await http.delete(`/mechanic/repairs/${props.repair.id}/photos/${photo.id}`)).data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}
</script>
