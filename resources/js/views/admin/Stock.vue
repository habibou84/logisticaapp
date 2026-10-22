<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Stock</h1>
      <button v-if="tab === 'products'" class="btn-primary" @click="openProduct()">+ Nouveau produit</button>
      <button v-if="tab === 'hubs' && canSettings" class="btn-primary" @click="openHub()">+ Nouvel entrepôt</button>
      <button v-if="tab === 'contracts' && canContracts" class="btn-primary" @click="openContract()">+ Nouveau contrat</button>
    </div>

    <div class="flex gap-2 overflow-x-auto">
      <button
        v-for="t in tabs"
        :key="t.value"
        :class="['btn whitespace-nowrap', tab === t.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']"
        @click="setTab(t.value)"
      >
        {{ t.label }}<span v-if="t.badge" class="ml-1 rounded-full bg-amber-400 text-slate-900 px-1.5 text-xs">{{ t.badge }}</span>
      </button>
    </div>

    <p v-if="!hubs.length && tab !== 'hubs'" class="rounded bg-amber-50 text-amber-900 text-sm p-3">
      Aucun entrepôt : créez-en un dans l'onglet « Entrepôts » pour recevoir les produits des marchands.
    </p>

    <!-- Produits et niveaux -->
    <template v-if="tab === 'products'">
      <div class="card p-3 flex flex-wrap gap-2 items-end">
        <div>
          <label class="label" for="f-merchant">E-commerçant</label>
          <select id="f-merchant" v-model="filters.merchant_id" class="input" @change="loadProducts(1)">
            <option :value="null">Tous</option>
            <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.business_name }}</option>
          </select>
        </div>
        <div class="flex-1 min-w-48">
          <label class="label" for="f-search">Recherche</label>
          <input id="f-search" v-model="filters.search" type="search" class="input" placeholder="Nom ou référence" @input="debouncedProducts">
        </div>
        <label class="flex items-center gap-2 text-sm pb-2"><input v-model="filters.low" type="checkbox" @change="loadProducts(1)"> Stock bas uniquement</label>
      </div>

      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr>
              <th class="p-2">Produit</th><th class="p-2">E-commerçant</th>
              <th v-for="h in hubs" :key="h.id" class="p-2 text-right">{{ h.name }}</th>
              <th class="p-2 text-right">Chez le marchand</th>
              <th class="p-2 text-right">Disponible</th><th />
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="p in products" :key="p.id" :class="p.is_low ? 'bg-amber-50' : ''">
              <td class="p-2">
                <p class="font-medium">{{ p.name }}</p>
                <p class="text-xs text-gray-500">{{ p.sku || 'sans référence' }} · {{ money(p.price) }}<span v-if="p.low_stock_threshold !== null"> · seuil {{ p.low_stock_threshold }}</span></p>
              </td>
              <td class="p-2">{{ p.merchant?.business_name }}</td>
              <td v-for="h in hubs" :key="h.id" class="p-2 text-right whitespace-nowrap">
                <template v-if="level(p, h.id)">
                  {{ level(p, h.id).on_hand }}<span v-if="level(p, h.id).reserved" class="text-xs text-amber-700"> ({{ level(p, h.id).reserved }} rés.)</span>
                </template>
                <span v-else class="text-gray-300">—</span>
              </td>
              <td class="p-2 text-right text-gray-600">{{ level(p, null)?.on_hand ?? '—' }}</td>
              <td :class="['p-2 text-right font-semibold', p.is_low ? 'text-amber-700' : '']">{{ p.available }}<span v-if="p.is_low"> ⚠️</span></td>
              <td class="p-2 text-right whitespace-nowrap space-x-1">
                <button class="btn-success px-2 py-1 text-xs" :disabled="!hubs.length" @click="openMove(p, 'receipt')">Entrée</button>
                <button class="btn-secondary px-2 py-1 text-xs" :disabled="!hubs.length" @click="openMove(p, 'withdrawal')">Retrait</button>
                <button class="btn-secondary px-2 py-1 text-xs" :disabled="!hubs.length" @click="openMove(p, 'count')">Inventaire</button>
                <button class="btn-secondary px-2 py-1 text-xs" @click="openProduct(p)">Modifier</button>
              </td>
            </tr>
            <tr v-if="!products.length"><td :colspan="5 + hubs.length" class="p-6 text-center text-gray-500">Aucun produit.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :meta="productsMeta" @change="loadProducts" />
    </template>

    <!-- Journal des mouvements -->
    <template v-if="tab === 'movements'">
      <div class="card p-3 flex flex-wrap gap-2 items-end">
        <div>
          <label class="label" for="m-merchant">E-commerçant</label>
          <select id="m-merchant" v-model="moveFilters.merchant_id" class="input" @change="loadMovements(1)">
            <option :value="null">Tous</option>
            <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.business_name }}</option>
          </select>
        </div>
        <div>
          <label class="label" for="m-hub">Entrepôt</label>
          <select id="m-hub" v-model="moveFilters.hub_id" class="input" @change="loadMovements(1)">
            <option :value="null">Tous</option>
            <option v-for="h in hubs" :key="h.id" :value="h.id">{{ h.name }}</option>
          </select>
        </div>
        <div>
          <label class="label" for="m-type">Type</label>
          <select id="m-type" v-model="moveFilters.type" class="input" @change="loadMovements(1)">
            <option :value="null">Tous</option>
            <option v-for="(label, value) in MOVEMENT_TYPES" :key="value" :value="value">{{ label }}</option>
          </select>
        </div>
      </div>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Date</th><th class="p-2">Produit</th><th class="p-2">Emplacement</th><th class="p-2">Mouvement</th><th class="p-2 text-right">Quantité</th><th class="p-2 text-right">Stock après</th><th class="p-2">Course / note</th><th class="p-2">Par</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="m in movements" :key="m.id">
              <td class="p-2 whitespace-nowrap text-xs">{{ dateTime(m.created_at) }}</td>
              <td class="p-2">{{ m.product?.name }}<div class="text-xs text-gray-500">{{ m.product?.merchant_name }}</div></td>
              <td class="p-2">{{ m.location?.label }}</td>
              <td class="p-2">{{ m.type_label }}</td>
              <td :class="['p-2 text-right font-semibold', m.on_hand_change > 0 ? 'text-emerald-700' : m.on_hand_change < 0 ? 'text-rose-700' : 'text-gray-500']">
                {{ movementQuantity(m) }}
              </td>
              <td class="p-2 text-right">{{ m.on_hand_after }}</td>
              <td class="p-2">
                <RouterLink v-if="m.order" :to="`/admin/courses/${m.order.id}`" class="text-blue-600 font-mono text-xs">{{ m.order.tracking_code }}</RouterLink>
                <span class="text-gray-600"> {{ m.note }}</span>
              </td>
              <td class="p-2 text-xs">{{ m.user_name || 'Système' }}</td>
            </tr>
            <tr v-if="!movements.length"><td colspan="8" class="p-6 text-center text-gray-500">Aucun mouvement.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :meta="movementsMeta" @change="loadMovements" />
    </template>

    <!-- Commandes à préparer -->
    <template v-if="tab === 'prepare'">
      <div v-if="!toPrepare.length" class="card p-6 text-center text-gray-500">Aucune commande à préparer.</div>
      <div class="grid md:grid-cols-2 gap-3">
        <div v-for="o in toPrepare" :key="o.id" class="card p-4 space-y-2 text-sm">
          <div class="flex justify-between gap-2">
            <RouterLink :to="`/admin/courses/${o.id}`" class="font-mono font-semibold text-blue-700">{{ o.tracking_code }}</RouterLink>
            <span class="text-gray-500">{{ o.pickup.hub_name }}</span>
          </div>
          <p>{{ o.merchant?.business_name }} → {{ o.delivery.zone_name }} · {{ o.recipient.name || o.recipient.phone }}</p>
          <ul class="rounded bg-slate-50 p-2 space-y-0.5">
            <li v-for="item in o.items" :key="item.id"><strong>{{ item.quantity }} ×</strong> {{ item.label }}</li>
          </ul>
          <button class="btn-success w-full" @click="prepare(o)">📦 Commande préparée</button>
        </div>
      </div>
    </template>

    <!-- Entrepôts -->
    <template v-if="tab === 'hubs'">
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Entrepôt</th><th class="p-2">Zone</th><th class="p-2">Adresse</th><th class="p-2">Téléphone</th><th class="p-2">État</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="h in allHubs" :key="h.id">
              <td class="p-2 font-medium">{{ h.name }}</td>
              <td class="p-2">{{ h.zone_name }}</td>
              <td class="p-2">{{ h.address || '—' }}<div v-if="h.landmark" class="text-xs text-gray-500">{{ h.landmark }}</div></td>
              <td class="p-2">{{ h.phone || '—' }}</td>
              <td class="p-2">{{ h.is_active ? 'Actif' : 'Fermé' }}</td>
              <td class="p-2 text-right"><button v-if="canSettings" class="btn-secondary px-2 py-1 text-xs" @click="openHub(h)">Modifier</button></td>
            </tr>
            <tr v-if="!allHubs.length"><td colspan="6" class="p-6 text-center text-gray-500">Aucun entrepôt.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Contrats de stockage -->
    <template v-if="tab === 'contracts'">
      <p class="text-sm text-gray-600">
        Le stockage est facturé le 1er de chaque mois pour le mois écoulé (écriture « Frais de stockage » déduite du prochain reversement).
      </p>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">E-commerçant</th><th class="p-2">Entrepôt</th><th class="p-2">Tarif</th><th class="p-2">Période</th><th class="p-2">Dernières facturations</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="c in contracts" :key="c.id" :class="c.is_active ? '' : 'text-gray-400'">
              <td class="p-2 font-medium">{{ c.merchant?.business_name }}</td>
              <td class="p-2">{{ c.hub_name || 'Tous' }}</td>
              <td class="p-2">{{ c.description }}</td>
              <td class="p-2 whitespace-nowrap">du {{ date(c.starts_on) }}<span v-if="c.ends_on"> au {{ date(c.ends_on) }}</span></td>
              <td class="p-2 text-xs">
                <span v-for="ch in c.charges" :key="ch.id" class="mr-2 whitespace-nowrap">{{ monthLabel(ch.period) }} : {{ money(ch.amount) }}</span>
                <span v-if="!c.charges?.length" class="text-gray-400">—</span>
              </td>
              <td class="p-2 text-right"><button v-if="canContracts" class="btn-secondary px-2 py-1 text-xs" @click="openContract(c)">Modifier</button></td>
            </tr>
            <tr v-if="!contracts.length"><td colspan="6" class="p-6 text-center text-gray-500">Aucun contrat : le stockage est gratuit.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Mouvement -->
    <Modal :open="move.open" :title="`${MOVE_TITLES[move.action]} · ${move.product?.name || ''}`" @close="move.open = false">
      <form class="space-y-3" @submit.prevent="saveMove">
        <div>
          <label class="label" for="mv-hub">Entrepôt</label>
          <select id="mv-hub" v-model="move.hub_id" class="input" required>
            <option v-for="h in hubs" :key="h.id" :value="h.id">{{ h.name }} · {{ level(move.product, h.id)?.on_hand ?? 0 }} en stock</option>
          </select>
        </div>
        <div>
          <label class="label" for="mv-qty">{{ move.action === 'count' ? 'Quantité comptée' : 'Quantité' }}</label>
          <input id="mv-qty" v-model.number="move.quantity" type="number" :min="move.action === 'count' ? 0 : 1" class="input" required>
          <p v-if="move.action === 'count' && level(move.product, move.hub_id)" class="text-xs text-gray-500 mt-1">
            Stock théorique : {{ level(move.product, move.hub_id).on_hand }} (dont {{ level(move.product, move.hub_id).reserved }} réservé(s))
          </p>
        </div>
        <div>
          <label class="label" for="mv-note">Note</label>
          <input id="mv-note" v-model="move.note" class="input" :placeholder="move.action === 'receipt' ? 'Ex. : dépôt du marchand, bon n° 12' : 'Ex. : repris par le marchand'">
        </div>
        <p v-if="move.error" class="field-error">{{ move.error }}</p>
        <button class="btn-primary w-full" :disabled="move.saving">Enregistrer</button>
      </form>
    </Modal>

    <!-- Produit -->
    <Modal :open="product.open" :title="product.id ? 'Modifier le produit' : 'Nouveau produit'" @close="product.open = false">
      <form class="space-y-3" @submit.prevent="saveProduct">
        <div v-if="!product.id">
          <label class="label" for="p-merchant">E-commerçant *</label>
          <select id="p-merchant" v-model="product.form.merchant_id" class="input" required>
            <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.business_name }}</option>
          </select>
        </div>
        <div><label class="label" for="p-name">Nom *</label><input id="p-name" v-model="product.form.name" class="input" required></div>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="label" for="p-sku">Référence</label><input id="p-sku" v-model="product.form.sku" class="input"></div>
          <div><label class="label" for="p-price">Prix de vente (F)</label><input id="p-price" v-model.number="product.form.price" type="number" min="0" class="input"></div>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="label" for="p-threshold">Alerte stock bas à</label><input id="p-threshold" v-model.number="product.form.low_stock_threshold" type="number" min="0" class="input" placeholder="Aucune alerte"></div>
          <div><label class="label" for="p-weight">Poids (kg)</label><input id="p-weight" v-model.number="product.form.weight_kg" type="number" min="0" step="0.1" class="input"></div>
        </div>
        <label v-if="product.id" class="flex items-center gap-2 text-sm"><input v-model="product.form.is_active" type="checkbox"> Produit actif (proposé à la création des courses)</label>
        <p v-if="product.error" class="field-error">{{ product.error }}</p>
        <button class="btn-primary w-full">Enregistrer</button>
      </form>
    </Modal>

    <!-- Entrepôt -->
    <Modal :open="hub.open" :title="hub.id ? 'Modifier l\'entrepôt' : 'Nouvel entrepôt'" @close="hub.open = false">
      <form class="space-y-3" @submit.prevent="saveHub">
        <div><label class="label" for="h-name">Nom *</label><input id="h-name" v-model="hub.form.name" class="input" required placeholder="Ex. : Entrepôt Cocody"></div>
        <div>
          <label class="label" for="h-zone">Zone *</label>
          <select id="h-zone" v-model="hub.form.zone_id" class="input" required>
            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
          </select>
          <p class="text-xs text-gray-500 mt-1">Point de départ des livraisons : sert au calcul du tarif.</p>
        </div>
        <div><label class="label" for="h-address">Adresse</label><input id="h-address" v-model="hub.form.address" class="input"></div>
        <div><label class="label" for="h-landmark">Repère</label><input id="h-landmark" v-model="hub.form.landmark" class="input"></div>
        <div><label class="label" for="h-phone">Téléphone</label><input id="h-phone" v-model="hub.form.phone" class="input" inputmode="tel"></div>
        <label class="flex items-center gap-2 text-sm"><input v-model="hub.form.is_active" type="checkbox"> Entrepôt actif</label>
        <p v-if="hub.error" class="field-error">{{ hub.error }}</p>
        <button class="btn-primary w-full">Enregistrer</button>
      </form>
    </Modal>

    <!-- Contrat -->
    <Modal :open="contract.open" :title="contract.id ? 'Modifier le contrat' : 'Nouveau contrat de stockage'" @close="contract.open = false">
      <form class="space-y-3" @submit.prevent="saveContract">
        <p v-if="contract.charged" class="text-sm rounded bg-slate-50 p-2">Contrat déjà facturé : seules la date de fin et la note peuvent changer.</p>
        <template v-if="!contract.charged">
          <div>
            <label class="label" for="c-merchant">E-commerçant *</label>
            <select id="c-merchant" v-model="contract.form.merchant_id" class="input" required>
              <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.business_name }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="c-hub">Entrepôt</label>
            <select id="c-hub" v-model="contract.form.hub_id" class="input">
              <option :value="null">Tous les entrepôts</option>
              <option v-for="h in hubs" :key="h.id" :value="h.id">{{ h.name }}</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="label" for="c-type">Facturation *</label>
              <select id="c-type" v-model="contract.form.billing_type" class="input" required>
                <option v-for="(label, value) in BILLING_TYPES" :key="value" :value="value">{{ label }}</option>
              </select>
            </div>
            <div v-if="contract.form.billing_type !== 'free'">
              <label class="label" for="c-price">Prix (F) *</label>
              <input id="c-price" v-model.number="contract.form.price" type="number" min="0" class="input" required>
            </div>
          </div>
          <div><label class="label" for="c-start">Début *</label><input id="c-start" v-model="contract.form.starts_on" type="date" class="input" required></div>
        </template>
        <div><label class="label" for="c-end">Fin</label><input id="c-end" v-model="contract.form.ends_on" type="date" class="input"></div>
        <div><label class="label" for="c-notes">Note</label><input id="c-notes" v-model="contract.form.notes" class="input"></div>
        <p v-if="contract.error" class="field-error">{{ contract.error }}</p>
        <div class="flex gap-2">
          <button v-if="contract.id && !contract.charged" type="button" class="btn-danger" @click="deleteContract">Supprimer</button>
          <button class="btn-primary flex-1">Enregistrer</button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import Pagination from '../../components/Pagination.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime, money, today } from '../../utils/format'

