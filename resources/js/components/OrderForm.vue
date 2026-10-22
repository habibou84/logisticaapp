<template>
  <form ref="formEl" class="space-y-5" @submit.prevent="submit()" @keydown.ctrl.enter.prevent="submit()">
    <div v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</div>

    <section v-if="!isMerchant" class="card p-4">
      <label class="label" for="merchant">E-commerçant *</label>
      <select id="merchant" v-model="form.merchant_id" class="input" required>
        <option :value="null" disabled>Choisir…</option>
        <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.business_name }}</option>
      </select>
      <p v-if="errors.merchant_id" class="field-error">{{ errors.merchant_id[0] }}</p>
    </section>

    <PasteMessage :merchant-id="merchantId" @parsed="applyParsed" />

    <section class="card p-4 space-y-3">
      <h3 class="font-semibold">Client et livraison</h3>
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="relative">
          <label class="label" for="phone">Téléphone * <span v-if="parsed.recipient_phone" class="check">à vérifier</span></label>
          <input
            id="phone" v-model="form.recipient_phone" :class="['input', mark('recipient_phone')]" required inputmode="tel"
            placeholder="07 00 00 00 00" autocomplete="off" @input="touched('recipient_phone'); searchRecipients()" @blur="checkDuplicates"
          >
          <ul v-if="suggestions.length" class="absolute z-10 mt-1 w-full bg-white border rounded shadow max-h-48 overflow-y-auto">
            <li v-for="r in suggestions" :key="r.id" class="px-3 py-2 text-sm hover:bg-slate-50 cursor-pointer" @click="pickRecipient(r)">
              <span class="font-medium">{{ r.name || 'Sans nom' }}</span> · {{ r.phone }}
              <span v-if="r.failed_count" class="text-xs text-red-600"> · {{ r.failed_count }} échec(s)</span>
            </li>
          </ul>
          <p v-if="errors.recipient_phone" class="field-error">{{ errors.recipient_phone[0] }}</p>
        </div>
        <div>
          <label class="label" for="name">Nom <span v-if="parsed.recipient_name" class="check">à vérifier</span></label>
          <input id="name" v-model="form.recipient_name" :class="['input', mark('recipient_name')]" @input="touched('recipient_name')">
        </div>
        <div v-if="duplicates.length" class="sm:col-span-2 rounded bg-amber-50 border border-amber-200 text-amber-900 text-sm px-3 py-2">
          ⚠️ Déjà {{ duplicates.length }} course(s) pour ce client chez cet e-commerçant ces dernières 24 h :
          <RouterLink v-for="d in duplicates" :key="d.id" :to="`${auth.homeRoute}/courses/${d.id}`" target="_blank" class="underline ml-1">
            {{ d.tracking_code }} ({{ d.status_label }}, {{ d.delivery_zone_name }}, {{ money(d.cod_amount) }})
          </RouterLink>
        </div>
        <div class="sm:col-span-2">
          <label class="label" for="zone">Commune / quartier de livraison * <span v-if="parsed.delivery_zone_id" class="check">à vérifier</span></label>
          <ZoneSelect id="zone" v-model="form.delivery_zone_id" :zones="zones" required :input-class="['input', mark('delivery_zone_id')]" @update:model-value="touched('delivery_zone_id')" />
          <div v-if="zoneChoices.length > 1" class="flex flex-wrap items-center gap-2 mt-1 text-sm">
            <span class="text-gray-600">Ou peut-être :</span>
            <button
              v-for="z in zoneChoices" :key="z.id" type="button"
              :class="['rounded-full px-3 py-0.5 ring-1', form.delivery_zone_id === z.id ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
              @click="form.delivery_zone_id = z.id"
            >
              {{ z.full_name }}
            </button>
          </div>
          <p v-if="errors.delivery_zone_id" class="field-error">{{ errors.delivery_zone_id[0] }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="label" for="address">Adresse et repère <span v-if="parsed.delivery_address" class="check">à vérifier</span></label>
          <input id="address" v-model="form.delivery_address" :class="['input', mark('delivery_address')]" placeholder="Ex. : Siporex, résidence Les Palmiers, face pharmacie" @input="touched('delivery_address')">
        </div>
        <div id="when-field" class="sm:col-span-2">
          <p class="label">Quand livrer ? <span class="text-red-600">*</span></p>
          <div class="flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Jour de livraison">
            <button
              v-for="w in deliveryOptions" :key="w.value" type="button" role="radio" :aria-checked="when === w.value" :disabled="w.disabled"
              :class="['rounded-full px-3 py-1 text-sm ring-1 disabled:opacity-40 disabled:cursor-not-allowed', when === w.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300', errors.when ? 'ring-red-500' : '']"
              @click="when = w.value"
            >
              {{ w.label }} <span class="opacity-70">· {{ w.description }}</span>
            </button>
            <input v-if="when === 'other'" v-model="form.delivery_scheduled_date" type="date" :min="minDate" class="input w-auto" aria-label="Jour de livraison">
            <select v-model="form.delivery_time_slot" class="input w-auto" aria-label="Créneau">
              <option :value="null">Créneau indifférent</option>
              <option value="08-12">Matin (8h-12h)</option>
              <option value="12-16">Après-midi (12h-16h)</option>
              <option value="16-20">Soir (16h-20h)</option>
            </select>
          </div>
          <p v-if="closed" class="text-xs text-gray-500 mt-1">🌙 {{ closedReason }} : le ramassage peut quand même se faire aujourd'hui.</p>
          <p v-if="dateSummary" class="text-sm font-medium mt-1">{{ dateSummary }}</p>
          <p v-if="errors.when" class="field-error">{{ errors.when[0] }}</p>
          <p v-if="errors.delivery_scheduled_date" class="field-error">{{ errors.delivery_scheduled_date[0] }}</p>
        </div>
      </div>
    </section>

    <section class="card p-4 space-y-3">
      <h3 class="font-semibold">Paiement</h3>
      <div class="grid sm:grid-cols-2 gap-3">
        <div>
          <label class="label" for="items">Montant des articles à encaisser (F) <span v-if="parsed.items_amount" class="check">à vérifier</span></label>
          <input id="items" v-model.number="form.items_amount" type="number" min="0" step="50" :class="['input', mark('items_amount')]" @input="touched('items_amount')">
        </div>
        <div>
          <label class="label" for="payer">Frais de livraison payés par <span class="text-red-600">*</span> <span v-if="parsed.fee_payer" class="check">à vérifier</span></label>
          <select id="payer" v-model="form.fee_payer" :class="['input', errors.fee_payer ? 'border-red-500 ring-1 ring-red-500' : mark('fee_payer')]" required @change="touched('fee_payer')">
            <option :value="null" disabled>Choisissez…</option>
            <option value="merchant">{{ isMerchant ? 'Moi (déduits du reversement)' : 'Le marchand (déduits du reversement)' }}</option>
            <option value="recipient">Le destinataire (ajoutés à l'encaissement)</option>
          </select>
          <p v-if="errors.fee_payer" class="field-error">{{ errors.fee_payer[0] }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="label" for="note">Instruction pour le livreur <span v-if="parsed.merchant_note" class="check">à vérifier</span></label>
          <input id="note" v-model="form.merchant_note" :class="['input', mark('merchant_note')]" placeholder="Facultatif : ex. appeler avant de passer" @input="touched('merchant_note')">
        </div>
      </div>
    </section>

    <details class="card p-4" :open="moreOpen" @toggle="moreOpen = $event.target.open">
      <summary class="font-semibold cursor-pointer">Plus d'options <span class="text-sm font-normal text-gray-500">(second numéro, express, fragile, contenu, référence{{ products.length || hubs.length ? ', articles du stock' : '' }}, ramassage)</span></summary>
      <div class="space-y-4 mt-3">
        <div class="grid sm:grid-cols-2 gap-3">
          <div>
            <label class="label" for="phone2">Second téléphone <span v-if="parsed.recipient_phone2" class="check">à vérifier</span></label>
            <input id="phone2" v-model="form.recipient_phone2" :class="['input', mark('recipient_phone2')]" inputmode="tel" @input="touched('recipient_phone2')">
            <p v-if="errors.recipient_phone2" class="field-error">{{ errors.recipient_phone2[0] }}</p>
          </div>
          <div>
            <label class="label" for="ref">Référence commande</label>
            <input id="ref" v-model="form.merchant_reference" class="input">
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="desc">Contenu du colis <span v-if="parsed.description" class="check">à vérifier</span></label>
            <input id="desc" v-model="form.description" :class="['input', mark('description')]" placeholder="Ex. : 2 robes, 1 sac" @input="touched('description')">
          </div>
          <label class="flex items-center gap-2 text-sm"><input v-model="form.is_express" type="checkbox"> ⚡ Express</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="form.is_fragile" type="checkbox"> 🥚 Fragile</label>
        </div>

        <div v-if="products.length || hubs.length" class="space-y-3 border-t pt-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h4 class="font-semibold">Articles du stock</h4>
            <select v-model="form.pickup_hub_id" class="input w-auto" aria-label="Provenance des articles">
              <option :value="null">Chez le marchand (ramassage)</option>
              <option v-for="h in hubs" :key="h.id" :value="h.id">🏬 {{ h.name }} (préparée à l'entrepôt)</option>
            </select>
          </div>
          <p v-if="form.pickup_hub_id" class="text-sm rounded bg-sky-50 text-sky-900 p-2">
            La commande sera préparée à l'entrepôt puis livrée : pas de ramassage chez le marchand.
          </p>
          <div v-for="(item, i) in items" :key="i" class="grid grid-cols-[1fr_6rem_7rem_auto] gap-2 items-center">
            <select v-if="item.product_id !== undefined" v-model="item.product_id" class="input" :aria-label="`Produit ${i + 1}`" @change="pickProduct(item)">
              <option :value="null" disabled>Choisir un produit…</option>
              <option v-for="p in products" :key="p.id" :value="p.id" :disabled="availableAt(p) <= 0">
                {{ p.name }}{{ p.sku ? ` (${p.sku})` : '' }} · {{ availableAt(p) }} dispo
              </option>
            </select>
            <input v-else v-model="item.label" class="input" placeholder="Article libre (hors stock)" :aria-label="`Article ${i + 1}`">
            <input v-model.number="item.quantity" type="number" min="1" class="input" :aria-label="`Quantité ${i + 1}`">
            <input v-model.number="item.unit_price" type="number" min="0" step="50" class="input" placeholder="Prix" :aria-label="`Prix unitaire ${i + 1}`">
            <button type="button" class="btn-secondary px-2" :aria-label="`Retirer l'article ${i + 1}`" @click="items.splice(i, 1)">✕</button>
          </div>
          <p v-if="errors.items || errors.pickup_hub_id" class="field-error">{{ (errors.items || errors.pickup_hub_id)[0] }}</p>
          <div class="flex flex-wrap gap-2">
            <button type="button" class="btn-secondary" :disabled="!products.length" @click="items.push({ product_id: null, quantity: 1, unit_price: 0 })">+ Produit du stock</button>
            <button v-if="!form.pickup_hub_id" type="button" class="btn-secondary" @click="items.push({ label: '', quantity: 1, unit_price: 0 })">+ Article libre</button>
          </div>
          <p v-if="items.length" class="text-sm text-gray-600">Total des articles : <strong>{{ money(itemsTotal) }}</strong> (reporté dans le montant à encaisser, modifiable)</p>
        </div>

        <div v-if="!form.pickup_hub_id" class="grid sm:grid-cols-2 gap-3 border-t pt-3">
          <p class="sm:col-span-2 text-sm text-gray-600">Lieu de ramassage, s'il diffère de l'adresse habituelle du marchand :</p>
          <div>
            <label class="label" for="pzone">Zone de ramassage</label>
            <select id="pzone" v-model="form.pickup_zone_id" class="input">
              <option :value="null">Adresse habituelle</option>
              <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="paddress">Adresse de ramassage</label>
            <input id="paddress" v-model="form.pickup_address" class="input">
          </div>
        </div>
      </div>
    </details>

    <div class="card p-4 flex flex-wrap items-center justify-between gap-3 sticky bottom-0">
      <div class="text-sm">
        <p v-if="quoteError" class="text-red-600">{{ quoteError }}</p>
        <template v-else-if="quote">
          <p>Frais de livraison : <strong>{{ money(quote.total) }}</strong></p>
          <p class="text-gray-600">À encaisser auprès du client : <strong>{{ money(codAmount) }}</strong></p>
        </template>
        <p v-else class="text-gray-500">Choisissez la zone pour voir le tarif.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" :disabled="saving" title="Garde l'e-commerçant pour enchaîner les saisies" @click="submit(true)">Enregistrer et nouvelle</button>
        <button type="submit" class="btn-primary" :disabled="saving" title="Ctrl + Entrée">{{ saving ? 'Enregistrement…' : submitLabel }}</button>
      </div>
    </div>
  </form>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { money } from '../utils/format'
import PasteMessage from './PasteMessage.vue'
import ZoneSelect from './ZoneSelect.vue'
import { useDeliveryDate } from '../composables/useDeliveryDate'

const props = defineProps({
  submitLabel: { type: String, default: 'Enregistrer la course' },
})
const emit = defineEmits(['saved', 'saved-again'])

const auth = useAuthStore()
const isMerchant = computed(() => !!auth.user?.merchant_id)

const form = reactive({
  merchant_id: null,
  recipient_name: '',
  recipient_phone: '',
  recipient_phone2: '',
  delivery_zone_id: null,
  delivery_address: '',
  delivery_landmark: '',
  delivery_scheduled_date: null,
  delivery_time_slot: null,
  description: '',
  items_amount: 0,
  // Aucun choix par défaut : qui paie la livraison doit être indiqué
  fee_payer: null,
  merchant_reference: '',
  is_express: false,
  is_fragile: false,
  merchant_note: '',
  pickup_zone_id: null,
  pickup_address: '',
  pickup_hub_id: null,
})
const items = ref([])
const hubs = ref([])
const products = ref([])

const zones = ref([])
const merchants = ref([])
const suggestions = ref([])
const quote = ref(null)
const quoteError = ref('')
const error = ref('')
const errors = ref({})
const saving = ref(false)

// Champs remplis par l'analyse d'un message : marqués « à vérifier » jusqu'à leur modification
const formEl = ref(null)
const parsed = reactive({})
const zoneChoices = ref([])
const duplicates = ref([])
const moreOpen = ref(false)
const mark = (key) => (parsed[key] ? 'ring-1 ring-amber-400 bg-amber-50' : '')
const touched = (key) => { delete parsed[key] }

// Jour de livraison : choix obligatoire, « Aujourd'hui » impossible quand la journée est fermée
const { when, options: deliveryOptions, closed, closedReason, minDate, summary: dateSummary, isChosen: dateChosen, load: loadDeliveryDay, reset: resetDate } = useDeliveryDate(form)

// Jour choisi : l'erreur disparaît
watch(dateChosen, (chosen) => { if (chosen && errors.value.when) errors.value = {} })

// Numéro ivoirien affiché comme on le tape (07 07 12 34 56)
const localPhone = (phone) => (phone || '').replace(/^\+225/, '')
const joinAddress = (...parts) => parts.map((p) => (p || '').trim()).filter(Boolean).join(', ')

function applyParsed(data) {
  Object.keys(parsed).forEach((k) => delete parsed[k])
  const set = (key, value) => {
    if (value === undefined || value === null || value === '') return
    form[key] = value
    parsed[key] = true
  }
  set('recipient_name', data.recipient_name)
  set('recipient_phone', localPhone(data.recipient_phone))
  set('recipient_phone2', localPhone(data.recipient_phone2))
  set('delivery_address', joinAddress(data.delivery_address, data.delivery_landmark))
  set('items_amount', data.items_amount)
  set('fee_payer', data.fee_payer)
  set('description', data.description)
  set('merchant_note', data.merchant_note)
  zoneChoices.value = data.zones || []
  if (zoneChoices.value.length) set('delivery_zone_id', zoneChoices.value[0].id)
  if (data.recipient_phone2 || data.description) moreOpen.value = true
  checkDuplicates()
}

// Même client chez le même marchand ces dernières 24 h : risque de doublon
async function checkDuplicates() {
  duplicates.value = []
  if (form.recipient_phone.replace(/\D/g, '').length < 8 || !merchantId.value) return
  try {
    const { data } = await http.get('/orders/duplicates', { params: { recipient_phone: form.recipient_phone, merchant_id: isMerchant.value ? undefined : merchantId.value } })
    duplicates.value = data.data
  } catch {
    // Simple alerte : sans réponse, on n'affiche rien
  }
}

const itemsTotal = computed(() => items.value.reduce((sum, i) => sum + (i.quantity || 0) * (i.unit_price || 0), 0))
const merchantId = computed(() => (isMerchant.value ? auth.user.merchant_id : form.merchant_id))
const hubZoneId = computed(() => hubs.value.find((h) => h.id === form.pickup_hub_id)?.zone_id)

// Disponible du produit à l'emplacement choisi (chez le marchand ou à l'entrepôt)
function availableAt(product) {
  return product.levels?.find((l) => (l.hub_id ?? null) === (form.pickup_hub_id ?? null))?.available ?? 0
}

function pickProduct(item) {
  const product = products.value.find((p) => p.id === item.product_id)
  if (product) item.unit_price = product.price
}

async function loadProducts() {
  products.value = []
  // Produits : catalogue ou stock, selon la formule de l'entreprise
  if (!merchantId.value || !(auth.hasFeature('stock') || auth.hasFeature('shop'))) return
  products.value = (await http.get('/products', { params: { merchant_id: merchantId.value, per_page: 200 } })).data.data
}

watch(itemsTotal, (total) => {
  if (items.value.length) form.items_amount = total
})
watch(() => form.pickup_hub_id, (hub) => {
  if (hub) items.value = items.value.filter((i) => i.product_id !== undefined)
})

const codAmount = computed(() => (form.items_amount || 0) + (form.fee_payer === 'recipient' && quote.value ? quote.value.total : 0))

onMounted(async () => {
  loadDeliveryDay()
  const requests = [http.get('/zones'), http.get('/hubs').catch(() => ({ data: { data: [] } }))]
  if (!isMerchant.value) requests.push(http.get('/merchants', { params: { per_page: 200, status: 'active' } }))
  const [z, h, m] = await Promise.all(requests)
  zones.value = z.data.data
  hubs.value = h.data.data
  if (m) merchants.value = m.data.data
  loadProducts()
})

watch(() => form.merchant_id, () => {
  items.value = []
  loadProducts()
  checkDuplicates()
})

// Devis en direct
let quoteTimer
watch(
  () => [form.merchant_id, form.delivery_zone_id, form.pickup_zone_id, form.pickup_hub_id, form.is_express, form.is_fragile],
  () => {
    clearTimeout(quoteTimer)
    quoteTimer = setTimeout(fetchQuote, 250)
  },
)

async function fetchQuote() {
  quote.value = null
  quoteError.value = ''
  if (!form.delivery_zone_id || (!isMerchant.value && !form.merchant_id)) return
  try {
    const { data } = await http.post('/quotes', {
      merchant_id: isMerchant.value ? undefined : form.merchant_id,
      delivery_zone_id: form.delivery_zone_id,
      pickup_zone_id: hubZoneId.value || form.pickup_zone_id || undefined,
      is_express: form.is_express,
      is_fragile: form.is_fragile,
    })
    quote.value = data.data
  } catch (e) {
    quoteError.value = apiErrorMessage(e)
  }
}

let searchTimer
function searchRecipients() {
  clearTimeout(searchTimer)
  const term = form.recipient_phone.replace(/\s/g, '')
  if (term.length < 4 || (!isMerchant.value && !form.merchant_id)) {
    suggestions.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    const { data } = await http.get('/recipients', {
      params: { search: term.slice(-8), merchant_id: isMerchant.value ? undefined : form.merchant_id },
    })
    suggestions.value = data.data
  }, 250)
}

function pickRecipient(r) {
  Object.assign(form, {
    recipient_name: r.name || '',
    recipient_phone: r.phone,
    recipient_phone2: r.phone2 || '',
    delivery_zone_id: r.zone_id,
    delivery_address: joinAddress(r.address, r.landmark),
  })
  suggestions.value = []
  checkDuplicates()
}

// Après « Enregistrer et nouvelle » : formulaire vidé, e-commerçant gardé
const blank = { ...form }
function reset() {
  Object.assign(form, { ...blank, merchant_id: form.merchant_id })
  items.value = []
  zoneChoices.value = []
  duplicates.value = []
  Object.keys(parsed).forEach((k) => delete parsed[k])
  resetDate()
  moreOpen.value = false
  document.getElementById('phone')?.focus()
}

async function submit(again = false) {
  if (saving.value) return
  if (!formEl.value?.reportValidity()) return
  saving.value = true
  error.value = ''
  errors.value = {}
  if (!dateChosen.value) {
    errors.value = { when: ['Choisissez quand livrer la course.'] }
    error.value = errors.value.when[0]
    saving.value = false
    document.getElementById('when-field')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    return
  }
  if (!form.fee_payer) {
    errors.value = { fee_payer: ['Indiquez qui paie la livraison : le marchand ou le client.'] }
    error.value = errors.value.fee_payer[0]
    saving.value = false
    document.getElementById('payer')?.focus()
    return
  }
  try {
    const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
    if (isMerchant.value) delete payload.merchant_id
    if (form.pickup_hub_id) {
      delete payload.pickup_zone_id
      delete payload.pickup_address
    }
    const lines = items.value.filter((i) => i.product_id || i.label?.trim())
    if (lines.length) {
      payload.items = lines.map((i) => (i.product_id
        ? { product_id: i.product_id, quantity: i.quantity || 1, unit_price: i.unit_price || 0 }
        : { label: i.label.trim(), quantity: i.quantity || 1, unit_price: i.unit_price || 0 }))
    }
    const { data } = await http.post('/orders', payload)
    if (again) {
      emit('saved-again', data.data)
      reset()
    } else {
      emit('saved', data.data)
    }
  } catch (e) {
    errors.value = e.response?.data?.errors || {}
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
