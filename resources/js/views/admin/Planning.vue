<template>
  <div class="space-y-4 max-w-5xl">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 v-if="!embedded" class="text-xl font-bold">📅 Planning des livreurs</h1>
        <p class="text-sm text-gray-600">Qui ramasse et qui livre où et quand. Livreur absent ou en pause : son remplaçant prend le relais.</p>
      </div>
      <button class="btn-primary" @click="openRule()">+ Nouvelle règle</button>
    </div>

    <!-- Ramassage automatique : état et lien vers le réglage -->
    <div v-if="board?.automation?.paused" class="card px-3 py-2 text-sm bg-red-50 text-red-900 flex flex-wrap items-center gap-2">
      <span class="flex-1 min-w-0">
        ⏸ Automatisation suspendue{{ board.automation.paused_by ? ` par ${board.automation.paused_by}` : '' }} :
        plus aucune course n'est confiée ni passée au remplaçant. Les alertes continuent.
      </span>
      <button class="btn-primary" :disabled="pausing" @click="pause(false)">Reprendre</button>
    </div>
    <div v-else-if="board" :class="['card px-3 py-2 text-sm flex flex-wrap items-center gap-2', AUTO[board.auto_pickup].cls]">
      <span class="flex-1 min-w-0">
        {{ AUTO[board.auto_pickup].text }}
        <template v-if="board.auto_delivery !== 'off'"> Livraison du jour même automatique {{ board.auto_delivery === 'on' ? 'active' : 'à l\'essai' }}.</template>
        <RouterLink v-if="auth.can('settings.manage')" to="/admin/reglages/courses" class="ml-1 font-medium underline">Régler</RouterLink>
      </span>
      <button v-if="board.auto_pickup !== 'off' || board.auto_delivery !== 'off'" class="btn-secondary" :disabled="pausing" @click="pause(true)">⏸ Suspendre l'automatisation</button>
    </div>

    <!-- Jour affiché -->
    <div class="flex flex-wrap items-center gap-2 text-sm">
      <button class="btn-secondary" aria-label="Jour précédent" @click="shift(-1)">←</button>
      <input v-model="date" type="date" class="input w-auto" aria-label="Jour" @change="load">
      <button class="btn-secondary" aria-label="Jour suivant" @click="shift(1)">→</button>
      <button v-if="date !== today" class="btn-secondary" @click="date = today; load()">Aujourd'hui</button>
      <span class="text-gray-600">{{ longDay(date) }}</span>
    </div>

    <template v-if="board">
      <!-- Ce jour-là : qui couvre quoi -->
      <section v-for="type in dayTypes" :key="type" class="card p-4 space-y-3">
        <h2 class="font-semibold">{{ TYPES[type] }}s ce jour-là</h2>
        <p v-if="!rulesOfDay(type).length" class="text-sm text-gray-500">Aucune règle ne s'applique ce jour-là.</p>
        <ul class="divide-y">
          <li v-for="r in rulesOfDay(type)" :key="r.id" class="py-2 flex flex-wrap items-center justify-between gap-2">
            <span class="min-w-0">
              <span class="block font-medium">{{ r.label.split(' · ')[0] }}</span>
              <span class="block text-xs text-gray-500">{{ r.when }}</span>
            </span>
            <span v-if="r.covering === 'courier'" class="rounded-full bg-emerald-50 px-2 py-0.5 text-sm font-medium text-emerald-800">🛵 {{ r.courier.name }}</span>
            <span v-else-if="r.covering === 'backup'" class="rounded-full bg-amber-50 px-2 py-0.5 text-sm font-medium text-amber-800" :title="`${r.courier.name} absent ou en pause`">🔁 {{ r.backup.name }} (remplace {{ r.courier.name }})</span>
            <span v-else class="rounded-full bg-red-50 px-2 py-0.5 text-sm font-medium text-red-700">⚠️ Personne : {{ r.courier.name }} absent{{ r.backup ? `, ${r.backup.name} aussi` : ', pas de remplaçant' }}</span>
          </li>
        </ul>

        <!-- Clients sans ramasseur : par zone (la plus chargée d'abord), une ligne par client -->
        <div v-if="type === 'pickup' && board.gaps.length" :class="['rounded-lg border p-3 space-y-3', gapTotals.waiting ? 'border-red-200 bg-red-50/60' : 'border-slate-200 bg-slate-50']">
          <div class="flex flex-wrap items-center gap-2">
            <p v-if="gapTotals.waiting" class="flex-1 text-sm font-semibold text-red-800">
              ⚠️ Clients sans ramasseur ce jour-là : {{ gapTotals.merchants }} client{{ gapTotals.merchants > 1 ? 's' : '' }}, {{ gapTotals.waiting }} colis à ramasser
            </p>
            <p v-else class="flex-1 text-sm text-slate-700">
              ✅ Aucun colis à ramasser sans ramasseur ce jour-là. Clients habituels qu'aucune règle ne couvre :
            </p>
            <input v-model="gapSearch" type="search" class="input sm:w-56" placeholder="Chercher un client" aria-label="Chercher un client sans ramasseur">
          </div>
          <p v-if="!gapZones.length" class="text-sm text-gray-600">Aucun client ne correspond.</p>
          <details v-for="g in gapZones" :key="g.zone?.id || 0" class="rounded-lg border border-slate-200 bg-white" :open="g.waiting > 0">
            <summary class="flex cursor-pointer flex-wrap items-center gap-2 px-3 py-2">
              <span class="font-semibold">{{ g.zone?.name || 'Sans zone' }}</span>
              <span v-if="g.waiting" class="rounded-full bg-red-600 px-2 text-xs font-semibold text-white">{{ g.waiting }} colis</span>
              <span class="text-xs text-gray-600"><template v-if="g.merchants.length">{{ g.merchants.length }} client{{ g.merchants.length > 1 ? 's' : '' }} à ramasser</template><template v-if="g.merchants.length && g.others.length"> · </template><template v-if="g.others.length">{{ g.others.length }} habituel{{ g.others.length > 1 ? 's' : '' }} sans colis</template></span>
              <span class="flex-1" />
              <button v-if="g.zone" type="button" class="text-sm font-medium text-red-800 underline" @click.prevent="openGapRule({ zone: g.zone })">Règle pour la zone</button>
            </summary>
            <table v-if="g.merchants.length" class="w-full text-sm stack-table">
              <thead class="bg-slate-50 text-left text-gray-600">
                <tr><th class="p-2">Client</th><th class="p-2 text-right">Colis à ramasser</th><th class="p-2">Ramasse d'habitude</th><th class="p-2" /></tr>
              </thead>
              <tbody class="divide-y">
                <tr v-for="m in g.merchants" :key="m.id">
                  <td data-label="Client" class="p-2 font-medium">{{ m.name }}</td>
                  <td data-label="Colis" class="p-2 text-right font-semibold text-red-700">{{ m.waiting }}</td>
                  <td data-label="D'habitude" class="p-2">{{ m.usual ? `🛵 ${m.usual.name}` : '—' }}</td>
                  <td class="p-2 text-right"><button type="button" class="text-blue-700 hover:underline whitespace-nowrap" @click="openGapRule({ merchant: m })">Créer une règle</button></td>
                </tr>
              </tbody>
            </table>
            <p v-if="g.others.length" class="border-t px-3 py-2 text-xs text-gray-600">
              Habituels sans colis pour l'instant : {{ g.others.map((m) => m.name).join(', ') }}
            </p>
          </details>
        </div>
      </section>

      <!-- Mises à disposition (MAD) ce jour-là : livreurs réservés à un client -->
      <section v-if="board.mad?.length" class="card p-4 space-y-3">
        <h2 class="font-semibold">Mises à disposition ce jour-là</h2>
        <ul class="divide-y">
          <li v-for="m in board.mad" :key="m.billing_plan_id" class="py-2 space-y-1">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="min-w-0">
                <span class="block font-medium">{{ m.merchant.name }}</span>
                <span class="block text-xs text-gray-500">
                  {{ m.reserved_today ? (m.exclusive ? 'Réservés au client' : 'Chez le client, non réservés') : 'Pas de mise à disposition ce jour-là' }}<template v-if="m.reserved_today && m.from"> · {{ m.from }}–{{ m.to }}</template><template v-if="m.ends_on"> · jusqu'au {{ shortDate(m.ends_on) }}</template>
                </span>
              </span>
              <span class="flex flex-wrap gap-1">
                <span
                  v-for="c in m.couriers" :key="c.id"
                  :class="['rounded-full px-2 py-0.5 text-sm font-medium', c.absent && m.reserved_today ? 'bg-red-50 text-red-700' : m.reserved_today ? 'bg-violet-50 text-violet-800' : 'bg-slate-100 text-slate-600']"
                >{{ c.absent ? '⚠️' : '🛵' }} {{ c.name }}{{ c.absent ? ' (absent)' : '' }}</span>
              </span>
            </div>
            <p v-if="m.missing" class="rounded bg-red-50 p-2 text-sm text-red-800">
              Livreur attitré absent : ses courses restent à l'équipe.
              <template v-if="m.replacements.length">Disponibles : {{ m.replacements.map((r) => r.name).join(', ') }}.</template>
              <RouterLink :to="{ path: '/admin/contrats', query: { activity: 'mad' } }" class="ml-1 font-medium underline">Changer les livreurs du contrat</RouterLink>
            </p>
          </li>
        </ul>
      </section>

      <!-- Toutes les règles -->
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Règles</h2>
        <p v-if="!board.rules.length" class="text-sm text-gray-500">Aucune règle. Exemple : « Awa ramasse à Cocody, tous les jours », « Yacouba ramasse chez Boutique Chic, le lundi et le jeudi ».</p>
        <ul class="divide-y">
          <li v-for="r in board.rules" :key="r.id" :class="['py-2 flex flex-wrap items-center justify-between gap-2', r.is_active ? '' : 'opacity-50']">
            <span class="min-w-0">
              <span class="block font-medium">🛵 {{ r.courier.name }}<span v-if="r.backup" class="font-normal text-gray-600"> · remplaçant {{ r.backup.name }}</span></span>
              <span class="block text-sm text-gray-700">{{ TYPES[r.type] }} : {{ r.label }}</span>
              <span v-if="r.note" class="block text-xs text-gray-500">{{ r.note }}</span>
            </span>
            <span class="flex gap-2 text-sm">
              <span v-if="!r.is_active" class="text-gray-500">en pause</span>
              <button class="text-blue-600" @click="openRule(r)">Modifier</button>
              <button class="text-red-700" @click="removeRule(r)">Supprimer</button>
            </span>
          </li>
        </ul>
      </section>

      <!-- Absences -->
      <section class="card p-4 space-y-3">
        <h2 class="font-semibold">Absences</h2>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="addAbsence">
          <div>
            <label class="label" for="abs-courier">Livreur</label>
            <select id="abs-courier" v-model="absence.courier_id" class="input w-auto" required>
              <option :value="null" disabled>Choisir…</option>
              <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div><label class="label" for="abs-from">Du</label><input id="abs-from" v-model="absence.starts_on" type="date" class="input w-auto" required></div>
          <div><label class="label" for="abs-to">Au</label><input id="abs-to" v-model="absence.ends_on" type="date" class="input w-auto" required></div>
          <div class="flex-1 min-w-40"><label class="label" for="abs-reason">Motif</label><input id="abs-reason" v-model="absence.reason" class="input" placeholder="Congé, maladie…"></div>
          <button class="btn-secondary">Ajouter</button>
        </form>
        <ul v-if="board.absences.length" class="divide-y text-sm">
          <li v-for="a in board.absences" :key="a.id" class="py-2 flex flex-wrap items-center justify-between gap-2">
            <span>{{ a.courier.name }} · du {{ shortDate(a.starts_on) }} au {{ shortDate(a.ends_on) }}<span v-if="a.reason" class="text-gray-500"> · {{ a.reason }}</span>
              <span v-if="a.current" class="ml-1 rounded-full bg-amber-100 px-1.5 text-xs text-amber-800">ce jour-là</span></span>
            <button class="text-red-700" @click="removeAbsence(a)">Retirer</button>
          </li>
        </ul>
      </section>
    </template>

    <Modal :open="form.open" :title="form.id ? 'Modifier la règle' : 'Nouvelle règle'" @close="form.open = false">
      <form class="space-y-4" @submit.prevent="saveRule">
        <fieldset>
          <legend class="label">Pour</legend>
          <div class="flex gap-2">
            <button v-for="(label, value) in TYPES" :key="value" type="button" :class="['rounded-full px-3 py-1 text-sm ring-1', form.type === value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']" @click="setType(value)">{{ label }}s</button>
          </div>
          <p v-if="form.type === 'delivery'" class="text-xs text-gray-500 mt-1">Utilisé par la livraison du jour même automatique : il livre ces zones avant les autres livreurs.</p>
        </fieldset>
        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label class="label" for="r-courier">Livreur</label>
            <select id="r-courier" v-model="form.courier_id" class="input" required>
              <option :value="null" disabled>Choisir…</option>
              <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="r-backup">Remplaçant <span class="font-normal text-gray-500">(facultatif)</span></label>
            <select id="r-backup" v-model="form.backup_courier_id" class="input">
              <option :value="null">Aucun</option>
              <option v-for="c in couriers.filter((c) => c.id !== form.courier_id)" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
        </div>

        <fieldset class="space-y-2">
          <legend class="label">Où ?</legend>
          <div v-if="form.type === 'pickup'" class="flex gap-2">
            <button v-for="t in TARGETS" :key="t.value" type="button" :class="['rounded-full px-3 py-1 text-sm ring-1', form.target === t.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']" @click="form.target = t.value">{{ t.label }}</button>
          </div>
          <input v-model="form.search" class="input" :placeholder="form.target === 'zones' ? 'Chercher une zone…' : 'Chercher un client…'" aria-label="Chercher">
          <div class="max-h-48 overflow-y-auto rounded border p-2 grid sm:grid-cols-2 gap-1">
            <label v-for="o in options" :key="o.id" class="flex items-center gap-2 text-sm">
              <input v-model="form[form.target === 'zones' ? 'zone_ids' : 'merchant_ids']" type="checkbox" :value="o.id"> {{ o.name }}
            </label>
            <p v-if="!options.length" class="text-sm text-gray-500">Aucun résultat.</p>
          </div>
          <p v-if="form.target === 'zones'" class="text-xs text-gray-500">Une commune couvre tous ses quartiers. Une règle pour un client passe avant celle de sa zone.</p>
        </fieldset>

        <fieldset class="space-y-2">
          <legend class="label">Quand ?</legend>
          <div class="flex flex-wrap gap-2">
            <button v-for="w in WHEN" :key="w.value" type="button" :class="['rounded-full px-3 py-1 text-sm ring-1', form.when === w.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']" @click="form.when = w.value">{{ w.label }}</button>
          </div>
          <div v-if="form.when === 'days'" class="flex flex-wrap gap-2">
            <label v-for="(d, i) in DAYS" :key="d" class="flex items-center gap-1 text-sm">
              <input v-model="form.days" type="checkbox" :value="i + 1"> {{ d }}
            </label>
          </div>
          <div v-if="form.when === 'date'"><input v-model="form.starts_on" type="date" class="input w-auto" aria-label="Date" required></div>
          <div v-if="form.when === 'period'" class="flex flex-wrap items-center gap-2 text-sm">
            du <input v-model="form.starts_on" type="date" class="input w-auto" aria-label="Du" required>
            au <input v-model="form.ends_on" type="date" class="input w-auto" aria-label="Au" required>
          </div>
          <p class="text-xs text-gray-500">Une date précise passe avant une période, avant certains jours, avant « tous les jours » : pratique pour un remplacement.</p>
        </fieldset>

        <div><label class="label" for="r-note">Note <span class="font-normal text-gray-500">(facultatif)</span></label><input id="r-note" v-model="form.note" class="input" maxlength="160"></div>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox"> Règle en service</label>

        <p v-if="form.error" class="field-error">{{ form.error }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="form.open = false">Annuler</button>
          <button class="btn-primary" :disabled="form.saving">{{ form.saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date as shortDate } from '../../utils/format'

// Onglet de « Activité des livreurs » : le titre de la page n'est pas répété
defineProps({ embedded: { type: Boolean, default: false } })

/**
 * Planning des livreurs : règles « qui ramasse où et quand », remplaçants, absences, et vue d'un jour.
 */
const auth = useAuthStore()
const toasts = useToastStore()

const TYPES = { pickup: 'Ramassage', delivery: 'Livraison' }
const TARGETS = [{ value: 'zones', label: 'Des zones' }, { value: 'merchants', label: 'Des clients' }]
const WHEN = [
  { value: 'always', label: 'Tous les jours' },
  { value: 'days', label: 'Certains jours' },
  { value: 'date', label: 'Une date' },
  { value: 'period', label: 'Une période' },
]
const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche']
const AUTO = {
  off: { cls: 'bg-slate-50 text-slate-700', text: 'Ramassage automatique désactivé : le planning sert de repère, l\'équipe confie les courses.' },
  trial: { cls: 'bg-amber-50 text-amber-900', text: 'Ramassage automatique à l\'essai : rien n\'est envoyé, le choix du planning est noté sur chaque course.' },
  on: { cls: 'bg-emerald-50 text-emerald-900', text: 'Ramassage automatique actif : les courses validées vont au livreur du planning.' },
}

const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const today = iso(new Date())
const date = ref(today)
const board = ref(null)
const couriers = ref([])
const zones = ref([])
const merchants = ref([])
const absence = reactive({ courier_id: null, starts_on: today, ends_on: today, reason: '' })

const empty = () => ({ open: false, id: null, type: 'pickup', courier_id: null, backup_courier_id: null, target: 'zones', zone_ids: [], merchant_ids: [], when: 'always', days: [], starts_on: '', ends_on: '', note: '', is_active: true, search: '', error: '', saving: false })
const form = reactive(empty())

const rulesOfDay = (type) => (board.value?.rules || []).filter((r) => r.applies && r.type === type)
// Les livraisons n'apparaissent qu'une fois une règle de livraison créée
const dayTypes = computed(() => ['pickup', ...((board.value?.rules || []).some((r) => r.type === 'delivery') ? ['delivery'] : [])])
const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const options = computed(() => (form.target === 'zones' ? zones.value : merchants.value)
  .filter((o) => !form.search || norm(o.name).includes(norm(form.search))))

const longDay = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })

function shift(n) {
  const d = new Date(`${date.value}T12:00:00`)
  d.setDate(d.getDate() + n)
  date.value = iso(d)
  load()
}

async function load() {
  board.value = (await http.get('/planning', { params: { date: date.value } })).data.data
}

// Suspendre en cas de souci (livreurs mal répartis, journée particulière), reprendre ensuite
const pausing = ref(false)
async function pause(paused) {
  if (paused && !confirm('Suspendre l\'automatisation ? Plus aucune course ne sera confiée automatiquement jusqu\'à la reprise.')) return
  pausing.value = true
  try {
    board.value.automation = (await http.post('/planning/pause', { paused })).data.data
    toasts.success(paused ? 'Automatisation suspendue.' : 'Automatisation reprise.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    pausing.value = false
  }
}

function openRule(rule = null) {
  Object.assign(form, empty(), { open: true })
  if (!rule) return
  const single = rule.starts_on && rule.starts_on === rule.ends_on
  Object.assign(form, {
    id: rule.id,
    type: rule.type,
    courier_id: rule.courier.id,
    backup_courier_id: rule.backup?.id ?? null,
    target: rule.merchant_ids.length ? 'merchants' : 'zones',
    zone_ids: [...rule.zone_ids],
    merchant_ids: [...rule.merchant_ids],
    when: single ? 'date' : (rule.starts_on || rule.ends_on) ? 'period' : rule.days.length ? 'days' : 'always',
    days: [...rule.days],
    starts_on: rule.starts_on || '',
    ends_on: rule.ends_on || '',
    note: rule.note || '',
    is_active: rule.is_active,
  })
}

// Règle de ramassage préremplie depuis un client ou une zone sans ramasseur (avec son livreur habituel)
function openGapRule({ merchant = null, zone = null }) {
  openRule()
  form.type = 'pickup'
  if (merchant) {
    form.target = 'merchants'
    form.merchant_ids = [merchant.id]
    if (merchant.usual) form.courier_id = merchant.usual.id
  } else if (zone) {
    form.target = 'zones'
    form.zone_ids = [zone.id]
  }
}

const gapSearch = ref('')
const gapZones = computed(() => {
  const q = gapSearch.value.trim().toLowerCase()
  if (!q) return board.value?.gaps || []
  const match = (m) => m.name.toLowerCase().includes(q)
  return (board.value?.gaps || [])
    .map((g) => (g.zone?.name || '').toLowerCase().includes(q) ? g : { ...g, merchants: g.merchants.filter(match), others: g.others.filter(match) })
    .filter((g) => g.merchants.length || g.others.length)
})
const gapTotals = computed(() => ({
  merchants: (board.value?.gaps || []).reduce((n, g) => n + g.merchants.length, 0),
  waiting: (board.value?.gaps || []).reduce((n, g) => n + g.waiting, 0),
}))

// Une livraison vise des zones (le client ne compte pas)
function setType(type) {
  form.type = type
  if (type === 'delivery') form.target = 'zones'
}

async function saveRule() {
  form.saving = true
  form.error = ''
  const payload = {
    type: form.type,
    courier_id: form.courier_id,
    backup_courier_id: form.backup_courier_id,
    zone_ids: form.target === 'zones' ? form.zone_ids : null,
    merchant_ids: form.target === 'merchants' ? form.merchant_ids : null,
    days: form.when === 'days' ? form.days : null,
    starts_on: ['date', 'period'].includes(form.when) ? form.starts_on || null : null,
    ends_on: form.when === 'date' ? form.starts_on || null : form.when === 'period' ? form.ends_on || null : null,
    note: form.note || null,
    is_active: form.is_active,
  }
  try {
    if (form.id) await http.put(`/planning/rules/${form.id}`, payload)
    else await http.post('/planning/rules', payload)
    form.open = false
    toasts.success('Règle enregistrée.')
    await load()
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

async function removeRule(rule) {
  if (!window.confirm(`Supprimer la règle de ${rule.courier.name} (${rule.label}) ?`)) return
  await http.delete(`/planning/rules/${rule.id}`)
  await load()
}

async function addAbsence() {
  try {
    await http.post('/planning/absences', { ...absence, reason: absence.reason || null })
    toasts.success('Absence enregistrée : ses règles passent à son remplaçant.')
    Object.assign(absence, { courier_id: null, reason: '' })
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function removeAbsence(a) {
  await http.delete(`/planning/absences/${a.id}`)
  await load()
}

onMounted(async () => {
  const [c, z, m] = await Promise.all([
    http.get('/couriers'),
    http.get('/zones'),
    http.get('/merchants', { params: { per_page: 200 } }),
  ])
  couriers.value = c.data.data.map((x) => ({ id: x.id, name: x.name }))
  zones.value = z.data.data.filter((x) => !x.is_shipping).map((x) => ({ id: x.id, name: x.full_name || x.name }))
  merchants.value = m.data.data.map((x) => ({ id: x.id, name: x.business_name }))
  await load()
})
</script>