const MOVEMENT_TYPES = { receipt: 'Entrée', withdrawal: 'Retrait', adjustment: 'Inventaire', reservation: 'Réservé', release: 'Libéré', shipment: 'Livré' }
const MOVE_TITLES = { receipt: 'Entrée en stock', withdrawal: 'Retrait', count: 'Inventaire' }
const BILLING_TYPES = { free: 'Gratuit', monthly_flat: 'Forfait mensuel', per_unit_day: 'Par article et par jour', per_order: 'Par commande préparée' }

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

const canSettings = computed(() => auth.can('settings.manage'))
const canContracts = computed(() => auth.can('settings.manage') || auth.can('finance.manage'))

const tab = ref(route.query.tab || 'products')
const merchants = ref([])
const zones = ref([])
const allHubs = ref([])
const hubs = computed(() => allHubs.value.filter((h) => h.is_active))
const products = ref([])
const productsMeta = ref(null)
const movements = ref([])
const movementsMeta = ref(null)
const toPrepare = ref([])
const contracts = ref([])

const filters = reactive({ merchant_id: null, search: '', low: false })
const moveFilters = reactive({ merchant_id: null, hub_id: null, type: null })
const move = reactive({ open: false, product: null, action: 'receipt', hub_id: null, quantity: 1, note: '', error: '', saving: false })
const product = reactive({ open: false, id: null, form: {}, error: '' })
const hub = reactive({ open: false, id: null, form: {}, error: '' })
const contract = reactive({ open: false, id: null, charged: false, form: {}, error: '' })

