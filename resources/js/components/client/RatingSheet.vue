<template>
  <!-- Client : noter le livreur (étoiles, bons points, motif si la note est basse, commentaire, préférence) -->
  <BottomSheet :open="open" :title="title" @close="$emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <div class="text-center space-y-1">
        <p class="text-slate-600">{{ question }}</p>
        <StarRating v-model="form.stars" size="h-10 w-10" gap="gap-1.5" label="Votre note" />
        <p class="text-sm font-medium text-slate-700 h-5">{{ WORDS[form.stars] || '' }}</p>
      </div>

      <div v-if="form.stars && !low">
        <p class="m-label">Ce qui était bien <span class="font-normal text-slate-500">(facultatif)</span></p>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="c in goodPoints" :key="c.value" type="button"
            :class="['tap rounded-full px-3 py-1.5 text-sm ring-1', form.criteria.includes(c.value) ? 'bg-emerald-600 text-white ring-emerald-600' : 'bg-white ring-slate-300 text-slate-700']"
            :aria-pressed="form.criteria.includes(c.value)" @click="toggle('criteria', c.value)"
          >
            {{ c.label }}
          </button>
        </div>
      </div>

      <!-- Ce qui n'est pas allé : liste déroulante, chaque choix s'ajoute (obligatoire si la note est basse) -->
      <div v-if="form.stars">
        <label class="m-label" for="rating-reason">
          Qu'est-ce qui n'est pas allé ?
          <span v-if="low">*</span><span v-else class="font-normal text-slate-500">(facultatif)</span>
        </label>
        <select id="rating-reason" class="m-input" :value="''" @change="addReason($event)">
          <option value="">{{ form.reasons.length ? 'Ajouter un autre motif…' : 'Choisir un motif…' }}</option>
          <option v-for="c in reasonChoices" :key="c.value" :value="c.value">{{ c.label }}</option>
        </select>
        <div v-if="form.reasons.length" class="mt-2 flex flex-wrap gap-2">
          <button
            v-for="r in form.reasons" :key="r" type="button"
            class="tap inline-flex items-center gap-1 rounded-full bg-red-50 px-3 py-1.5 text-sm text-red-700 ring-1 ring-red-200"
            :aria-label="`Retirer : ${reasonLabel(r)}`" @click="toggle('reasons', r)"
          >
            {{ reasonLabel(r) }} <span aria-hidden="true">✕</span>
          </button>
        </div>
      </div>

      <div v-if="form.stars">
        <label class="m-label" for="rating-comment">Un mot <span class="font-normal text-slate-500">(facultatif)</span></label>
        <textarea id="rating-comment" v-model="form.comment" rows="2" maxlength="1000" class="m-input" placeholder="Votre avis aide l'agence à s'améliorer" />
      </div>

      <!-- Livreur préféré ou écarté (si l'entreprise le permet) -->
      <div v-if="choice && courierId" class="flex gap-2">
        <button type="button" :class="['tap flex-1 rounded-xl py-2.5 text-sm font-semibold ring-1', preference === 'preferred' ? 'bg-emerald-50 text-emerald-800 ring-emerald-300' : 'bg-white ring-slate-200']" @click="$emit('preference', preference === 'preferred' ? null : 'preferred')">
          {{ preference === 'preferred' ? '★ Mon livreur préféré' : '☆ En faire mon préféré' }}
        </button>
        <button type="button" :class="['tap flex-1 rounded-xl py-2.5 text-sm font-semibold ring-1', preference === 'excluded' ? 'bg-red-50 text-red-700 ring-red-300' : 'bg-white ring-slate-200']" @click="$emit('preference', preference === 'excluded' ? null : 'excluded')">
          {{ preference === 'excluded' ? 'Écarté de mes courses' : 'Ne plus me l\'envoyer' }}
        </button>
      </div>

      <p v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p>
      <button class="m-btn-primary" :disabled="!form.stars || saving">{{ saving ? 'Envoi…' : 'Envoyer ma note' }}</button>
    </form>
  </BottomSheet>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import BottomSheet from '../mobile/BottomSheet.vue'
import StarRating from '../StarRating.vue'

const props = defineProps({
  open: Boolean,
  title: { type: String, default: 'Noter le livreur' },
  question: { type: String, default: 'Comment s\'est passée la course ?' },
  // Note déjà donnée (modification)
  rating: { type: Object, default: null },
  criteria: { type: Object, required: true }, // { client, reasons, settings }
  activity: { type: String, default: 'ecommerce' },
  courierId: { type: Number, default: null },
  preference: { type: String, default: null },
  saving: Boolean,
  error: { type: String, default: '' },
})
const emit = defineEmits(['close', 'save', 'preference'])
const WORDS = { 1: 'Très décevant', 2: 'Décevant', 3: 'Correct', 4: 'Bien', 5: 'Excellent' }
const form = reactive({ stars: null, criteria: [], reasons: [], comment: '' })

const low = computed(() => !!form.stars && form.stars <= (props.criteria.settings?.reason_below ?? 2))
const choice = computed(() => !!props.criteria.settings?.client_choice)
// Bons points selon l'activité : remise et décharge (distribution), présence (MAD)
const goodPoints = computed(() => (props.criteria.client || []).filter((c) => (c.value !== 'handover' || props.activity === 'distribution') && (c.value !== 'presence' || props.activity === 'mad')))
// Motifs choisis par l'agence ; « absent » pour la MAD seulement, « autre personne » hors e-commerce
const reasonList = computed(() => (props.criteria.client_reasons || props.criteria.reasons || []).filter((c) => (c.value !== 'absent' || props.activity === 'mad') && (c.value !== 'wrong_person' || props.activity !== 'ecommerce')))
const reasonChoices = computed(() => reasonList.value.filter((c) => !form.reasons.includes(c.value)))
const reasonLabel = (v) => (props.criteria.reasons || []).find((c) => c.value === v)?.label || v

function addReason(event) {
  const value = event.target.value
  if (value && !form.reasons.includes(value)) form.reasons = [...form.reasons, value]
  event.target.value = ''
}

watch(() => props.open, (open) => {
  if (!open) return
  Object.assign(form, {
    stars: props.rating?.stars ?? null,
    criteria: [...(props.rating?.criteria || [])],
    reasons: [...(props.rating?.reasons || [])],
    comment: props.rating?.comment || '',
  })
}, { immediate: true })

function toggle(field, value) {
  form[field] = form[field].includes(value) ? form[field].filter((v) => v !== value) : [...form[field], value]
}

function submit() {
  emit('save', {
    stars: form.stars,
    criteria: low.value ? [] : form.criteria,
    reasons: form.reasons,
    comment: form.comment || null,
  })
}
</script>
