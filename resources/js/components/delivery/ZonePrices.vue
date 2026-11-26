<template>
  <!-- Zones et prix : prix de base, puis chaque commune (on collecte, on livre, forfait) et le bouton de ses prix au
       départ, qui ouvre sa fenêtre (paliers, puis un palier par destination) ; vue d'ensemble départ × arrivée -->
  <section class="space-y-3">
    <div class="flex flex-wrap items-center gap-2">
      <input v-model="query" type="search" class="input w-full sm:w-80" placeholder="Chercher une commune ou un quartier" aria-label="Chercher un lieu">
      <span class="flex-1" />
      <template v-if="canZones">
        <button type="button" class="btn-secondary" @click="open('place')">+ Commune ou quartier</button>
        <button type="button" class="btn-secondary" @click="open('shipping')">+ Zone d'expédition</button>
      </template>
    </div>

    <p v-if="canPrices" class="text-sm text-slate-600">
      Touchez « Prix au départ » d'une commune : vous réglez ses prix vers toutes les destinations, par paliers. Un prix vaut dans les deux sens.
      <template v-if="!isDefault"> Sans prix ici, c'est celui de la grille normale.</template>
    </p>
    <p v-if="canPrices && isDefault && baseDraft === null && missingCount" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
      {{ missingCount }} trajet{{ missingCount > 1 ? 's' : '' }} entre communes sans prix ({{ missingNames }}) :
      réglez-les, ou donnez un prix de base.
    </p>

    <form v-if="adding" class="card space-y-3 p-4" @submit.prevent="create">
      <div class="flex flex-wrap items-end gap-2">
        <div class="min-w-40 flex-1">
          <label class="label" for="zp-name">Nom</label>
          <input id="zp-name" v-model.trim="draft.name" class="input" required maxlength="100" :placeholder="adding === 'shipping' ? 'Ex. : Expédition Bouaké (gare UTB Adjamé)' : 'Ex. : Angré'">
        </div>
        <div v-if="adding === 'place'" class="min-w-40 flex-1">
          <label class="label" for="zp-parent">Dans la commune</label>
          <select id="zp-parent" v-model="draft.parent_id" class="input">
            <option :value="null">— (c'est une commune)</option>
            <option v-for="c in communes" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <div v-else class="w-44">
          <label class="label" for="zp-estimate">Frais habituels du transporteur</label>
          <input id="zp-estimate" v-model.number="draft.shipping_fee_estimate" type="number" min="0" class="input" placeholder="Ex. : 3000">
        </div>
        <button class="btn-primary" :disabled="draft.saving">Ajouter</button>
        <button type="button" class="btn-secondary" @click="adding = false">Annuler</button>
      </div>
      <p v-if="adding === 'shipping'" class="text-sm text-slate-600">
        Le livreur dépose le colis à une gare ou chez un transporteur et paie l'envoi, facturé au client. Le prix de la course jusqu'à la gare
        se règle dans « Prix au départ » de chaque commune.
      </p>
      <p v-if="draft.error" class="field-error">{{ draft.error }}</p>
    </form>

    <p v-if="loading" class="card p-6 text-center text-slate-600">Chargement…</p>
    <p v-else-if="loadError" class="field-error">{{ loadError }}</p>

    <template v-else>
      <!-- Prix de base : tout trajet sans prix -->
      <div v-if="isDefault && canPrices" class="card flex flex-wrap items-center gap-x-6 gap-y-3 p-4">
        <div class="min-w-56 flex-1">
          <p class="font-semibold">Prix de base</p>
          <p class="text-sm text-slate-600">Pour tout trajet sans prix. Plus aucune course bloquée faute de tarif.</p>
        </div>
        <label class="flex items-center gap-2">
          <input :value="baseDraft ?? ''" inputmode="numeric" placeholder="—" class="input w-36 py-2 text-right text-base tabular-nums" aria-label="Prix de base" @change="setBase($event.target.value)">
          <span class="text-sm text-slate-600">F</span>
        </label>
      </div>

      <div class="card divide-y overflow-hidden">
        <div :class="['hidden items-center gap-4 bg-slate-50 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600 md:grid', GRID]">
          <span>Commune</span><span class="text-center">On collecte</span><span class="text-center">On livre</span>
          <span v-if="showForfait" class="text-center">Forfait</span><span class="text-right">Prix au départ</span>
        </div>
        <template v-for="c in shownCommunes" :key="c.id">
          <div :class="['grid grid-cols-2 items-center gap-x-4 gap-y-2 px-4 py-3', GRID, c.is_active ? '' : 'bg-slate-50']">
            <div class="col-span-2 min-w-0 md:col-span-1">
              <p :class="['text-base font-semibold', c.is_active ? '' : 'text-slate-600']">
                {{ c.name }}
                <span v-if="interior(c)" class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Intérieur</span>
              </p>
              <button v-if="childrenOf(c).length" type="button" class="mt-0.5 text-sm font-medium text-emerald-700 hover:underline" @click="toggleOpen(c)">
                {{ isOpen(c) ? '▾' : '▸' }} {{ childrenOf(c).length }} quartier{{ childrenOf(c).length > 1 ? 's' : '' }}<template v-if="ownCount(c)"> · {{ ownCount(c) }} à part</template>
              </button>
              <template v-if="canZones">
                <button type="button" class="ml-3 text-xs text-blue-700" @click="rename(c)">Renommer</button>
                <button type="button" class="ml-2 text-xs text-red-700" @click="remove(c)">Supprimer</button>
              </template>
            </div>
            <label class="flex items-center gap-2 text-sm md:justify-center">
              <input type="checkbox" class="h-5 w-5" :checked="pickupOn(c)" :disabled="!canZones || busy[c.id]" :aria-label="`On collecte ici : ${c.name}`" @change="patch(c, 'is_pickup')"><span class="md:hidden">On collecte</span>
            </label>
            <label class="flex items-center gap-2 text-sm md:justify-center">
              <input type="checkbox" class="h-5 w-5" :checked="c.is_active" :disabled="!canZones || busy[c.id]" :aria-label="`On livre ici : ${c.name}`" @change="patch(c, 'is_active')"><span class="md:hidden">On livre</span>
            </label>
            <!-- Commune couverte par les forfaits de distribution et de mise à disposition -->
            <label v-if="showForfait" class="col-span-2 flex items-center gap-2 text-sm md:col-span-1 md:justify-center">
              <template v-if="!interior(c)">
                <input type="checkbox" class="h-5 w-5" :checked="c.in_forfait" :disabled="!canZones || busy[c.id]" :aria-label="`Couverte par les forfaits : ${c.name}`" @change="patch(c, 'in_forfait')"><span class="md:hidden">Couverte par les forfaits</span>
              </template>
              <span v-else class="text-xs text-slate-600">selon le contrat</span>
            </label>
            <div class="col-span-2 flex items-center justify-end gap-3 md:col-span-1">
              <template v-if="interior(c)"><span class="text-xs text-slate-600">prix dans l'onglet Distribution et MAD</span></template>
              <template v-else-if="canPrices">
                <span v-if="c.is_active" class="text-xs tabular-nums text-slate-600">{{ progress(c).done }}/{{ progress(c).total }} réglés</span>
                <button type="button" class="btn-secondary py-1.5" :disabled="!c.is_active" :aria-label="`Prix au départ de ${c.name}`" @click="departure = c">Prix au départ</button>
              </template>
            </div>
          </div>
          <template v-if="isOpen(c)">
            <div v-for="q in childrenOf(c)" :key="q.id" :class="['grid grid-cols-2 items-center gap-x-4 gap-y-2 bg-slate-50/60 py-2.5 pl-8 pr-4', GRID, q.is_active ? '' : 'opacity-60']">
              <p class="col-span-2 min-w-0 md:col-span-1">
                ↳ {{ q.name }}
                <span v-if="hasOwnDeparture(q)" class="ml-1 rounded-full bg-emerald-50 px-1.5 text-xs text-emerald-800 ring-1 ring-emerald-200">prix à part</span>
                <template v-if="canZones">
                  <button type="button" class="ml-3 text-xs text-blue-700" @click="rename(q)">Renommer</button>
                  <button type="button" class="ml-2 text-xs text-red-700" @click="remove(q)">Supprimer</button>
                </template>
              </p>
              <label class="flex items-center gap-2 text-sm md:justify-center">
                <input type="checkbox" class="h-5 w-5" :checked="pickupOn(q)" :disabled="!canZones || busy[q.id] || parentPickupOff(q)" :aria-label="`On collecte ici : ${q.name}`" @change="patch(q, 'is_pickup')"><span class="md:hidden">On collecte</span>
              </label>
              <label class="flex items-center gap-2 text-sm md:justify-center">
                <input type="checkbox" class="h-5 w-5" :checked="q.is_active" :disabled="!canZones || busy[q.id]" :aria-label="`On livre ici : ${q.name}`" @change="patch(q, 'is_active')"><span class="md:hidden">On livre</span>
              </label>
              <span v-if="showForfait" class="hidden text-center text-xs text-slate-600 md:block">comme {{ c.name }}</span>
              <p class="col-span-2 text-right text-xs text-slate-600 md:col-span-1">{{ hasOwnDeparture(q) ? 'prix à part au départ' : `suit ${c.name}` }}</p>
            </div>
          </template>
        </template>
        <p v-if="!shownCommunes.length" class="p-6 text-center text-slate-600">Aucun lieu ne correspond.</p>
      </div>

      <!-- Vue d'ensemble : départ en ligne, arrivée en colonne ; en gris, le prix qui s'applique faute de trajet réglé -->
      <details v-if="canPrices" class="card p-4">
        <summary class="cursor-pointer font-semibold">Vue d'ensemble des prix entre communes</summary>
        <p class="mt-2 text-sm text-slate-600">Départ en ligne, arrivée en colonne. En gris : pas de trajet réglé, c'est le prix de base. Touchez une commune pour l'ouvrir.</p>
        <div class="mt-3 overflow-x-auto">
          <table class="border-collapse text-xs tabular-nums">
            <tr>
              <th class="border bg-slate-50 px-2 py-1.5" />
              <th v-for="b in overview" :key="b.id" class="border bg-slate-50 px-2 py-1.5 font-semibold" :title="b.name">{{ b.name.slice(0, 5) }}{{ b.name.length > 5 ? '.' : '' }}</th>
            </tr>
            <tr v-for="a in overview" :key="a.id">
              <th class="border bg-slate-50 px-2 py-1.5 text-left"><button type="button" class="font-semibold text-emerald-700 hover:underline" @click="departure = a">{{ a.name }}</button></th>
              <td v-for="b in overview" :key="b.id" :class="['border px-2 py-1.5 text-center', cell(a, b).kind === 'route' ? '' : 'text-slate-400']">{{ cell(a, b).price === null ? '—' : cell(a, b).price.toLocaleString('fr-FR') }}</td>
            </tr>
          </table>
        </div>
      </details>

      <!-- Zones d'expédition -->
      <section v-if="shipping.length" class="space-y-2">
        <h2 class="pt-2 font-semibold">Zones d'expédition</h2>
        <p class="text-sm text-slate-600">
          Le livreur dépose le colis à une gare ou chez un transporteur. Le prix de la course jusqu'à la gare se règle dans « Prix au départ » ;
          les frais du transporteur, notés par le livreur, sont facturés au client.
        </p>
        <div class="card divide-y overflow-hidden">
          <div v-for="z in shipping" :key="z.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="h-5 w-5" :checked="z.is_active" :disabled="!canZones" :aria-label="`On livre ici : ${z.name}`" @change="patch(z, 'is_active')"> On livre ici</label>
            <span :class="['min-w-40 flex-1 font-medium', z.is_active ? '' : 'text-slate-600 line-through']">🚌 {{ z.name }}</span>
            <label class="flex items-center gap-2 text-xs text-slate-600">
              Frais habituels du transporteur
              <input :value="z.shipping_fee_estimate" type="number" min="0" class="input w-24 py-1" :disabled="!canZones" :aria-label="`Frais habituels : ${z.name}`" @change="setEstimate(z, $event.target.value)">
            </label>
            <template v-if="canZones">
              <button type="button" class="text-xs text-blue-700" @click="rename(z)">Renommer</button>
              <button type="button" class="text-xs text-red-700" @click="remove(z)">Supprimer</button>
            </template>
          </div>
        </div>
      </section>

      <!-- Enregistrer : les prix (les cases à cocher s'enregistrent tout de suite) -->
      <p v-if="!changes" class="text-sm text-slate-600">Les cases à cocher s'enregistrent tout de suite ; les prix, avec le bouton « Enregistrer ».</p>
      <div v-else-if="canPrices" class="sticky bottom-0 z-30 flex flex-wrap items-center gap-3 rounded-xl border bg-white p-3 shadow-lg">
        <span class="flex-1 text-sm font-semibold text-amber-800">{{ changes }} prix modifié{{ changes > 1 ? 's' : '' }}, pas encore enregistré{{ changes > 1 ? 's' : '' }}</span>
        <button type="button" class="btn-secondary" :disabled="saving" @click="load">Annuler</button>
        <button type="button" class="btn-primary" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer les prix' }}</button>
      </div>

      <DeparturePrices
        v-if="departure" :commune="departure" :zones="zones" :prices="prices" :base="baseDraft" :is-default="isDefault"
        :tiers="tiersOf(departure)" :changes="changes" :saving="saving"
        @close="departure = null" @set="setCell" @tiers="(list) => setTiers(departure, list)" @save="save" @cancel="load"
      />
    </template>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { myActivities } from '../../utils/activities'
import { resolveRoute } from '../../utils/routePrices'
import DeparturePrices from './DeparturePrices.vue'
import { confirmDialog, promptDialog } from '../../composables/useDialog'

/**
 * Zones et prix (repris de Jibiat). Prix de base (tout trajet sans prix). Puis chaque commune : « on collecte »,
 * « on livre », « forfait » (enregistrés tout de suite, droit sur les zones) et « Prix au départ » (droit sur les
 * tarifs), qui ouvre sa fenêtre : paliers de la commune, puis un palier par destination, depuis la commune ou un de
 * ses quartiers (DeparturePrices). Vue d'ensemble départ × arrivée. Les prix s'enregistrent ensemble.
 */
const props = defineProps({ gridId: { type: Number, required: true }, isDefault: { type: Boolean, default: true } })

const auth = useAuthStore()
const toasts = useToastStore()
const canZones = computed(() => auth.can('zones.manage'))
const canPrices = computed(() => auth.can('pricing.manage'))
// Colonne « Forfait » : seulement avec la distribution ou la mise à disposition
const showForfait = computed(() => myActivities(auth.user).some((a) => ['distribution', 'mad'].includes(a.value)))
const GRID = computed(() => (showForfait.value ? 'md:grid-cols-[minmax(12rem,1fr)_6rem_6rem_6rem_15rem]' : 'md:grid-cols-[minmax(12rem,1fr)_7rem_7rem_17rem]'))

const zones = ref([])
const rules = ref({}) // "départ-arrivée" -> prix enregistré
const prices = ref({}) // "départ-arrivée" -> prix affiché (modifiable)
const loading = ref(true)
const loadError = ref('')
const query = ref('')
const busy = reactive({})
const saving = ref(false)
const adding = ref(false)
const draft = reactive({ name: '', parent_id: null, shipping_fee_estimate: null, error: '', saving: false })
function open(mode) {
  adding.value = adding.value === mode ? false : mode
  Object.assign(draft, { name: '', parent_id: null, shipping_fee_estimate: null, error: '' })
}
const leaveWarning = 'Des prix modifiés ne sont pas enregistrés. Quitter quand même ?'

const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const key = (a, b) => `${a}-${b}`
const byId = computed(() => Object.fromEntries(zones.value.map((z) => [z.id, z])))
const communes = computed(() => zones.value.filter((z) => !z.parent_id && !z.is_shipping))
const shipping = computed(() => zones.value.filter((z) => z.is_shipping))
// Ville de l'intérieur : ses prix sont ceux de la distribution (onglet Distribution et MAD)
const interior = (z) => !!z.city && z.city !== 'Abidjan'

const childrenOf = (c) => zones.value.filter((z) => z.parent_id === c.id && !z.is_shipping)
const opened = ref(new Set())
const isOpen = (c) => opened.value.has(c.id) || (!!query.value.trim() && childrenOf(c).some((q) => norm(q.name).includes(norm(query.value.trim()))))
function toggleOpen(c) {
  const next = new Set(opened.value)
  next.has(c.id) ? next.delete(c.id) : next.add(c.id)
  opened.value = next
}
const ownCount = (c) => childrenOf(c).filter((q) => hasOwnDeparture(q)).length
const matches = (c) => { const q = norm(query.value.trim()); return !q || norm(c.name).includes(q) || childrenOf(c).some((z) => norm(z.name).includes(q)) }
const shownCommunes = computed(() => communes.value.filter(matches))

// Prix de base de la grille (enregistré avec les autres prix)
const baseSaved = ref(null)
const baseDraft = ref(null)
function setBase(raw) {
  const v = parse(raw)
  if (v !== undefined) baseDraft.value = v
}
const parentPickupOff = (z) => z.parent_id !== null && byId.value[z.parent_id]?.is_pickup === false
const pickupOn = (z) => z.is_pickup && !parentPickupOff(z)
// Prix au départ : trajet départ → arrivée, sinon prix de base (comme le calcul du serveur)
const departure = ref(null)
const cell = (a, b) => resolveRoute(a, b, { byId: byId.value, prices: prices.value, own: {}, base: baseDraft.value })
const delivered = computed(() => communes.value.filter((c) => c.is_active && !interior(c)))
const overview = delivered
function progress(c) {
  return { done: delivered.value.filter((d) => cell(c, d).kind === 'route').length, total: delivered.value.length }
}
const hasOwnDeparture = (z) => Object.keys(prices.value).some((k) => k.startsWith(`${z.id}-`))
// Trajets entre communes livrées sans aucun prix (ni trajet, ni prix de base)
const missingList = computed(() => overview.value.flatMap((a) => overview.value.filter((b) => b.id >= a.id && cell(a, b).price === null).map((b) => `${a.name} → ${b.name}`)))
const missingCount = computed(() => missingList.value.length)
const missingNames = computed(() => missingList.value.slice(0, 3).join(', ') + (missingCount.value > 3 ? '…' : ''))

// Paliers de chaque commune de départ : enregistrés avec la grille, sinon tirés de ses prix
const tiersSaved = ref({})
const tiers = ref({})
const DEFAULT_TIERS = [1000, 1500, 2000, 3000]
function tiersOf(c) {
  if (tiers.value[c.id]) return tiers.value[c.id]
  const from = new Set([c.id, ...childrenOf(c).map((q) => q.id)])
  const used = [...new Set(Object.entries(prices.value).filter(([k]) => k.split('-').map(Number).some((id) => from.has(id))).map(([, v]) => v))]
  return [...used.sort((x, y) => x - y), ...DEFAULT_TIERS].filter((v, i, a) => a.indexOf(v) === i).slice(0, 4).sort((x, y) => x - y)
}
function setTiers(c, list) {
  tiers.value = { ...tiers.value, [c.id]: list }
}
const tierChanges = computed(() => JSON.stringify(tiers.value) !== JSON.stringify(tiersSaved.value))

const ruleChanges = computed(() => {
  const keys = new Set([...Object.keys(rules.value), ...Object.keys(prices.value)])
  return [...keys].filter((k) => (rules.value[k] ?? null) !== (prices.value[k] ?? null)).length
})
const changes = computed(() => ruleChanges.value + (baseDraft.value !== baseSaved.value ? 1 : 0) + (tierChanges.value ? 1 : 0))

function parse(raw) {
  if (raw === '' || raw === null) return null
  const n = Number(String(raw).replace(/\s/g, ''))
  return Number.isNaN(n) || n < 0 ? undefined : Math.round(n)
}
function setCell(a, b, raw) {
  const v = parse(raw)
  if (v === undefined) return
  const next = { ...prices.value }
  if (v === null) delete next[key(a, b)]
  else next[key(a, b)] = v
  prices.value = next
}

async function load() {
  loading.value = zones.value.length === 0
  try {
    const [z, grid] = await Promise.all([
      http.get('/zones', { params: { include_inactive: 1 } }),
      canPrices.value ? http.get(`/pricing-grids/${props.gridId}`) : Promise.resolve(null),
    ])
    zones.value = z.data.data
    const g = grid?.data.data
    rules.value = Object.fromEntries((g?.rules ?? []).map((r) => [key(r.origin_zone_id, r.destination_zone_id), r.price]))
    prices.value = { ...rules.value }
    tiersSaved.value = Object.fromEntries(Object.entries(g?.tiers ?? {}).map(([k, v]) => [Number(k), v]))
    tiers.value = { ...tiersSaved.value }
    baseSaved.value = g?.base_price ?? null
    baseDraft.value = baseSaved.value
    loadError.value = ''
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    if (baseDraft.value !== baseSaved.value || tierChanges.value) {
      await http.put(`/pricing-grids/${props.gridId}`, { base_price: baseDraft.value, tiers: tiers.value })
    }
    if (ruleChanges.value) {
      // Un trajet est symétrique si le sens inverse n'a pas de prix propre
      const list = Object.entries(prices.value).map(([k, price]) => {
        const [o, d] = k.split('-').map(Number)
        return { origin_zone_id: o, destination_zone_id: d, price, is_symmetric: prices.value[key(d, o)] === undefined }
      })
      await http.put(`/pricing-grids/${props.gridId}/rules`, { rules: list })
    }
    toasts.success('Prix enregistrés.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

// Case à cocher (on collecte, on livre, forfait) : enregistrée tout de suite
async function patch(zone, field) {
  busy[zone.id] = true
  try {
    await http.patch(`/zones/${zone.id}`, { [field]: !zone[field] })
    zone[field] = !zone[field]
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy[zone.id] = false
  }
}

async function setEstimate(zone, value) {
  try {
    await http.patch(`/zones/${zone.id}`, { shipping_fee_estimate: value === '' ? null : Number(value) })
    zone.shipping_fee_estimate = value === '' ? null : Number(value)
    toasts.success('Frais habituels enregistrés.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function rename(zone) {
  const name = (await promptDialog('Nouveau nom du lieu :', zone.name))?.trim()
  if (!name || name === zone.name) return
  try {
    await http.patch(`/zones/${zone.id}`, { name })
    zone.name = name
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function remove(zone) {
  if (!(await confirmDialog(`Supprimer le lieu « ${zone.name} » et ses prix ?`))) return
  try {
    await http.delete(`/zones/${zone.id}`)
    toasts.success('Lieu supprimé.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e), { timeout: 10000 })
  }
}

async function create() {
  if (changes.value && !(await confirmDialog('Des prix modifiés ne sont pas enregistrés : ils seront perdus. Continuer ?'))) return
  draft.error = ''
  draft.saving = true
  try {
    const isShipping = adding.value === 'shipping'
    await http.post('/zones', {
      name: draft.name, parent_id: isShipping ? null : draft.parent_id, is_shipping: isShipping,
      ...(isShipping ? { shipping_fee_estimate: draft.shipping_fee_estimate || null } : {}),
    })
    toasts.success('Lieu ajouté.')
    adding.value = false
    await load()
  } catch (e) {
    draft.error = apiErrorMessage(e)
  } finally {
    draft.saving = false
  }
}

onBeforeRouteLeave(async () => !changes.value || (await confirmDialog(leaveWarning)))
function warnBeforeUnload(event) {
  if (changes.value) event.preventDefault()
}
window.addEventListener('beforeunload', warnBeforeUnload)
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload))

onMounted(load)
</script>
