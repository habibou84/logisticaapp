<template>
  <div class="min-h-dvh bg-slate-50 text-slate-900 pb-28" :style="{ '--app-color': '#047857' }">
    <div v-if="error" class="max-w-md mx-auto px-4 py-24 text-center space-y-2">
      <p class="text-4xl" aria-hidden="true">🛍️</p>
      <p class="text-lg font-semibold">{{ error }}</p>
    </div>

    <p v-else-if="!shop" class="py-24 text-center text-slate-500">Chargement…</p>

    <!-- Commande envoyée -->
    <div v-else-if="done" class="max-w-md mx-auto px-4 py-10 space-y-5 text-center">
      <p class="text-5xl" aria-hidden="true">✅</p>
      <h1 class="text-2xl font-bold">Commande envoyée !</h1>
      <p class="text-slate-600">
        {{ shop.name }} a bien reçu votre commande. Un livreur vous appellera au moment de la livraison.
      </p>
      <div class="m-card p-4 space-y-1 text-left">
        <p class="text-sm text-slate-500">Articles</p>
        <p class="font-medium">{{ done.description }}</p>
        <p class="text-sm text-slate-500 pt-2">À payer au livreur</p>
        <p class="text-2xl font-bold">{{ money(done.total) }}</p>
        <p class="text-sm text-slate-500 pt-2">Code de suivi</p>
        <p class="font-mono font-semibold">{{ done.tracking_code }}</p>
      </div>
      <RouterLink :to="`/suivi/${done.tracking_code}`" class="m-btn-primary">📍 Suivre ma commande</RouterLink>
      <button type="button" class="text-sm text-slate-600 underline" @click="done = null">Retour à la boutique</button>
    </div>

    <template v-else>
      <!-- En-tête de la boutique -->
      <header class="bg-gradient-to-br from-emerald-700 to-teal-600 text-white">
        <div class="max-w-3xl mx-auto px-4 pt-8 pb-6 space-y-2">
          <h1 class="text-2xl md:text-3xl font-bold">{{ shop.name }}</h1>
          <p v-if="shop.intro" class="text-emerald-50/90">{{ shop.intro }}</p>
          <div class="flex flex-wrap gap-2 pt-1 text-sm">
            <span class="rounded-full bg-white/15 px-3 py-1">💵 Paiement à la livraison</span>
            <a v-if="contactLink" :href="contactLink" target="_blank" rel="noopener" class="rounded-full bg-white/15 px-3 py-1">💬 Contacter le vendeur</a>
          </div>
        </div>
      </header>

      <!-- Catalogue -->
      <main class="max-w-3xl mx-auto px-4 py-5">
        <p v-if="!shop.products.length" class="py-16 text-center text-slate-500">Aucun article pour le moment.</p>
        <ul class="grid grid-cols-2 md:grid-cols-3 gap-3">
          <li v-for="p in shop.products" :key="p.id" class="m-card overflow-hidden flex flex-col">
            <div class="aspect-square bg-slate-100 grid place-items-center">
              <img v-if="p.photo_url" :src="p.photo_url" :alt="p.name" class="h-full w-full object-cover" loading="lazy">
              <span v-else class="text-4xl" aria-hidden="true">🛍️</span>
            </div>
            <div class="p-3 flex flex-col gap-1 flex-1">
              <p class="font-medium leading-snug">{{ p.name }}</p>
              <p v-if="p.description" class="text-xs text-slate-500 line-clamp-2">{{ p.description }}</p>
              <p class="font-bold mt-auto pt-1">{{ money(p.price) }}</p>
              <p v-if="p.available === 0" class="text-sm font-medium text-red-600">Épuisé</p>
              <div v-else-if="cart[p.id]" class="flex items-center justify-between rounded-xl bg-emerald-50 p-1">
                <button type="button" class="tap h-9 w-9 rounded-lg bg-white text-lg font-bold shadow-sm" :aria-label="`Retirer un ${p.name}`" @click="add(p, -1)">−</button>
                <span class="font-semibold" aria-live="polite">{{ cart[p.id] }}</span>
                <button type="button" class="tap h-9 w-9 rounded-lg bg-white text-lg font-bold shadow-sm disabled:opacity-40" :disabled="atMax(p)" :aria-label="`Ajouter un ${p.name}`" @click="add(p, 1)">+</button>
              </div>
              <button v-else type="button" class="tap rounded-xl bg-emerald-600 py-2 text-sm font-semibold text-white" @click="add(p, 1)">Ajouter</button>
            </div>
          </li>
        </ul>
        <p class="mt-8 text-center text-xs text-slate-500">
          Livraison assurée par <strong>{{ shop.delivery_company.name }}</strong>
        </p>
      </main>

      <!-- Panier -->
      <div v-if="count" class="fixed inset-x-0 bottom-0 z-20 bg-white/95 backdrop-blur border-t border-slate-200 pb-safe">
        <div class="max-w-3xl mx-auto px-4 py-3 flex items-center gap-3">
          <div class="flex-1">
            <p class="text-sm text-slate-500">{{ count }} article{{ count > 1 ? 's' : '' }}</p>
            <p class="text-lg font-bold">{{ money(itemsTotal) }}</p>
          </div>
          <button type="button" class="tap rounded-2xl bg-emerald-600 px-6 py-3.5 font-semibold text-white shadow-sm" @click="checkout = true">Commander</button>
        </div>
      </div>

      <!-- Coordonnées de livraison -->
      <BottomSheet :open="checkout" title="Votre commande" @close="checkout = false">
        <form class="space-y-4" @submit.prevent="submit">
          <ul class="divide-y rounded-xl bg-slate-50 px-3 text-sm">
            <li v-for="line in lines" :key="line.id" class="flex justify-between py-2 gap-2">
              <span>{{ line.quantity }} × {{ line.name }}</span><span class="font-medium whitespace-nowrap">{{ money(line.quantity * line.price) }}</span>
            </li>
          </ul>

          <div>
            <label class="m-label" for="shop-name">Votre nom</label>
            <input id="shop-name" v-model.trim="form.name" class="m-input" autocomplete="name" required maxlength="120">
          </div>
          <div>
            <label class="m-label" for="shop-phone">Téléphone</label>
            <input id="shop-phone" v-model.trim="form.phone" class="m-input" type="tel" inputmode="tel" autocomplete="tel" required placeholder="07 00 00 00 00">
          </div>
          <div>
            <label class="m-label" for="shop-zone">Commune ou quartier</label>
            <select id="shop-zone" v-model="form.zone_id" class="m-input" required @change="quote">
              <option :value="null" disabled>Choisissez…</option>
              <option v-for="z in shop.zones" :key="z.id" :value="z.id">{{ z.name }}</option>
            </select>
          </div>
          <div>
            <label class="m-label" for="shop-address">Adresse de livraison</label>
            <input id="shop-address" v-model.trim="form.address" class="m-input" autocomplete="street-address" required maxlength="500" placeholder="Rue, immeuble, quartier…">
          </div>
          <div>
            <label class="m-label" for="shop-landmark">Repère (facultatif)</label>
            <input id="shop-landmark" v-model.trim="form.landmark" class="m-input" maxlength="255" placeholder="Ex. : en face de la pharmacie">
          </div>
          <div>
            <label class="m-label" for="shop-note">Message au vendeur (facultatif)</label>
            <textarea id="shop-note" v-model.trim="form.note" class="m-input" rows="2" maxlength="500" />
          </div>
          <!-- Piège à robots : invisible pour les personnes -->
          <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" class="absolute -left-[9999px] h-0 w-0 opacity-0" aria-hidden="true">

          <div class="rounded-xl bg-slate-50 p-3 text-sm space-y-1">
            <p class="flex justify-between"><span>Articles</span><span>{{ money(itemsTotal) }}</span></p>
            <p class="flex justify-between">
              <span>Livraison</span>
              <span v-if="!pricing">{{ form.zone_id ? 'Calcul…' : 'Choisissez votre commune' }}</span>
              <span v-else-if="pricing.fee_payer === 'merchant'" class="text-emerald-700 font-medium">Offerte</span>
              <span v-else>{{ money(pricing.delivery_fee) }}</span>
            </p>
            <p class="flex justify-between pt-1 border-t border-slate-200 text-base font-bold">
              <span>À payer au livreur</span><span>{{ money(pricing ? pricing.total : itemsTotal) }}</span>
            </p>
          </div>

          <p v-if="formError" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2" role="alert">{{ formError }}</p>
          <button type="submit" class="m-btn-primary" :disabled="sending || !count">{{ sending ? 'Envoi…' : 'Confirmer la commande' }}</button>
          <p class="text-center text-xs text-slate-500">Vous payez à la livraison, en espèces ou par Mobile Money.</p>
        </form>
      </BottomSheet>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import { whatsappLink } from '../../composables/useBranding'