const tabs = computed(() => [
  { value: 'products', label: 'Produits' },
  { value: 'prepare', label: 'À préparer', badge: toPrepare.value.length || null },
  { value: 'movements', label: 'Mouvements' },
  { value: 'hubs', label: 'Entrepôts' },
  ...(canContracts.value || auth.can('finance.view') ? [{ value: 'contracts', label: 'Contrats de stockage' }] : []),
])

function setTab(value) {
  tab.value = value
  router.replace({ query: { tab: value } })
  loadTab()
}

function level(p, hubId) {
  return p?.levels?.find((l) => (l.hub_id ?? null) === (hubId ?? null))
}

function movementQuantity(m) {
  if (m.on_hand_change) return (m.on_hand_change > 0 ? '+' : '') + m.on_hand_change
  return `${m.reserved_change > 0 ? '+' : ''}${m.reserved_change} rés.`
}

function monthLabel(period) {
  return new Date(period).toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' })
}

async function loadProducts(page = 1) {
  const { data } = await http.get('/products', {
    params: { page, per_page: 50, merchant_id: filters.merchant_id || undefined, search: filters.search || undefined, low: filters.low ? 1 : undefined },
  })
  products.value = data.data
  productsMeta.value = data.meta
}

let searchTimer
function debouncedProducts() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => loadProducts(1), 300)
}

