<template>
  <div class="space-y-4">
    <div class="flex gap-2">
      <input v-model="search" type="search" class="m-input flex-1" placeholder="Rechercher un produit…" aria-label="Rechercher un produit" @input="debouncedLoad">
      <button v-if="canManage" class="m-btn-primary w-auto px-4" @click="openForm()">+ Produit</button>
    </div>

    <div class="flex rounded-xl bg-slate-200 p-1">
      <button v-for="t in tabs" :key="t.value" :class="['tap flex-1 rounded-lg py-2 text-sm font-medium', filter === t.value ? 'bg-white shadow-sm' : 'text-slate-600']" @click="filter = t.value; load()">
        {{ t.label }}
      </button>
    </div>

    <p v-for="c in activeContracts" :key="c.id" class="rounded-xl bg-sky-50 text-sky-900 text-sm p-3">
      🏬 Stockage {{ c.hub_name ? `à ${c.hub_name}` : 'à l\'entrepôt' }} : {{ c.description }}, facturé chaque mois et déduit de vos paiements.
    </p>

    <button
      v-for="p in products"
      :key="p.id"
      type="button"
      :class="['tap w-full text-left m-card p-4 space-y-2 active:bg-slate-50', p.is_low ? 'ring-2 ring-amber-300' : '']"
      @click="openProduct(p)"
    >
      <div class="flex justify-between gap-3">
        <div class="min-w-0">
          <p class="font-semibold truncate">{{ p.name }}</p>
          <p class="text-xs text-slate-500">{{ p.sku || 'Sans référence' }} · {{ money(p.price) }}</p>
        </div>
        <div class="text-right shrink-0">
          <p :class="['text-2xl font-bold leading-none', p.available <= 0 ? 'text-red-600' : p.is_low ? 'text-amber-600' : '']">{{ p.available }}</p>
          <p class="text-xs text-slate-500">disponible{{ p.available > 1 ? 's' : '' }}</p>
        </div>
      </div>
      <div v-if="p.levels.length" class="flex flex-wrap gap-1.5">
        <span v-for="l in p.levels" :key="l.location_id" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs">
          {{ l.hub_id ? '🏬' : '🏠' }} {{ l.hub_id ? l.label : 'Chez moi' }} : <strong>{{ l.on_hand }}</strong><span v-if="l.reserved" class="text-amber-700"> · {{ l.reserved }} réservé{{ l.reserved > 1 ? 's' : '' }}</span>
        </span>
      </div>
      <p v-if="p.is_low" class="text-xs font-medium text-amber-700">⚠️ {{ p.available <= 0 ? 'Rupture de stock' : 'Stock bas' }} (alerte à {{ p.low_stock_threshold }})</p>
    </button>

    <EmptyState
      v-if="loaded && !products.length"
      icon="📦"
      :title="filter === 'low' ? 'Aucun produit en stock bas' : 'Aucun produit'"
      :text="filter === 'low' ? '' : 'Ajoutez vos produits pour suivre votre stock et les choisir en créant une course. Vous pouvez aussi les déposer à l\'entrepôt de l\'agence.'"
    />

    <!-- Fiche produit -->
    <BottomSheet :open="!!current" :title="current?.name || ''" @close="current = null">
      <div v-if="current" class="space-y-4">
        <div class="m-card divide-y">
          <div v-for="l in current.levels" :key="l.location_id" class="p-3 flex justify-between text-sm">
            <span>{{ l.hub_id ? `🏬 ${l.label}` : '🏠 Chez moi' }}</span>
            <span><strong>{{ l.available }}</strong> dispo<span class="text-slate-500"> · {{ l.on_hand }} en stock</span></span>
          </div>
          <p v-if="!current.levels.length" class="p-3 text-sm text-slate-500">Aucun stock enregistré.</p>
        </div>

        <div v-if="canManage" class="space-y-2">
          <p class="text-sm font-medium">Mon stock à la maison</p>
          <div class="grid grid-cols-3 gap-2">
            <button v-for="a in actions" :key="a.value" :class="['tap rounded-xl py-3 text-sm font-medium ring-1', move.action === a.value ? 'text-white ring-transparent' : 'bg-white ring-slate-200']" :style="move.action === a.value ? { backgroundColor: 'var(--app-color)' } : {}" @click="move.action = a.value; move.quantity = a.value === 'count' ? homeLevel?.on_hand ?? 0 : 1">
              {{ a.label }}
            </button>
          </div>
          <div class="flex gap-2">
            <input v-model.number="move.quantity" type="number" min="0" inputmode="numeric" class="m-input text-lg font-semibold" :aria-label="move.action === 'count' ? 'Quantité comptée' : 'Quantité'">
            <button class="m-btn-primary w-auto px-5" :disabled="move.saving" @click="saveMove">OK</button>
          </div>
          <p class="text-xs text-slate-500">{{ actions.find((a) => a.value === move.action).help }}</p>
          <p v-if="move.error" class="text-sm text-red-600">{{ move.error }}</p>
          <p class="text-xs text-slate-500">Le stock à l'entrepôt est tenu par l'agence : déposez vos produits et elle les enregistre.</p>
        </div>

        <div v-if="history.length" class="space-y-1">
          <p class="text-sm font-medium">Derniers mouvements</p>
          <div class="m-card divide-y text-sm">
            <div v-for="m in history" :key="m.id" class="p-3 flex justify-between gap-2">
              <div class="min-w-0">
                <p>{{ m.type_label }} · {{ m.location?.hub_id ? m.location.label : 'Chez moi' }}</p>
                <p class="text-xs text-slate-500 truncate">{{ dateTime(m.created_at) }}<span v-if="m.order"> · {{ m.order.tracking_code }}</span><span v-if="m.note"> · {{ m.note }}</span></p>
              </div>
              <p :class="['font-semibold whitespace-nowrap', m.on_hand_change > 0 ? 'text-emerald-700' : m.on_hand_change < 0 ? 'text-red-600' : 'text-slate-500']">
                {{ m.on_hand_change ? (m.on_hand_change > 0 ? '+' : '') + m.on_hand_change : `${m.reserved_change > 0 ? '+' : ''}${m.reserved_change} rés.` }}
              </p>
            </div>
          </div>
        </div>

        <button v-if="canManage" class="m-btn-secondary" @click="openForm(current)">Modifier le produit</button>
      </div>
    </BottomSheet>

    <!-- Formulaire produit -->
    <BottomSheet :open="form.open" :title="form.id ? 'Modifier le produit' : 'Nouveau produit'" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="saveForm">
        <div><label class="m-label" for="p-name">Nom *</label><input id="p-name" v-model="form.data.name" class="m-input" required placeholder="Ex. : Robe wax taille M"></div>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="m-label" for="p-price">Prix de vente</label><input id="p-price" v-model.number="form.data.price" type="number" min="0" inputmode="numeric" class="m-input"></div>
          <div><label class="m-label" for="p-sku">Référence</label><input id="p-sku" v-model="form.data.sku" class="m-input"></div>
        </div>
        <div>
          <label class="m-label" for="p-threshold">M'alerter quand il en reste</label>
          <input id="p-threshold" v-model.number="form.data.low_stock_threshold" type="number" min="0" inputmode="numeric" class="m-input" placeholder="Pas d'alerte">
        </div>
        <div v-if="!form.id">
          <label class="m-label" for="p-qty">Quantité que j'ai chez moi</label>
          <input id="p-qty" v-model.number="form.data.initial" type="number" min="0" inputmode="numeric" class="m-input" placeholder="0">
        </div>
        <label v-else class="flex items-center gap-2 text-sm"><input v-model="form.data.is_active" type="checkbox" class="h-5 w-5 accent-[var(--app-color)]"> Proposer ce produit dans mes courses</label>
        <p v-if="form.error" class="text-sm text-red-600">{{ form.error }}</p>
        <button class="m-btn-primary" :disabled="form.saving">Enregistrer</button>
      </form>
    </BottomSheet>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { dateTime, money } from '../../utils/format'

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const canManage = computed(() => auth.can('stock.manage'))

