<template>
  <div class="max-w-3xl space-y-4">
    <div>
      <h1 class="text-xl font-bold">🧭 Mise en route</h1>
      <p class="text-sm text-gray-600">Étape par étape, ce qu'il faut régler pour que l'application travaille bien. Chaque étape se coche toute seule quand c'est fait.</p>
    </div>

    <p v-if="error" class="field-error">{{ error }}</p>

    <template v-if="guide">
      <!-- Progression -->
      <section :class="['card p-4 space-y-3', guide.ready ? 'bg-emerald-50' : '']">
        <p class="font-semibold">
          {{ guide.ready ? '✅ Les étapes essentielles sont faites : l\'application est prête à travailler.' : `Étapes essentielles : ${guide.essentials.done} sur ${guide.essentials.total}` }}
        </p>
        <div class="h-2 rounded-full bg-slate-200 overflow-hidden" role="progressbar" :aria-valuenow="guide.essentials.done" :aria-valuemax="guide.essentials.total" aria-label="Étapes essentielles faites">
          <div class="h-full bg-emerald-600 transition-all" :style="{ width: `${percent(guide.essentials)}%` }" />
        </div>
        <p class="text-sm text-gray-600">Étapes conseillées : {{ guide.recommended.done }} sur {{ guide.recommended.total }}.</p>
        <p v-if="next" class="text-sm">
          Prochaine étape : <strong>{{ next.title }}</strong>
          <RouterLink :to="link(next)" class="ml-1 text-blue-600 underline">{{ next.action.label }}</RouterLink>
        </p>
      </section>

      <!-- Bilan de santé : ce qui se dérègle après la mise en route -->
      <section class="card p-4 space-y-2">
        <h2 class="font-semibold">🩺 Bilan de santé</h2>
        <p v-if="!guide.health.length" class="text-sm text-emerald-800">Rien à signaler pour le moment.</p>
        <ul v-else class="space-y-1">
          <li v-for="h in guide.health" :key="h.text" class="flex flex-wrap items-start justify-between gap-2 text-sm">
            <span :class="h.level === 'alert' ? 'text-red-700' : 'text-amber-800'">{{ h.level === 'alert' ? '🔴' : '🟠' }} {{ h.text }}</span>
            <RouterLink :to="{ path: h.to, query: { depuis: 'mise-en-route' } }" class="text-blue-600 underline whitespace-nowrap">Corriger</RouterLink>
          </li>
        </ul>
      </section>

      <section v-for="group in groups" :key="group.title" class="space-y-2">
        <h2 class="font-semibold">{{ group.title }}</h2>
        <p class="text-sm text-gray-500">{{ group.intro }}</p>
        <ol class="space-y-2">
          <li v-for="(s, i) in group.steps" :key="s.key" :class="['card p-4 space-y-2', s.skipped ? 'opacity-60' : '']">
            <div class="flex items-start gap-3">
              <span class="text-xl leading-none" :title="STATUS[s.status].label" aria-hidden="true">{{ STATUS[s.status].icon }}</span>
              <div class="min-w-0 flex-1 space-y-1">
                <p class="font-semibold">
                  {{ group.essential ? `${i + 1}. ` : '' }}{{ s.title }}
                  <span :class="['ml-1 rounded-full px-2 py-0.5 text-xs font-medium', STATUS[s.status].cls]">{{ s.skipped ? 'Plus tard' : STATUS[s.status].label }}</span>
                </p>
                <p class="text-sm text-gray-600">{{ s.why }}</p>
                <ul class="text-sm text-gray-800 space-y-0.5">
                  <li v-for="line in s.lines" :key="line">{{ line }}</li>
                </ul>
                <!-- Course d'essai : où elle en est -->
                <template v-if="s.test">
                  <ol class="mt-2 space-y-1 text-sm">
                    <li v-for="c in s.test.checkpoints" :key="c.label" :class="c.done ? 'text-emerald-800' : 'text-gray-500'">
                      {{ c.done ? '✅' : '⬜' }} {{ c.label }}
                    </li>
                  </ol>
                  <p class="text-xs text-amber-800 bg-amber-50 rounded px-2 py-1">
                    Ne versez pas l'argent de cette course à la caisse : une fois versé, la course d'essai ne peut plus être effacée.
                  </p>
                </template>
              </div>
            </div>
            <div class="flex flex-wrap gap-2 sm:pl-9">
              <template v-if="s.key === 'first_delivery' && !s.test">
                <button type="button" :class="s.status === 'done' ? 'btn-secondary' : 'btn-primary'" :disabled="busy" @click="startTest">
                  {{ s.status === 'done' ? 'Faire une autre course d\'essai' : 'Lancer une course d\'essai' }}
                </button>
              </template>
              <RouterLink v-else :to="link(s)" :class="s.status === 'done' && !s.test ? 'btn-secondary' : 'btn-primary'">{{ s.status === 'done' && !s.test ? 'Revoir' : s.action.label }}</RouterLink>
              <button v-if="s.test" type="button" class="btn-secondary text-red-700" :disabled="busy" @click="cleanTest">Effacer la course d'essai</button>
              <button v-if="!s.essential && s.status !== 'done'" type="button" class="btn-secondary" :disabled="busy" @click="skip(s, !s.skipped)">
                {{ s.skipped ? 'Reprendre cette étape' : 'Plus tard' }}
              </button>
            </div>
          </li>
        </ol>
      </section>
    </template>

    <p v-else-if="!error" class="text-sm text-gray-500">Chargement…</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'

