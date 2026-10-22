<template>
  <div class="space-y-4">
    <p v-if="!settings" class="text-slate-500">Chargement…</p>

    <template v-else>
      <!-- Ouverture et lien -->
      <section class="m-card p-4 space-y-4">
        <ToggleRow
          v-model="settings.shop_enabled"
          label="Boutique ouverte"
          :description="settings.shop_enabled ? 'Vos clients peuvent commander depuis le lien.' : 'Ouvrez-la pour obtenir votre lien de commande.'"
          @update:model-value="save({ shop_enabled: $event })"
        />

        <div v-if="settings.shop_enabled && settings.url" class="space-y-3">
          <div class="rounded-xl bg-emerald-50 p-3">
            <p class="text-xs text-emerald-800">Votre lien de commande</p>
            <a :href="settings.url" target="_blank" rel="noopener" class="font-semibold text-emerald-900 break-all">{{ settings.url }}</a>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <button type="button" class="m-btn-secondary !py-3" @click="copy">{{ copied ? '✓ Copié' : '📋 Copier' }}</button>
            <a :href="shareLink" target="_blank" rel="noopener" class="m-btn-success !py-3">💬 Partager</a>
          </div>
          <p class="text-xs text-slate-500">
            Mettez ce lien dans vos statuts WhatsApp, sur Facebook, Instagram ou TikTok. Chaque commande devient une
            course, en paiement à la livraison.
          </p>
        </div>

        <form class="space-y-3 border-t pt-4" @submit.prevent="save({ shop_slug: slug, shop_intro: intro })">
          <div>
            <label class="m-label" for="shop-slug">Lien de la boutique</label>
            <div class="flex items-center rounded-xl border border-slate-300 bg-white focus-within:ring-2 focus-within:ring-[var(--app-color)]">
              <span class="pl-3 text-sm text-slate-500 whitespace-nowrap">{{ origin }}/b/</span>
              <input id="shop-slug" v-model.trim="slug" class="flex-1 min-w-0 bg-transparent px-1 py-3.5 text-base focus:outline-none" maxlength="60" :placeholder="settings.suggested_slug" autocapitalize="off">
            </div>
          </div>
          <div>
            <label class="m-label" for="shop-intro">Présentation (facultatif)</label>
            <textarea id="shop-intro" v-model.trim="intro" class="m-input" rows="2" maxlength="300" placeholder="Ex. : Pagnes, robes et accessoires, livrés partout à Abidjan." />
          </div>
          <p v-if="error" class="field-error">{{ error }}</p>
          <button class="m-btn-primary" :disabled="saving">Enregistrer</button>
        </form>
      </section>

      <!-- Livraison des commandes de la boutique -->
      <section class="m-card p-4 space-y-3">
        <h2 class="font-semibold">Qui paie la livraison des commandes de la boutique ?</h2>
        <div class="grid gap-2" role="radiogroup" aria-label="Qui paie la livraison">
          <button
            v-for="o in FEE_PAYERS" :key="o.value" type="button" role="radio" :aria-checked="settings.shop_fee_payer === o.value"
            :class="['tap rounded-xl p-3 text-left ring-1 transition', settings.shop_fee_payer === o.value ? 'ring-2 ring-[var(--app-color)] bg-emerald-50' : 'ring-slate-200 bg-white']"
            @click="setFeePayer(o.value)"
          >
            <span class="block font-medium">{{ o.label }}</span>
            <span class="block text-sm text-slate-500">{{ o.text }}</span>
          </button>
        </div>
        <p class="text-xs text-slate-500">Ce choix ne concerne que la boutique en ligne : vos autres courses gardent leur réglage habituel.</p>
      </section>

      <!-- Articles -->
      <section class="space-y-3">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold text-lg">Articles proposés</h2>
          <button type="button" class="text-sm font-semibold" :style="{ color: 'var(--app-color)' }" @click="openNew">+ Ajouter</button>
        </div>
        <p class="text-sm text-slate-500">Les articles de votre stock apparaissent ici. Le stock suivi est réservé à chaque commande ; un article épuisé ne peut pas être commandé.</p>

        <EmptyState v-if="!products.length" icon="🛍️" title="Aucun article" text="Ajoutez vos articles avec une photo et un prix." />

        <div v-for="p in products" :key="p.id" class="m-card p-3 flex gap-3">
          <label class="relative h-20 w-20 shrink-0 rounded-xl bg-slate-100 overflow-hidden grid place-items-center cursor-pointer" :title="p.photo_url ? 'Changer la photo' : 'Ajouter une photo'">
            <img v-if="p.photo_url" :src="p.photo_url" :alt="p.name" class="h-full w-full object-cover">
            <span v-else class="text-center text-xs text-slate-500 px-1">📷<br>Photo</span>
            <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="upload(p, $event)">
            <span v-if="uploading === p.id" class="absolute inset-0 bg-white/70 grid place-items-center text-xs">Envoi…</span>
          </label>
          <div class="flex-1 min-w-0 space-y-1">
            <p class="font-medium truncate">{{ p.name }}</p>
            <p class="text-sm"><strong>{{ money(p.price) }}</strong>
              <span v-if="p.levels?.length" :class="['ml-2', p.available > 0 ? 'text-slate-500' : 'text-red-600']">· {{ p.available > 0 ? `${p.available} en stock` : 'épuisé' }}</span>
            </p>
            <div class="flex items-center gap-3 pt-1 text-sm">
              <label class="flex items-center gap-1.5">
                <input type="checkbox" :checked="p.shop_visible" @change="update(p, { shop_visible: $event.target.checked })"> En boutique
              </label>
              <button type="button" class="underline text-slate-600" @click="openEdit(p)">Modifier</button>
            </div>
          </div>
        </div>
      </section>
    </template>

    <!-- Ajout ou modification d'un article -->
    <BottomSheet :open="editor.open" :title="editor.id ? 'Modifier l\'article' : 'Nouvel article'" @close="editor.open = false">
      <form class="space-y-4" @submit.prevent="saveProduct">
        <div>
          <label class="m-label" for="p-name">Nom</label>
          <input id="p-name" v-model.trim="editor.name" class="m-input" required maxlength="255" placeholder="Ex. : Robe wax">
        </div>
        <div>
          <label class="m-label" for="p-price">Prix (F CFA)</label>
          <input id="p-price" v-model.number="editor.price" class="m-input" type="number" inputmode="numeric" min="0" required>
        </div>
        <div>
          <label class="m-label" for="p-desc">Description (facultatif)</label>
          <textarea id="p-desc" v-model.trim="editor.description" class="m-input" rows="2" maxlength="1000" placeholder="Tailles, couleurs…" />
        </div>
        <p v-if="editor.error" class="field-error">{{ editor.error }}</p>
        <button class="m-btn-primary" :disabled="editor.saving">Enregistrer</button>
        <button v-if="editor.id && editor.photo" type="button" class="m-btn-secondary" @click="removePhoto">Retirer la photo</button>
      </form>
    </BottomSheet>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import ToggleRow from '../../components/mobile/ToggleRow.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'