import { money } from '../../utils/format'

const route = useRoute()
const slug = String(route.params.slug || '').toLowerCase()
const CART_KEY = `shop-cart:${slug}`
const CONTACT_KEY = 'shop-contact'

const shop = ref(null)
const error = ref('')
const cart = reactive(readJson(CART_KEY) || {})
const checkout = ref(false)
const pricing = ref(null)
const sending = ref(false)
const formError = ref('')
const done = ref(null)
// Coordonnées gardées sur l'appareil pour la commande suivante
const form = reactive({ name: '', phone: '', zone_id: null, address: '', landmark: '', note: '', website: '', ...readJson(CONTACT_KEY) })

const products = computed(() => Object.fromEntries((shop.value?.products ?? []).map((p) => [p.id, p])))
const lines = computed(() => Object.entries(cart)
  .filter(([id, q]) => products.value[id] && q > 0)
  .map(([id, quantity]) => ({ ...products.value[id], quantity })))
const count = computed(() => lines.value.reduce((n, l) => n + l.quantity, 0))
const itemsTotal = computed(() => lines.value.reduce((n, l) => n + l.quantity * l.price, 0))
const items = computed(() => lines.value.map((l) => ({ product_id: l.id, quantity: l.quantity })))
const contactLink = computed(() => whatsappLink(shop.value?.contact_phone, `Bonjour, je vous contacte depuis votre boutique en ligne.`))