/**
 * Parcours de mise en route : étapes essentielles puis conseillées, vérifiées par le serveur sur les vraies données.
 */
const STATUS = {
  done: { icon: '✅', label: 'Fait', cls: 'bg-emerald-100 text-emerald-800' },
  partial: { icon: '🟠', label: 'À compléter', cls: 'bg-amber-100 text-amber-800' },
  todo: { icon: '⭕', label: 'À faire', cls: 'bg-slate-100 text-slate-700' },
}

const router = useRouter()
const guide = ref(null)
const error = ref('')
const busy = ref(false)

const percent = (c) => (c.total ? Math.round((c.done / c.total) * 100) : 0)
// Le réglage ouvert ramène au parcours (bouton « Revenir à la mise en route »)
const link = (s) => ({ path: s.action.to, query: { depuis: 'mise-en-route' } })

const groups = computed(() => [
  { title: 'Étapes essentielles', intro: 'Sans elles, les courses ne peuvent pas être créées, confiées ou payées correctement.', essential: true, steps: guide.value.steps.filter((s) => s.essential) },
  { title: 'Étapes conseillées', intro: 'Elles améliorent le service. Vous pouvez les remettre à plus tard.', essential: false, steps: guide.value.steps.filter((s) => !s.essential) },
])
const next = computed(() => guide.value?.steps.find((s) => s.status !== 'done' && !s.skipped))

async function load() {
  try {
    guide.value = (await http.get('/setup')).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

// Course d'essai : créée puis ouverte ; effacée avec tout ce qu'elle a produit
async function startTest() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post('/setup/test-order')
    router.push({ path: `/admin/courses/${data.data.order_id}`, query: { depuis: 'mise-en-route' } })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function cleanTest() {
  if (!confirm('Effacer la course d\'essai ? Son journal, les gains du livreur et l\'argent encaissé sont effacés aussi.')) return
  busy.value = true
  error.value = ''
  try {
    guide.value = (await http.delete('/setup/test-order')).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function skip(step, skipped) {
  busy.value = true
  try {
    guide.value = (await http.post('/setup/skip', { step: step.key, skipped })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  await load()
  // Parcours ouvert : il ne s'ouvrira plus tout seul à la connexion
  if (guide.value && !guide.value.seen) http.post('/setup/seen').catch(() => {})
})
</script>
