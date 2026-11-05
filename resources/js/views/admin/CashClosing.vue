<template>
  <div class="space-y-4">
    <div>
      <RouterLink to="/admin/caisse" class="text-sm font-semibold text-emerald-700 hover:underline">‹ Caisse</RouterLink>
      <h1 class="text-xl font-bold">Clôture du jour</h1>
      <p class="text-sm text-gray-600">Comptez l'argent de la caisse. Jibiat compare avec ce qui devrait y être et note l'écart.</p>
    </div>

    <p v-if="!loaded" class="text-gray-500">Chargement…</p>

    <!-- Journée déjà clôturée -->
    <template v-else-if="closed">
      <div class="card border-emerald-200 bg-emerald-50 p-4">
        <p class="font-semibold text-emerald-900">🔒 Caisse du {{ date(closed.day) }} clôturée à {{ time(closed.closed_at) }} par {{ closed.closed_by }}</p>
        <p class="text-sm text-emerald-900">Ce qui arrive maintenant passera sur la clôture de demain.</p>
      </div>
      <ClosingDetail :closing="closed" />
    </template>

    <!-- Compter et clôturer -->
    <template v-else-if="figures">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <StatCard title="Entré" :value="money(figures.total_in)" icon="⬇️" :subtitle="`depuis ${since}`" />
        <StatCard title="Sorti" :value="money(figures.total_out)" icon="⬆️" subtitle="clients, paie, avances" />
        <StatCard title="Encore chez les livreurs" :value="money(figures.with_couriers)" icon="🛵" subtitle="pas dans la caisse" />
      </div>

      <div class="card overflow-x-auto">
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Moyen</th><th class="p-2 text-right">Début</th><th class="p-2 text-right">+ Entré</th><th class="p-2 text-right">− Sorti</th><th class="p-2 text-right">Devrait y avoir</th><th class="p-2">Compté</th><th class="p-2 text-right">Écart</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="l in figures.lines" :key="l.method">
              <td class="p-2 font-medium">{{ l.method === 'cash' ? 'Espèces (tiroir)' : l.label }}</td>
              <td data-label="Début" class="p-2 text-right">{{ l.start === null ? '—' : money(l.start) }}</td>
              <td data-label="Entré" class="p-2 text-right">{{ money(l.in) }}</td>
              <td data-label="Sorti" class="p-2 text-right">{{ l.out ? `− ${money(l.out)}` : money(0) }}</td>
              <td data-label="Devrait y avoir" class="p-2 text-right font-semibold">{{ l.expected === null ? '—' : money(l.expected) }}</td>
              <td data-label="Compté" class="p-2">
                <label class="sr-only" :for="`counted-${l.method}`">{{ l.method === 'cash' ? 'Espèces comptées' : `Solde ${l.label}` }}</label>
                <input :id="`counted-${l.method}`" v-model.number="counted[l.method]" type="number" min="0" class="input w-36" :placeholder="l.method === 'cash' ? 'Obligatoire' : 'Solde du compte'" :disabled="!canClose">
              </td>
              <td data-label="Écart" :class="['p-2 text-right font-semibold', gapClass(gap(l))]">{{ gap(l) === null ? '—' : signed(gap(l)) }}</td>
            </tr>
          </tbody>
        </table>
        <p class="px-3 py-2 text-xs text-gray-500">
          « Début » : le fond laissé ou le solde compté à la dernière clôture. Premier comptage d'un moyen : il sert de départ, sans écart.
          Les moyens laissés vides ne sont pas vérifiés.
        </p>
      </div>

      <form v-if="canClose" class="grid gap-4 md:grid-cols-[3fr_2fr]" @submit.prevent="submit">
        <div :class="['card p-4 space-y-3', totalGap ? 'border-red-200 bg-red-50' : '']">
          <p v-if="totalGap" class="font-semibold text-red-800">Écart : {{ totalGap > 0 ? 'il y a' : 'il manque' }} {{ money(Math.abs(totalGap)) }}</p>
          <p v-else-if="counted.cash !== '' && counted.cash !== undefined" class="font-semibold text-emerald-800">Aucun écart</p>
          <div v-if="totalGap">
            <label class="label" for="closing-explanation">Explication (obligatoire s'il y a un écart)</label>
            <input id="closing-explanation" v-model="explanation" type="text" maxlength="500" class="input" placeholder="Ex. : monnaie rendue à un livreur">
          </div>
          <div>
            <label class="label" for="closing-float">Laissé dans le tiroir pour demain (fond de caisse)</label>
            <input id="closing-float" v-model.number="floatKept" type="number" min="0" class="input w-40">
          </div>
          <p v-if="counted.cash !== '' && counted.cash !== undefined" class="text-sm text-gray-700">
            Le reste ({{ money(Math.max(0, (counted.cash || 0) - (floatKept || 0))) }}) est noté comme déposé à la banque ou au coffre.
          </p>
        </div>
        <div class="card p-4 flex flex-col gap-2">
          <p class="font-semibold">Avant de clôturer</p>
          <p v-if="figures.with_couriers" class="text-sm text-gray-700">Des livreurs ont encore {{ money(figures.with_couriers) }}. Recevez-les avant, ou ils passeront demain.</p>
          <p class="text-sm text-gray-700">Une fois clôturée, la journée ne bouge plus : un versement oublié passe sur demain.</p>
          <p v-if="error" class="text-sm text-red-700" role="alert">{{ error }}</p>
          <button type="submit" class="btn-primary mt-auto" :disabled="saving">{{ saving ? 'Clôture…' : 'Clôturer la journée' }}</button>
        </div>
      </form>
      <p v-else class="text-sm text-gray-600">Seuls les responsables de la caisse peuvent clôturer.</p>
    </template>

    <!-- Historique -->
    <div class="card overflow-x-auto">
      <div class="flex flex-wrap items-center gap-2 border-b p-3">
        <h2 class="flex-1 font-semibold">Clôtures précédentes</h2>
        <button type="button" class="btn-secondary" :disabled="!history.length" @click="exportCsv">Exporter</button>
      </div>
      <table class="w-full text-sm stack-table">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Jour</th><th class="p-2 text-right">Entré</th><th class="p-2 text-right">Sorti</th><th class="p-2 text-right">Écart</th><th class="p-2">Par</th><th /></tr>
        </thead>
        <tbody class="divide-y">
          <template v-for="c in history" :key="c.id">
            <tr>
              <td class="p-2 font-medium">{{ date(c.day) }}</td>
              <td data-label="Entré" class="p-2 text-right">{{ money(c.total_in) }}</td>
              <td data-label="Sorti" class="p-2 text-right">{{ money(c.total_out) }}</td>
              <td data-label="Écart" :class="['p-2 text-right font-semibold', gapClass(c.difference)]">{{ signed(c.difference) }}<span v-if="c.explanation" class="block text-xs font-normal text-gray-600">« {{ c.explanation }} »</span></td>
              <td data-label="Par" class="p-2">{{ c.closed_by }}</td>
              <td class="p-2 text-right"><button type="button" class="text-blue-600" @click="opened = opened === c.id ? null : c.id">{{ opened === c.id ? 'Masquer' : 'Détail' }}</button></td>
            </tr>
            <tr v-if="opened === c.id"><td colspan="6" class="p-2 bg-slate-50"><ClosingDetail :closing="c" /></td></tr>
          </template>
          <tr v-if="!history.length"><td colspan="6" class="p-4 text-center text-gray-500">Aucune clôture pour le moment.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http from '../../bootstrap/axios'
import StatCard from '../../components/StatCard.vue'
import { downloadCsv } from '../../components/stats/chart'
import { date, dateTime, money } from '../../utils/format'

const loaded = ref(false)
const closed = ref(null)
const figures = ref(null)
const canClose = ref(false)
const history = ref([])
const opened = ref(null)
const counted = reactive({})
const explanation = ref('')
const floatKept = ref(0)
const saving = ref(false)
const error = ref('')

const time = (v) => new Date(v).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const signed = (v) => (v > 0 ? `+ ${money(v)}` : v < 0 ? `− ${money(-v)}` : money(0))
const gapClass = (v) => (v === null || v === undefined ? '' : v === 0 ? 'text-emerald-700' : 'text-red-700')
const since = computed(() => (figures.value ? dateTime(figures.value.period_start) : ''))

function gap(line) {
  const value = counted[line.method]
  return value === '' || value === undefined || value === null || line.expected === null ? null : value - line.expected
}
const totalGap = computed(() => (figures.value?.lines || []).reduce((sum, l) => sum + (gap(l) || 0), 0))

async function load() {
  const [{ data: current }, { data: past }] = await Promise.all([http.get('/finance/closing'), http.get('/finance/closings')])
  closed.value = current.data.closed
  figures.value = current.data.figures
  canClose.value = current.data.can_close
  history.value = past.data
  floatKept.value = current.data.last?.float_kept ?? 0
  loaded.value = true
}

async function submit() {
  error.value = ''
  if (counted.cash === '' || counted.cash === undefined) {
    error.value = 'Comptez les espèces de la caisse.'
    return
  }
  if (!window.confirm('Clôturer la journée ? Elle ne pourra plus être modifiée.')) return
  saving.value = true
  try {
    const payload = Object.fromEntries(Object.entries(counted).filter(([, v]) => v !== '' && v !== undefined && v !== null))
    await http.post('/finance/closing', { counted: payload, explanation: explanation.value || null, float_kept: floatKept.value || 0 })
    await load()
  } catch (e) {
    error.value = e.response?.data?.message || 'La clôture a échoué.'
  } finally {
    saving.value = false
  }
}

function exportCsv() {
  downloadCsv(`clotures-caisse-${new Date().toISOString().slice(0, 10)}.csv`, [
    ['Jour', 'Entré', 'Sorti', 'Écart', 'Explication', 'Fond laissé', 'Déposé', 'Chez les livreurs', 'Par'],
    ...history.value.map((c) => [c.day, c.total_in, c.total_out, c.difference, c.explanation || '', c.float_kept, c.deposited, c.with_couriers, c.closed_by || '']),
  ])
}

// Détail d'une clôture : moyens, comptage, fond et dépôt
const ClosingDetail = defineComponent({
  props: { closing: { type: Object, required: true } },
  setup(props) {
    return () => {
      const c = props.closing
      return h('div', { class: 'card overflow-x-auto' }, [
        h('table', { class: 'w-full text-sm' }, [
          h('thead', { class: 'bg-slate-50 text-left text-gray-600' }, h('tr', ['Moyen', 'Début', 'Entré', 'Sorti', 'Attendu', 'Compté', 'Écart'].map((t, i) => h('th', { class: ['p-2', i ? 'text-right' : ''] }, t)))),
          h('tbody', { class: 'divide-y' }, c.lines.map((l) => h('tr', { key: l.method }, [
            h('td', { class: 'p-2' }, l.method === 'cash' ? 'Espèces' : l.label),
            ...[l.start, l.in, l.out, l.expected, l.counted].map((v) => h('td', { class: 'p-2 text-right' }, v === null || v === undefined ? '—' : money(v))),
            h('td', { class: ['p-2 text-right font-semibold', gapClass(l.difference)] }, l.difference === null || l.difference === undefined ? '—' : signed(l.difference)),
          ]))),
        ]),
        h('p', { class: 'px-3 py-2 text-sm text-gray-700' }, `Fond laissé : ${money(c.float_kept)} · Déposé : ${money(c.deposited)} · Encore chez les livreurs : ${money(c.with_couriers)}`
          + (c.explanation ? ` · Écart expliqué : « ${c.explanation} »` : '')),
      ])
    }
  },
})

onMounted(load)
</script>