const tabs = [
  { value: 'all', label: 'Tous' },
  { value: 'low', label: 'Stock bas' },
]
const actions = [
  { value: 'receipt', label: '+ Entrée', help: 'Ajoutez les articles reçus ou fabriqués.' },
  { value: 'withdrawal', label: '− Sortie', help: 'Articles vendus en boutique, offerts ou abîmés.' },
  { value: 'count', label: 'Inventaire', help: 'Indiquez combien vous en avez réellement : le stock est corrigé.' },
]

const products = ref([])
const contracts = ref([])
const loaded = ref(false)
const search = ref('')
const filter = ref(route.query.filtre === 'bas' ? 'low' : 'all')
const current = ref(null)
const history = ref([])
const move = reactive({ action: 'receipt', quantity: 1, error: '', saving: false })
const form = reactive({ open: false, id: null, data: {}, error: '', saving: false })

const homeLevel = computed(() => current.value?.levels.find((l) => !l.hub_id))
const activeContracts = computed(() => contracts.value.filter((c) => c.is_active && c.billing_type !== 'free'))

async function load() {
  const { data } = await http.get('/products', { params: { per_page: 200, search: search.value || undefined, low: filter.value === 'low' ? 1 : undefined } })
  products.value = data.data
  loaded.value = true
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
}

async function openProduct(p) {
  current.value = p
  history.value = []
  Object.assign(move, { action: 'receipt', quantity: 1, error: '' })
  const { data } = await http.get(`/products/${p.id}`)
  current.value = data.data
  history.value = data.movements.slice(0, 10)
}

async function saveMove() {
  move.saving = true
  move.error = ''
  try {
    const { data } = await http.post('/stock/movements', { product_id: current.value.id, action: move.action, quantity: move.quantity || 0 })
    toasts.success(data.data ? 'Stock mis à jour.' : 'Inventaire conforme.')
    replace(data.product)
    await openProduct(data.product)
  } catch (e) {
    move.error = apiErrorMessage(e)
  } finally {
    move.saving = false
  }
}

function replace(product) {
  const index = products.value.findIndex((p) => p.id === product.id)
  if (index >= 0) products.value[index] = product
  else products.value.unshift(product)
}

function openForm(p = null) {
  current.value = null
  Object.assign(form, {
    open: true,
    id: p?.id ?? null,
    error: '',
    data: p
      ? { name: p.name, price: p.price, sku: p.sku, low_stock_threshold: p.low_stock_threshold, is_active: p.is_active }
      : { name: '', price: 0, sku: '', low_stock_threshold: null, initial: null },
  })
}

async function saveForm() {
  form.saving = true
  form.error = ''
  try {
    const { initial, ...fields } = form.data
    const payload = { ...fields, sku: fields.sku || null, low_stock_threshold: fields.low_stock_threshold === '' ? null : fields.low_stock_threshold }
    let product = form.id ? (await http.put(`/products/${form.id}`, payload)).data.data : (await http.post('/products', payload)).data.data
    if (!form.id && initial > 0) {
      product = (await http.post('/stock/movements', { product_id: product.id, action: 'receipt', quantity: initial, note: 'Stock de départ' })).data.product
    }
    replace(product)
    form.open = false
    toasts.success('Produit enregistré.')
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

onMounted(async () => {
  await load()
  contracts.value = (await http.get('/storage-contracts').catch(() => ({ data: { data: [] } }))).data.data
})
</script>