import { siteOrigin } from '../../native'

const auth = useAuthStore()
const toasts = useToastStore()
const origin = new URL(siteOrigin()).host

const settings = ref(null)
const slug = ref('')
const intro = ref('')
const saving = ref(false)
const error = ref('')
const copied = ref(false)
const products = ref([])
const uploading = ref(null)
const editor = reactive({ open: false, id: null, name: '', price: null, description: '', photo: null, saving: false, error: '' })

const FEE_PAYERS = [
  { value: 'recipient', label: '🧾 Le client', text: 'Le prix de la livraison, selon sa commune, s\'ajoute au total qu\'il paie au livreur.' },
  { value: 'merchant', label: '🎁 Moi (livraison offerte)', text: 'Le client voit « Livraison offerte » ; le prix est retiré de vos reversements.' },
]

async function setFeePayer(value) {
  if (settings.value.shop_fee_payer === value) return
  const previous = settings.value.shop_fee_payer
  settings.value.shop_fee_payer = value
  try {
    apply((await http.patch('/shop', { shop_fee_payer: value })).data.data)
    toasts.success(value === 'merchant' ? 'Livraison offerte à vos clients.' : 'La livraison est payée par vos clients.')
  } catch (e) {
    settings.value.shop_fee_payer = previous
    toasts.error(apiErrorMessage(e))
  }
}

const shareLink = computed(() => `https://wa.me/?text=${encodeURIComponent(`Commandez chez ${auth.user?.merchant?.business_name ?? 'nous'} : ${settings.value?.url} (paiement à la livraison)`)}`)

function apply(data) {
  settings.value = data
  slug.value = data.shop_slug || ''
  intro.value = data.shop_intro || ''
}

async function save(payload) {
  saving.value = true
  error.value = ''
  try {
    apply((await http.patch('/shop', payload)).data.data)
    toasts.success(payload.shop_enabled === false ? 'Boutique fermée.' : 'Boutique enregistrée.')
  } catch (e) {
    error.value = apiErrorMessage(e)
    if (payload.shop_enabled !== undefined) settings.value.shop_enabled = !payload.shop_enabled
  } finally {
    saving.value = false
  }
}

async function copy() {
  try {
    await navigator.clipboard.writeText(settings.value.url)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    window.prompt('Copiez le lien :', settings.value.url)
  }
}

async function loadProducts() {
  products.value = (await http.get('/products', { params: { per_page: 200 } })).data.data
}

function replace(product) {
  const i = products.value.findIndex((p) => p.id === product.id)
  if (i >= 0) products.value[i] = product
  else products.value.push(product)
}

async function update(p, payload) {
  try {
    replace((await http.patch(`/products/${p.id}`, payload)).data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function upload(p, event) {
  const file = event.target.files[0]
  event.target.value = ''
  if (!file) return
  uploading.value = p.id
  try {
    const body = new FormData()
    body.append('photo', file)
    replace((await http.post(`/products/${p.id}/photo`, body)).data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    uploading.value = null
  }
}

function openNew() {
  Object.assign(editor, { open: true, id: null, name: '', price: null, description: '', photo: null, error: '' })
}

function openEdit(p) {
  Object.assign(editor, { open: true, id: p.id, name: p.name, price: p.price, description: p.description || '', photo: p.photo_url, error: '' })
}

async function saveProduct() {
  editor.saving = true
  editor.error = ''
  try {
    const payload = { name: editor.name, price: editor.price || 0, description: editor.description || null }
    const { data } = editor.id ? await http.patch(`/products/${editor.id}`, payload) : await http.post('/products', { ...payload, shop_visible: true })
    replace(data.data)
    editor.open = false
    toasts.success(editor.id ? 'Article modifié.' : 'Article ajouté : touchez le cadre pour ajouter sa photo.')
  } catch (e) {
    editor.error = apiErrorMessage(e)
  } finally {
    editor.saving = false
  }
}

async function removePhoto() {
  await http.delete(`/products/${editor.id}/photo`)
  replace({ ...products.value.find((p) => p.id === editor.id), photo_url: null })
  editor.photo = null
}

onMounted(async () => {
  apply((await http.get('/shop')).data.data)
  await loadProducts()
})
</script>