async function loadMovements(page = 1) {
  const params = Object.fromEntries(Object.entries({ ...moveFilters, page }).filter(([, v]) => v !== null && v !== ''))
  const { data } = await http.get('/stock/movements', { params })
  movements.value = data.data
  movementsMeta.value = data.meta
}

async function loadToPrepare() {
  toPrepare.value = (await http.get('/orders', { params: { queue: 'to_prepare', per_page: 100 } })).data.data
}

async function loadContracts() {
  contracts.value = (await http.get('/storage-contracts')).data.data
}

async function loadHubs() {
  allHubs.value = (await http.get('/hubs', { params: { include_inactive: 1 } })).data.data
}

function loadTab() {
  if (tab.value === 'products') loadProducts()
  if (tab.value === 'movements') loadMovements()
  if (tab.value === 'prepare') loadToPrepare()
  if (tab.value === 'contracts') loadContracts()
  if (tab.value === 'hubs') loadHubs()
}

function openMove(p, action) {
  Object.assign(move, { open: true, product: p, action, hub_id: hubs.value[0]?.id, quantity: action === 'count' ? (level(p, hubs.value[0]?.id)?.on_hand ?? 0) : 1, note: '', error: '' })
}

async function saveMove() {
  move.saving = true
  move.error = ''
  try {
    const { data } = await http.post('/stock/movements', {
      product_id: move.product.id, hub_id: move.hub_id, action: move.action, quantity: move.quantity, note: move.note || undefined,
    })
    const index = products.value.findIndex((p) => p.id === data.product.id)
    if (index >= 0) products.value[index] = data.product
    move.open = false
    toasts.success(data.data ? 'Mouvement enregistré.' : 'Inventaire conforme : aucun écart.')
  } catch (e) {
    move.error = apiErrorMessage(e)
  } finally {
    move.saving = false
  }
}

