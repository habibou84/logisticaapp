<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Qualité des livreurs</h1>
        <p class="text-sm text-gray-600">Note sur 5 des {{ settings.window_days || 90 }} derniers jours : automatique, clients et équipe (Réglages › Notation des livreurs).</p>
      </div>
      <RouterLink v-if="auth.can('ratings.manage')" to="/admin/reglages/notation" class="btn-secondary">⚙️ Réglages</RouterLink>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm stack-table">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Livreur</th><th class="p-2">Note</th><th class="p-2">Automatique</th><th class="p-2">Clients</th><th class="p-2">Équipe</th><th class="p-2" /></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="c in rows" :key="c.id" class="hover:bg-slate-50 cursor-pointer" @click="open(c.id)">
            <td class="p-2 font-medium">{{ c.name }} <span v-if="c.alert" class="ml-1 rounded-full bg-red-50 px-2 py-0.5 text-xs text-red-700">à suivre</span></td>
            <td data-label="Note" class="p-2">
              <span v-if="c.new" class="text-gray-500">Nouveau</span>
              <span v-else class="inline-flex items-center gap-2"><strong :class="scoreClass(c.score)">{{ c.score.toFixed(1) }}</strong><StarRating :model-value="c.score" readonly size="h-4 w-4" /></span>
            </td>
            <td data-label="Automatique" class="p-2">{{ c.auto ?? '—' }}</td>
            <td data-label="Clients" class="p-2">{{ c.client ?? '—' }} <span class="text-xs text-gray-500">({{ c.client_count }})</span></td>
            <td data-label="Équipe" class="p-2">{{ c.team ?? '—' }} <span class="text-xs text-gray-500">({{ c.team_count }})</span></td>
            <td class="p-2 text-right"><span v-if="c.bad_last_7_days" class="text-xs text-red-700">{{ c.bad_last_7_days }} mauvaise(s) note(s) en 7 jours</span></td>
          </tr>
          <tr v-if="!rows.length"><td colspan="6" class="p-6 text-center text-gray-500">{{ loading ? 'Chargement…' : 'Aucun livreur.' }}</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Détail d'un livreur -->
    <Modal :open="!!detail" :title="detail ? `Note de ${detail.courier.name}` : ''" @close="close">
      <div v-if="detail" class="space-y-4 text-sm">
        <div class="flex flex-wrap items-center gap-3">
          <strong :class="['text-4xl', scoreClass(detail.score)]">{{ detail.score?.toFixed(1) ?? '—' }}</strong>
          <div>
            <StarRating :model-value="detail.score" readonly size="h-6 w-6" />
            <p class="text-xs text-gray-500">{{ detail.new ? 'Pas encore assez de données' : `du ${date(detail.from)} au ${date(detail.to)}` }}</p>
          </div>
          <div class="ml-auto flex gap-2 text-xs">
            <span v-for="t in detail.trend" :key="t.month" class="rounded bg-slate-100 px-2 py-1">{{ monthLabel(t.month) }} : <strong>{{ t.score ?? '—' }}</strong></span>
          </div>
        </div>

        <div class="grid sm:grid-cols-3 gap-2">
          <div v-for="s in sources" :key="s.key" :class="['rounded-lg border p-2', detail.weights[s.key]?.used ? 'border-slate-200' : 'border-dashed border-slate-200 text-gray-400']">
            <p class="text-xs text-gray-500">{{ s.label }} · {{ detail.weights[s.key]?.weight }} %</p>
            <p class="text-lg font-semibold">{{ s.value ?? '—' }}</p>
            <p class="text-xs">{{ s.hint }}</p>
          </div>
        </div>

        <div>
          <h3 class="font-semibold mb-1">Note automatique</h3>
          <ul class="space-y-1">
            <li v-for="i in detail.auto.indicators" :key="i.key" class="flex items-center gap-2">
              <span class="w-56 shrink-0">{{ i.label }}</span>
              <span class="h-2 flex-1 rounded bg-slate-100 overflow-hidden"><span class="block h-2" :class="i.value >= 90 ? 'bg-emerald-500' : i.value >= 70 ? 'bg-amber-500' : 'bg-red-500'" :style="{ width: `${i.value ?? 0}%` }" /></span>
              <span class="w-12 text-right font-medium">{{ i.value === null ? '—' : `${i.value} %` }}</span>
              <span class="hidden md:inline w-48 text-xs text-gray-500 truncate">{{ i.detail }}</span>
            </li>
          </ul>
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
          <div v-if="Object.keys(detail.client.criteria).length || Object.keys(detail.client.reasons).length">
            <h3 class="font-semibold mb-1">Ce que disent les clients</h3>
            <p class="flex flex-wrap gap-1">
              <span v-for="(n, k) in detail.client.criteria" :key="k" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800">{{ label('client', k) }} · {{ n }}</span>
              <span v-for="(n, k) in detail.client.reasons" :key="k" class="rounded-full bg-red-50 px-2 py-0.5 text-xs text-red-700">{{ label('reasons', k) }} · {{ n }}</span>
            </p>
          </div>
          <div v-if="Object.keys(detail.team.criteria).length">
            <h3 class="font-semibold mb-1">Critères de l'équipe</h3>
            <p v-for="(v, k) in detail.team.criteria" :key="k" class="flex justify-between"><span>{{ label('team', k) }}</span><strong>{{ v }} / 5</strong></p>
          </div>
        </div>

        <!-- Évaluation de l'équipe -->
        <form class="rounded-lg border p-3 space-y-2" @submit.prevent="evaluate">
          <h3 class="font-semibold">Évaluer {{ detail.courier.name }} (ce mois-ci)</h3>
          <StarRating v-model="evaluation.stars" size="h-7 w-7" label="Note globale" />
          <div class="grid sm:grid-cols-2 gap-x-4 gap-y-1">
            <div v-for="c in criteria.team" :key="c.value" class="flex items-center justify-between gap-2">
              <span>{{ c.label }}</span>
              <StarRating v-model="evaluation.criteria[c.value]" size="h-4 w-4" :label="c.label" />
            </div>
          </div>
          <textarea v-model="evaluation.comment" rows="2" class="input" placeholder="Commentaire (vu seulement par l'équipe)" />
          <p v-if="evaluation.error" class="field-error">{{ evaluation.error }}</p>
          <button class="btn-primary" :disabled="!evaluation.stars || evaluation.saving">Enregistrer l'évaluation</button>
        </form>

        <div>
          <h3 class="font-semibold mb-1">Dernières notes</h3>
          <ul class="divide-y rounded border">
            <li v-for="r in detail.ratings" :key="r.id" :class="['p-2 space-y-0.5', r.neutralized_at ? 'opacity-60' : '']">
              <div class="flex flex-wrap items-center gap-2">
                <StarRating :model-value="r.stars" readonly size="h-4 w-4" />
                <span class="text-xs rounded bg-slate-100 px-1.5">{{ SOURCES[r.source] }}{{ r.scope === 'period' ? ' · période' : '' }}</span>
                <span class="text-xs text-gray-600">{{ r.merchant || r.author || '' }}</span>
                <RouterLink v-if="r.order" :to="`/admin/courses/${r.order.id}`" class="text-xs font-mono text-blue-600">{{ r.order.tracking_code }}</RouterLink>
                <span class="text-xs text-gray-500 ml-auto">{{ dateTime(r.created_at) }}<template v-if="r.weight < 1"> · compte {{ r.weight * 100 }} %</template></span>
              </div>
              <p v-if="r.reasons.length || r.criteria.length" class="flex flex-wrap gap-1">
                <span v-for="k in r.reasons" :key="k" class="rounded-full bg-red-50 px-2 text-xs text-red-700">{{ label('reasons', k) }}</span>
                <span v-for="k in (Array.isArray(r.criteria) ? r.criteria : [])" :key="k" class="rounded-full bg-emerald-50 px-2 text-xs text-emerald-800">{{ label('client', k) }}</span>
              </p>
              <p v-if="r.comment">« {{ r.comment }} »</p>
              <p v-if="r.neutralized_at" class="text-xs text-gray-600">Neutralisée : {{ r.neutralized_reason }}
                <button v-if="auth.can('ratings.manage')" type="button" class="text-blue-600 ml-1" @click="restore(r)">Rétablir</button>
              </p>
              <button v-else-if="auth.can('ratings.manage') && r.source !== 'team'" type="button" class="text-xs text-red-700" @click="neutralize(r)">Neutraliser…</button>
            </li>
            <li v-if="!detail.ratings.length" class="p-3 text-gray-500">Aucune note pour le moment.</li>
          </ul>
        </div>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import StarRating from '../../components/StarRating.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime } from '../../utils/format'

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()
const rows = ref([])
const settings = ref({})
const loading = ref(true)
const detail = ref(null)
const criteria = ref({ client: [], reasons: [], team: [], indicators: [] })
const evaluation = reactive({ stars: null, criteria: {}, comment: '', saving: false, error: '' })
const SOURCES = { client: 'Client', team: 'Équipe', recipient: 'Destinataire' }

