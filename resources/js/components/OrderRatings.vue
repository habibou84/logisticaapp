<template>
  <!-- Fiche course (équipe) : notes du livreur sur cette course, et la note de l'équipe -->
  <div class="card p-4 space-y-3 text-sm">
    <div class="flex items-center justify-between gap-2">
      <h2 class="flex items-center gap-1.5 font-semibold"><Icone nom="prioritaire" />Notes du livreur</h2>
      <RouterLink v-if="couriers.length" :to="{ path: `/admin/livreurs/${couriers[0].id}`, query: { onglet: 'notes' } }" class="text-blue-600">Sa note globale</RouterLink>
    </div>
    <ul v-if="ratings.length" class="divide-y">
      <li v-for="r in ratings" :key="r.id" :class="['py-1.5', r.neutralized_at ? 'opacity-60' : '']">
        <div class="flex flex-wrap items-center gap-2">
          <StarRating :model-value="r.stars" readonly size="h-4 w-4" />
          <span class="text-xs rounded bg-slate-100 px-1.5">{{ SOURCES[r.source] }}</span>
          <span class="text-xs text-slate-600">{{ r.courier.name }} · {{ r.author || r.merchant || '' }}</span>
          <span v-if="r.neutralized_at" class="text-xs text-slate-600">neutralisée</span>
        </div>
        <p v-if="r.reasons?.length" class="flex flex-wrap gap-1 pt-0.5">
          <span v-for="k in r.reasons" :key="k" class="rounded-full bg-red-50 px-2 text-xs text-red-700">{{ reasonLabel(k) }}</span>
        </p>
        <p v-if="r.comment" class="text-slate-700">« {{ r.comment }} »</p>
      </li>
    </ul>
    <p v-else class="text-slate-600">Aucune note sur cette course.</p>

    <form v-if="couriers.length" class="space-y-3 border-t pt-3" @submit.prevent="save">
      <div class="flex flex-wrap items-center gap-2">
        <select v-if="couriers.length > 1" v-model="form.courier_id" class="input w-auto" aria-label="Livreur">
          <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <span v-else class="text-slate-600">Votre note pour <b>{{ couriers[0].name }}</b> :</span>
        <StarRating v-model="form.stars" size="h-6 w-6" label="Note de l'équipe" />
        <b v-if="chosen" :class="form.stars <= reasonBelow ? 'text-red-700' : 'text-amber-700'">{{ form.stars }} · {{ chosen.label }}</b>
      </div>
      <!-- Ce que veut dire chaque étoile -->
      <div class="grid grid-cols-2 gap-1.5 sm:grid-cols-5">
        <button
          v-for="m in stars" :key="m.value" type="button"
          :class="['rounded-lg px-2 py-1.5 text-left text-xs ring-1', form.stars === m.value ? 'bg-amber-50 ring-2 ring-amber-400' : 'bg-white ring-slate-200']"
          :aria-pressed="form.stars === m.value" @click="form.stars = m.value"
        >
          <b class="block">{{ m.value }} ★ {{ m.label }}</b><span class="text-slate-600">{{ m.description }}</span>
        </button>
      </div>
      <!-- Ce qui n'est pas allé : liste déroulante, chaque choix s'ajoute (obligatoire si la note est basse) -->
      <div v-if="form.stars">
        <label class="label" for="team-reason">
          Ce qui n'est pas allé
          <span v-if="low">*</span><span v-else class="font-normal text-slate-600">(facultatif)</span>
        </label>
        <select id="team-reason" class="input sm:w-80" :value="''" @change="addReason">
          <option value="">{{ form.reasons.length ? 'Ajouter un autre motif…' : 'Choisir un motif…' }}</option>
          <option v-for="c in reasonChoices" :key="c.value" :value="c.value">{{ c.label }}</option>
        </select>
        <div v-if="form.reasons.length" class="mt-2 flex flex-wrap gap-1.5">
          <button v-for="k in form.reasons" :key="k" type="button" class="rounded-full bg-red-50 px-3 py-1 text-xs text-red-700 ring-1 ring-red-200" :aria-label="`Retirer : ${reasonLabel(k)}`" @click="form.reasons = form.reasons.filter((x) => x !== k)">
            {{ reasonLabel(k) }} <Icone nom="fermer" taille="petite" class="inline align-text-bottom" />
          </button>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <input v-model="form.comment" class="input flex-1 min-w-40" placeholder="Commentaire (équipe)">
        <button class="btn-secondary" :disabled="!form.stars || (low && !form.reasons.length) || saving">Noter</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import Icone from './ui/Icone.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import StarRating from './StarRating.vue'
import { useToastStore } from '../stores/toasts'

const props = defineProps({ order: { type: Object, required: true } })
const toasts = useToastStore()
const SOURCES = { client: 'Client', team: 'Équipe', recipient: 'Destinataire' }
const ratings = ref([])
const saving = ref(false)
// Livreurs de la course : livraison d'abord
const couriers = computed(() => [props.order.delivery_courier, props.order.pickup_courier, props.order.return_courier]
  .filter(Boolean).filter((c, i, list) => list.findIndex((x) => x.id === c.id) === i))
const form = reactive({ courier_id: null, stars: null, reasons: [], comment: '' })
// Sens des étoiles, motifs proposés (Réglages › Notation des livreurs), note basse à partir de laquelle un motif est demandé
const criteria = ref({ team_stars: [], team_reasons: [], team_reason_choices: [], settings: {} })
const stars = computed(() => criteria.value.team_stars || [])
const chosen = computed(() => stars.value.find((m) => m.value === form.stars))
const reasonBelow = computed(() => criteria.value.settings?.reason_below ?? 2)
const low = computed(() => !!form.stars && form.stars <= reasonBelow.value)
const reasonChoices = computed(() => (criteria.value.team_reason_choices || criteria.value.team_reasons || []).filter((c) => !form.reasons.includes(c.value)))
const reasonLabel = (k) => (criteria.value.team_reasons || []).find((c) => c.value === k)?.label
  || (criteria.value.reasons || []).find((c) => c.value === k)?.label || k

function addReason(event) {
  const value = event.target.value
  if (value && !form.reasons.includes(value)) form.reasons = [...form.reasons, value]
  event.target.value = ''
}

async function load() {
  ratings.value = (await http.get(`/orders/${props.order.id}/ratings`).catch(() => ({ data: { data: [] } }))).data.data
}

async function save() {
  saving.value = true
  try {
    await http.post(`/couriers/${form.courier_id || couriers.value[0].id}/ratings`, { order_id: props.order.id, stars: form.stars, reasons: form.reasons, comment: form.comment || null })
    toasts.success('Note enregistrée.')
    Object.assign(form, { stars: null, reasons: [], comment: '' })
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  form.courier_id = couriers.value[0]?.id ?? null
  load()
  http.get('/ratings/criteria').then(({ data }) => { criteria.value = data.data }).catch(() => {})
})
</script>