function openProduct(p = null) {
  Object.assign(product, {
    open: true,
    id: p?.id ?? null,
    error: '',
    form: p
      ? { name: p.name, sku: p.sku, price: p.price, low_stock_threshold: p.low_stock_threshold, weight_kg: p.weight_kg, is_active: p.is_active }
      : { merchant_id: filters.merchant_id || merchants.value[0]?.id, name: '', sku: '', price: 0, low_stock_threshold: null, weight_kg: null },
  })
}

async function saveProduct() {
  product.error = ''
  try {
    const payload = { ...product.form, sku: product.form.sku || null, low_stock_threshold: product.form.low_stock_threshold === '' ? null : product.form.low_stock_threshold }
    if (product.id) await http.put(`/products/${product.id}`, payload)
    else await http.post('/products', payload)
    product.open = false
    toasts.success('Produit enregistré.')
    loadProducts(productsMeta.value?.current_page || 1)
  } catch (e) {
    product.error = apiErrorMessage(e)
  }
}

function openHub(h = null) {
  Object.assign(hub, {
    open: true,
    id: h?.id ?? null,
    error: '',
    form: h ? { name: h.name, zone_id: h.zone_id, address: h.address, landmark: h.landmark, phone: h.phone, is_active: h.is_active } : { name: '', zone_id: zones.value[0]?.id, address: '', landmark: '', phone: '', is_active: true },
  })
}