const scoreClass = (s) => (s === null || s === undefined ? 'text-gray-400' : s >= 4 ? 'text-emerald-700' : s >= 3 ? 'text-amber-700' : 'text-red-700')
const label = (group, key) => criteria.value[group]?.find((c) => c.value === key)?.label || key
const monthLabel = (m) => new Date(`${m}-15`).toLocaleDateString('fr-FR', { month: 'short' })
const sources = computed(() => (detail.value ? [
  { key: 'auto', label: 'Automatique', value: detail.value.auto.score, hint: 'faits enregistrés' },
  { key: 'client', label: 'Clients', value: detail.value.client.average, hint: detail.value.client.counted ? `${detail.value.client.count} note(s)` : `${detail.value.client.count} / ${detail.value.client.min} note(s) minimum` },
  { key: 'team', label: 'Équipe', value: detail.value.team.average, hint: `${detail.value.team.count} note(s)` },
] : []))

async function load() {
  loading.value = true
  try {
    const { data } = await http.get('/courier-quality')
    rows.value = data.data
    settings.value = data.settings
  } finally {
    loading.value = false
  }
}

async function open(id) {
  detail.value = (await http.get(`/couriers/${id}/score`)).data.data
  Object.assign(evaluation, { stars: null, criteria: {}, comment: '', error: '' })
  router.replace({ query: { livreur: id } })
}

function close() {
  detail.value = null
  router.replace({ query: {} })
}

async function evaluate() {
  evaluation.saving = true
  evaluation.error = ''
  try {
    await http.post(`/couriers/${detail.value.courier.id}/ratings`, { stars: evaluation.stars, criteria: evaluation.criteria, comment: evaluation.comment || null })
    toasts.success('Évaluation enregistrée.')
    await Promise.all([open(detail.value.courier.id), load()])
  } catch (e) {
    evaluation.error = apiErrorMessage(e)
  } finally {
    evaluation.saving = false
  }
}

async function neutralize(r) {
  const reason = window.prompt('Pourquoi cette note ne doit-elle plus compter ? (ex. client mécontent du prix, pas du livreur)')
  if (!reason) return
  await http.post(`/courier-ratings/${r.id}/neutralize`, { reason })
  await Promise.all([open(detail.value.courier.id), load()])
}

async function restore(r) {
  await http.post(`/courier-ratings/${r.id}/restore`)
  await Promise.all([open(detail.value.courier.id), load()])
}

onMounted(async () => {
  criteria.value = (await http.get('/ratings/criteria')).data.data
  await load()
  if (route.query.livreur) open(Number(route.query.livreur))
})
</script>
