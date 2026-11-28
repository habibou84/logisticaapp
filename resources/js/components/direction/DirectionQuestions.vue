<template>
  <div class="space-y-4">
    <p v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</p>

    <!-- Clé Anthropic non réglée : ce que fait la fonction et comment l'activer -->
    <section v-if="enabled === false" class="rounded-xl border border-dashed border-slate-400 bg-slate-50 p-5 space-y-3">
      <span class="inline-block rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">Non activé</span>
      <h2 class="text-lg font-semibold">Posez vos questions en français, l'application répond avec vos chiffres</h2>
      <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
        <li><strong class="text-slate-900">Vous écrivez une question</strong> comme à un collaborateur : « Quelles zones ont le plus d'échecs ce mois ? », « Quel client a livré le plus de colis le mois dernier ? ».</li>
        <li><strong class="text-slate-900">L'IA comprend la question</strong> et choisit le bon calcul : quel indicateur, quelle activité, quelle période. Elle ne reçoit que le texte de la question.</li>
        <li><strong class="text-slate-900">L'application calcule et affiche la réponse</strong>. Aucun chiffre de l'entreprise n'est envoyé à Anthropic, et rien n'est modifié.</li>
      </ul>
      <p class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900">
        <strong>Pour l'activer</strong> : réglez la clé Anthropic sur le serveur (<code class="rounded bg-white px-1">ANTHROPIC_API_KEY</code>). Dès que la clé est en place, cet écran s'active tout seul.
        C'est la même clé que pour la lecture des commandes WhatsApp. L'accès se donne ensuite par l'autorisation « Poser des questions aux statistiques (IA) » dans les rôles.
      </p>
      <div class="flex flex-wrap gap-2">
        <label class="sr-only" for="ask-off">Votre question</label>
        <input id="ask-off" type="text" class="input min-w-0 flex-1" placeholder="Activez la clé Anthropic pour poser une question" disabled>
        <button type="button" class="btn-primary" disabled>Demander</button>
      </div>
    </section>

    <section v-else-if="enabled" class="card space-y-3 p-4">
      <div>
        <h2 class="font-semibold">Posez une question sur votre activité</h2>
        <p class="text-xs text-slate-600">L'IA comprend la question, l'application calcule la réponse. Seule la question est envoyée à Anthropic, jamais vos chiffres.</p>
      </div>
      <form class="flex flex-wrap gap-2" @submit.prevent="ask(question)">
        <label class="sr-only" for="ask-question">Votre question</label>
        <input id="ask-question" v-model="question" type="text" maxlength="500" class="input min-w-0 flex-1" placeholder="Par exemple : quelles zones ont le plus d'échecs ce mois ?">
        <button type="submit" class="btn-primary" :disabled="asking || question.trim().length < 5">{{ asking ? 'Recherche…' : 'Demander' }}</button>
      </form>
      <div class="flex flex-wrap gap-1.5">
        <button v-for="e in EXAMPLES" :key="e" type="button" class="rounded-full border bg-white px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50" :disabled="asking" @click="ask(e)">{{ e }}</button>
      </div>

      <div v-if="answer" class="space-y-3 rounded-xl border p-4" aria-live="polite">
        <p class="font-semibold">{{ answer.question }}</p>
        <div v-if="answer.understood.length" class="flex flex-wrap items-center gap-1.5 text-xs">
          <span class="text-slate-600">L'IA a compris :</span>
          <span v-for="u in answer.understood" :key="u" class="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 font-semibold text-blue-800">{{ u }}</span>
        </div>
        <p v-if="answer.reason" class="text-sm text-slate-600">{{ answer.reason }}</p>

        <template v-if="answer.kind === 'table'">
          <p v-if="!answer.table.length" class="text-sm text-slate-600">Aucune course sur cette période.</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-slate-50 text-left text-slate-600">
                <tr>
                  <th class="p-2">{{ answer.rows_label }}</th>
                  <th v-for="c in answer.columns" :key="c.key" class="p-2 text-right">{{ c.label }}</th>
                  <th class="p-2 text-right">{{ answer.columns.length ? 'Total' : answer.metric_label }}</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <tr v-for="r in answer.table" :key="r.key">
                  <td class="p-2 font-medium">{{ r.label }}</td>
                  <td v-for="(v, i) in r.cells" :key="i" class="p-2 text-right whitespace-nowrap">{{ fmt(v) }}</td>
                  <td class="p-2 text-right font-semibold whitespace-nowrap">{{ fmt(r.total) }}</td>
                </tr>
              </tbody>
              <tfoot class="border-t font-semibold">
                <tr>
                  <td class="p-2">Ensemble ({{ answer.count }} ligne(s))</td>
                  <td v-for="(v, i) in answer.totals.cells" :key="i" class="p-2 text-right whitespace-nowrap">{{ fmt(v) }}</td>
                  <td class="p-2 text-right whitespace-nowrap">{{ fmt(answer.totals.total) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p class="border-t pt-2 text-xs text-slate-600">
            Calculé par l'application sur {{ number(answer.orders) }} course(s) créées du {{ date(answer.period.from) }} au {{ date(answer.period.to) }}<template v-if="answer.shown < answer.count"> · {{ answer.shown }} ligne(s) affichées sur {{ answer.count }}</template>.
            Vérifiez que l'IA a bien compris la question avant de vous appuyer sur ce tableau.
          </p>
        </template>
        <RouterLink v-else-if="answer.kind === 'view'" :to="answer.href" class="btn-secondary inline-flex">Ouvrir la vue qui répond</RouterLink>
      </div>
    </section>
    <p v-else-if="!error" class="text-slate-600">Chargement…</p>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { date, money } from '../../utils/format'
import { number } from './shared'

const EXAMPLES = [
  'Quelles zones ont le plus d\'échecs ce mois ?',
  'Quels sont les 5 clients qui ont le plus de courses livrées le mois dernier ?',
  'Quel jour de la semaine a-t-on le plus de courses en e-commerce ?',
  'Quelle activité rapporte le plus de marge ?',
]

const enabled = ref(null)
const question = ref('')
const asking = ref(false)
const answer = ref(null)
const error = ref('')

function fmt(v) {
  if (v === null || v === undefined) return '—'
  if (answer.value.metric === 'revenue') return money(v)
  if (answer.value.metric === 'success_rate') return `${Number(v).toLocaleString('fr-FR')} %`
  return Number(v).toLocaleString('fr-FR')
}

async function ask(text) {
  const wanted = (text || '').trim()
  if (wanted.length < 5 || asking.value) return
  question.value = wanted
  asking.value = true
  error.value = ''
  try {
    answer.value = (await http.post('/stats/direction/questions', { question: wanted })).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    asking.value = false
  }
}

http.get('/stats/direction/questions').then((r) => { enabled.value = r.data.data.enabled }).catch((e) => { error.value = apiErrorMessage(e) })
</script>