async function saveHub() {
  hub.error = ''
  try {
    const payload = Object.fromEntries(Object.entries(hub.form).map(([k, v]) => [k, v === '' ? null : v]))
    if (hub.id) await http.patch(`/hubs/${hub.id}`, payload)
    else await http.post('/hubs', payload)
    hub.open = false
    toasts.success('Entrepôt enregistré.')
    loadHubs()
  } catch (e) {
    hub.error = apiErrorMessage(e)
  }
}

function openContract(c = null) {
  Object.assign(contract, {
    open: true,
    id: c?.id ?? null,
    charged: !!c?.charges?.length,
    error: '',
    form: c
      ? { merchant_id: c.merchant_id, hub_id: c.hub_id, billing_type: c.billing_type, price: c.price, starts_on: c.starts_on, ends_on: c.ends_on, notes: c.notes }
      : { merchant_id: merchants.value[0]?.id, hub_id: null, billing_type: 'monthly_flat', price: 0, starts_on: today(), ends_on: null, notes: '' },
  })
}

async function saveContract() {
  contract.error = ''
  try {
    const payload = contract.charged
      ? { ends_on: contract.form.ends_on || null, notes: contract.form.notes || null }
      : { ...contract.form, ends_on: contract.form.ends_on || null, notes: contract.form.notes || null, price: contract.form.billing_type === 'free' ? 0 : contract.form.price }
    if (contract.id) await http.put(`/storage-contracts/${contract.id}`, payload)
    else await http.post('/storage-contracts', payload)
    contract.open = false
    toasts.success('Contrat enregistré.')
    loadContracts()
  } catch (e) {
    contract.error = apiErrorMessage(e)
  }
}

async function deleteContract() {
  if (!window.confirm('Supprimer ce contrat ?')) return
  try {
    await http.delete(`/storage-contracts/${contract.id}`)
    contract.open = false
    loadContracts()
  } catch (e) {
    contract.error = apiErrorMessage(e)
  }
}

async function prepare(o) {
  try {
    await http.post(`/orders/${o.id}/status`, { status: 'at_hub' })
    toasts.success(`${o.tracking_code} préparée : prête pour la livraison.`)
    loadToPrepare()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(async () => {
  const [m, z] = await Promise.all([
    http.get('/merchants', { params: { per_page: 200 } }).catch(() => ({ data: { data: [] } })),
    http.get('/zones'),
    loadHubs(),
  ])
  merchants.value = m.data.data
  zones.value = z.data.data
  loadTab()
  if (tab.value !== 'prepare') loadToPrepare()
})
</script>
