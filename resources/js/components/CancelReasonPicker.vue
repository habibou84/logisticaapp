<template>
  <div class="space-y-2">
    <p class="text-sm font-semibold">{{ question }} <span class="text-red-600">*</span></p>
    <p v-if="loading" class="text-sm text-slate-600">Chargement…</p>
    <div v-else class="space-y-1.5" role="radiogroup">
      <label
        v-for="r in info?.reasons || []"
        :key="r.code"
        :class="['flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm', modelValue === r.code ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200']"
      >
        <input type="radio" :value="r.code" :checked="modelValue === r.code" @change="$emit('update:modelValue', r.code)">
        {{ r.label }}
      </label>
    </div>
    <div>
      <label class="label" :for="noteId">Précision {{ modelValue === 'other' ? '*' : '(facultatif)' }}</label>
      <textarea :id="noteId" :value="note" rows="2" :class="mobile ? 'm-input' : 'input'" maxlength="500" :required="modelValue === 'other'" @input="$emit('update:note', $event.target.value)" />
    </div>
    <p v-if="info && !info.merchant" class="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-900">📣 Le client est prévenu avec ce motif et votre nom.</p>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import http from '../bootstrap/axios'

/** Motif d'annulation obligatoire (liste du client ou de l'équipe) et précision ; « Autre » demande une précision */
defineProps({
  modelValue: { type: String, default: null },
  note: { type: String, default: '' },
  mobile: { type: Boolean, default: false },
  question: { type: String, default: 'Pourquoi annulez-vous ?' },
})
defineEmits(['update:modelValue', 'update:note'])

const info = ref(null)
const loading = ref(true)
const noteId = `cancel-note-${Math.random().toString(36).slice(2, 8)}`

onMounted(async () => {
  try {
    info.value = (await http.get('/cancel-reasons')).data.data
  } finally {
    loading.value = false
  }
})
</script>