function readJson(key) {
  try { return JSON.parse(localStorage.getItem(key)) } catch { return null }
}
function writeJson(key, value) {
  try { localStorage.setItem(key, JSON.stringify(value)) } catch { /* stockage indisponible */ }
}

function atMax(p) {
  return (p.available !== null && cart[p.id] >= p.available) || cart[p.id] >= 50
}

function add(p, delta) {
  const next = (cart[p.id] || 0) + delta
  if (next <= 0) delete cart[p.id]
  else if (!(delta > 0 && atMax(p))) cart[p.id] = next
}

watch(cart, () => {
  writeJson(CART_KEY, cart)
  quote()
}, { deep: true })

let quoteSeq = 0
async function quote() {
  pricing.value = null
  if (!form.zone_id || !items.value.length) return
  const seq = ++quoteSeq
  try {
    const { data } = await http.post(`/shops/${slug}/quote`, { zone_id: form.zone_id, items: items.value })
    if (seq === quoteSeq) pricing.value = data.data
  } catch (e) {
    if (seq === quoteSeq) formError.value = apiErrorMessage(e)
  }
}

async function submit() {
  sending.value = true
  formError.value = ''
  try {
    const { data } = await http.post(`/shops/${slug}/orders`, { ...form, items: items.value })
    const { name, phone, zone_id, address, landmark } = form
    writeJson(CONTACT_KEY, { name, phone, zone_id, address, landmark })
    for (const id of Object.keys(cart)) delete cart[id]
    form.note = ''
    checkout.value = false
    done.value = data.data
    window.scrollTo({ top: 0 })
    load()
  } catch (e) {
    const errors = e.response?.data?.errors
    formError.value = errors ? Object.values(errors)[0][0] : apiErrorMessage(e, 'La commande n\'a pas pu être envoyée.')
  } finally {
    sending.value = false
  }
}

async function load() {
  try {
    shop.value = (await http.get(`/shops/${slug}`)).data.data
    document.title = shop.value.name
    // Articles retirés ou épuisés depuis la dernière visite
    for (const id of Object.keys(cart)) {
      const p = products.value[id]
      if (!p || p.available === 0) delete cart[id]
      else if (p.available !== null && cart[id] > p.available) cart[id] = p.available
    }
    if (form.zone_id && !shop.value.zones.some((z) => z.id === form.zone_id)) form.zone_id = null
  } catch (e) {
    error.value = e.response?.status === 404 ? 'Cette boutique n\'existe pas ou est fermée pour le moment.' : apiErrorMessage(e)
  }
}

watch(checkout, (open) => { if (open) { formError.value = ''; quote() } })

onMounted(load)
</script>
