<template>
  <div class="space-y-6">
    <p class="text-sm text-gray-600">
      Connectez une boutique en ligne ou un logiciel : il crée les courses et suit leur livraison automatiquement.
      <a href="/developpeurs/api" target="_blank" class="text-blue-600 underline">Documentation de l'API</a>
    </p>

    <!-- Valeur secrète affichée une seule fois -->
    <div v-if="reveal" class="rounded-xl border-2 border-amber-300 bg-amber-50 p-4 space-y-2">
      <p class="font-semibold text-amber-900">{{ reveal.title }}</p>
      <p class="text-sm text-amber-900">Copiez-la maintenant : elle ne sera plus jamais affichée.</p>
      <div class="flex gap-2">
        <code class="flex-1 min-w-0 break-all rounded bg-white px-3 py-2 text-sm ring-1 ring-amber-200">{{ reveal.value }}</code>
        <button type="button" class="btn-primary" @click="copy(reveal.value)">Copier</button>
      </div>
      <button type="button" class="text-sm text-amber-900 underline" @click="reveal = null">C'est noté</button>
    </div>

    <!-- Clés API -->
    <section class="card p-4 space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold">Clés API</h2>
        <button type="button" class="btn-primary" :disabled="!ready" @click="keyForm.open = !keyForm.open">+ Nouvelle clé</button>
      </div>

      <form v-if="keyForm.open" class="rounded-lg bg-slate-50 p-3 space-y-3" @submit.prevent="createKey">
        <div>
          <label class="label" for="key-name">Nom *</label>
          <input id="key-name" v-model="keyForm.name" class="input" required placeholder="Ex. : Boutique Shopify">
        </div>
        <fieldset>
          <legend class="label">Autorisations</legend>
          <label v-for="s in scopes" :key="s.value" class="flex items-center gap-2 text-sm py-0.5">
            <input v-model="keyForm.scopes" type="checkbox" :value="s.value"> {{ s.label }} <code class="text-xs text-gray-500">{{ s.value }}</code>
          </label>
        </fieldset>
        <div>
          <label class="label" for="key-exp">Expire le (facultatif)</label>
          <input id="key-exp" v-model="keyForm.expires_at" type="date" class="input" :min="tomorrow">
        </div>
        <p v-if="keyForm.error" class="field-error">{{ keyForm.error }}</p>
        <button class="btn-primary" :disabled="!keyForm.scopes.length">Créer la clé</button>
      </form>

      <div class="divide-y">
        <div v-for="k in keys" :key="k.id" :class="['py-3 flex flex-wrap items-center justify-between gap-2', k.is_active ? '' : 'opacity-50']">
          <div class="min-w-0">
            <p class="font-medium">{{ k.name }} <span v-if="staff && !merchantId && k.merchant_name" class="text-xs text-gray-500">· {{ k.merchant_name }}</span></p>
            <p class="text-xs text-gray-500 font-mono">{{ k.masked_key }}</p>
            <p class="text-xs text-gray-500">
              {{ k.scopes.join(', ') }} ·
              {{ k.revoked_at ? `révoquée le ${dateTime(k.revoked_at)}` : k.last_used_at ? `utilisée le ${dateTime(k.last_used_at)}` : 'jamais utilisée' }}
              <span v-if="k.expires_at && !k.revoked_at"> · expire le {{ date(k.expires_at) }}</span>
            </p>
          </div>
          <button v-if="!k.revoked_at" type="button" class="btn-secondary text-red-600" @click="revoke(k)">Révoquer</button>
        </div>
        <p v-if="ready && !keys.length" class="py-3 text-sm text-gray-500">Aucune clé.</p>
      </div>
    </section>

    <!-- Webhooks -->
    <section class="card p-4 space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="font-semibold">Webhooks</h2>
          <p class="text-xs text-gray-500">Votre site est prévenu à chaque événement (signature HMAC-SHA256).</p>
        </div>
        <button type="button" class="btn-primary" :disabled="!ready" @click="openHookForm()">+ Nouvelle adresse</button>
      </div>

      <form v-if="hookForm.open" class="rounded-lg bg-slate-50 p-3 space-y-3" @submit.prevent="saveHook">
        <div>
          <label class="label" for="hook-url">Adresse (https) *</label>
          <input id="hook-url" v-model="hookForm.url" type="url" class="input" required placeholder="https://ma-boutique.ci/webhooks/livraison">
        </div>
        <fieldset>
          <legend class="label">Événements</legend>
          <label v-for="e in events" :key="e.value" class="flex items-center gap-2 text-sm py-0.5">
            <input v-model="hookForm.events" type="checkbox" :value="e.value"> {{ e.label }} <code class="text-xs text-gray-500">{{ e.value }}</code>
          </label>
        </fieldset>
        <div>
          <label class="label" for="hook-desc">Description</label>
          <input id="hook-desc" v-model="hookForm.description" class="input">
        </div>
        <p v-if="hookForm.error" class="field-error">{{ hookForm.error }}</p>
        <div class="flex gap-2">
          <button class="btn-primary" :disabled="!hookForm.events.length">Enregistrer</button>
          <button type="button" class="btn-secondary" @click="hookForm.open = false">Annuler</button>
        </div>
      </form>

      <div class="divide-y">
        <div v-for="h in hooks" :key="h.id" class="py-3 space-y-2">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-medium break-all">{{ h.url }}</p>
              <p class="text-xs text-gray-500">
                <span :class="h.is_active ? 'text-emerald-700' : 'text-gray-500'">{{ h.is_active ? 'Active' : 'Désactivée' }}</span>
                · {{ h.events.join(', ') }}
                <span v-if="staff && !merchantId && h.merchant_name"> · {{ h.merchant_name }}</span>
              </p>
              <p v-if="h.consecutive_failures || h.failed_last_week" class="text-xs text-red-600">
                {{ h.failed_last_week }} envoi(s) en échec cette semaine
              </p>
            </div>
            <div class="flex flex-wrap gap-1">
              <button type="button" class="btn-secondary px-2 py-1 text-xs" @click="test(h)">Tester</button>
              <button type="button" class="btn-secondary px-2 py-1 text-xs" @click="toggleLog(h)">{{ log.id === h.id ? 'Masquer le journal' : 'Journal' }}</button>
              <button type="button" class="btn-secondary px-2 py-1 text-xs" @click="openHookForm(h)">Modifier</button>
              <button type="button" class="btn-secondary px-2 py-1 text-xs" @click="toggleHook(h)">{{ h.is_active ? 'Désactiver' : 'Activer' }}</button>
              <button type="button" class="btn-secondary px-2 py-1 text-xs" @click="rotate(h)">Nouveau secret</button>
              <button type="button" class="btn-secondary px-2 py-1 text-xs text-red-600" @click="removeHook(h)">Supprimer</button>
            </div>
          </div>

          <div v-if="log.id === h.id" class="rounded-lg bg-slate-50 p-2 text-sm">
            <p v-if="!log.items.length" class="text-gray-500 p-1">Aucun envoi pour le moment.</p>
            <div v-for="d in log.items" :key="d.id" class="border-b last:border-0 py-1.5">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span>
                  <span :class="['rounded-full px-2 py-0.5 text-xs', DELIVERY_STATUS[d.status].class]">{{ DELIVERY_STATUS[d.status].label }}</span>
                  <code class="ml-1 text-xs">{{ d.event }}</code>
                  <span class="text-xs text-gray-500"> · {{ dateTime(d.created_at) }} · {{ d.attempts }} tentative(s){{ d.response_code ? ` · HTTP ${d.response_code}` : '' }}</span>
                </span>
                <span class="flex gap-1">
                  <button type="button" class="text-xs text-blue-600 underline" @click="log.open = log.open === d.id ? null : d.id">Contenu</button>
                  <button v-if="d.status !== 'delivered'" type="button" class="text-xs text-blue-600 underline" @click="redeliver(d)">Renvoyer</button>
                </span>
              </div>
              <p v-if="d.error && d.status !== 'delivered'" class="text-xs text-red-600">{{ d.error }}<span v-if="d.next_retry_at"> · nouvelle tentative {{ dateTime(d.next_retry_at) }}</span></p>
              <pre v-if="log.open === d.id" class="mt-1 max-h-64 overflow-auto rounded bg-slate-900 p-2 text-xs text-slate-100">{{ JSON.stringify(d.payload, null, 2) }}</pre>
            </div>
          </div>
        </div>
        <p v-if="ready && !hooks.length" class="py-3 text-sm text-gray-500">Aucune adresse.</p>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { date, dateTime } from '../utils/format'

// merchantId : marchand choisi par l'administration (null pour le marchand lui-même)
const props = defineProps({ merchantId: { type: Number, default: null }, staff: { type: Boolean, default: false } })

const DELIVERY_STATUS = {
  pending: { label: 'En attente', class: 'bg-amber-100 text-amber-800' },
  delivered: { label: 'Remis', class: 'bg-emerald-100 text-emerald-800' },
  failed: { label: 'Échec', class: 'bg-red-100 text-red-700' },
}

const toasts = useToastStore()
const keys = ref([])
const scopes = ref([])
const hooks = ref([])
const events = ref([])
const reveal = ref(null)
const keyForm = reactive({ open: false, name: '', scopes: ['orders:read', 'orders:write'], expires_at: '', error: '' })
const hookForm = reactive({ open: false, id: null, url: '', events: [], description: '', error: '' })
const log = reactive({ id: null, items: [], open: null })

// L'administration doit d'abord choisir un marchand
const ready = computed(() => !props.staff || !!props.merchantId)
const params = computed(() => (props.merchantId ? { merchant_id: props.merchantId } : {}))
const tomorrow = computed(() => new Date(Date.now() + 86400000).toISOString().slice(0, 10))

async function load() {
  const [k, w] = await Promise.all([http.get('/api-keys', { params: params.value }), http.get('/webhooks', { params: params.value })])
  keys.value = k.data.data
  scopes.value = k.data.scopes
  hooks.value = w.data.data
  events.value = w.data.events
}

async function copy(value) {
  try {
    await navigator.clipboard.writeText(value)
    toasts.success('Copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le texte.')
  }
}

async function createKey() {
  keyForm.error = ''
  try {
    const { data } = await http.post('/api-keys', { ...params.value, name: keyForm.name, scopes: keyForm.scopes, expires_at: keyForm.expires_at || null })
    reveal.value = { title: `Clé « ${data.data.name} »`, value: data.key }
    Object.assign(keyForm, { open: false, name: '', expires_at: '' })
    load()
  } catch (e) {
    keyForm.error = apiErrorMessage(e)
  }
}

async function revoke(k) {
  if (!window.confirm(`Révoquer la clé « ${k.name} » ? Les logiciels qui l'utilisent n'auront plus accès.`)) return
  await http.delete(`/api-keys/${k.id}`)
  load()
}

function openHookForm(h = null) {
  Object.assign(hookForm, { open: true, id: h?.id ?? null, url: h?.url ?? '', events: h ? [...h.events] : events.value.map((e) => e.value), description: h?.description ?? '', error: '' })
}

async function saveHook() {
  hookForm.error = ''
  try {
    const payload = { url: hookForm.url, events: hookForm.events, description: hookForm.description || null }
    if (hookForm.id) {
      await http.patch(`/webhooks/${hookForm.id}`, payload)
    } else {
      const { data } = await http.post('/webhooks', { ...params.value, ...payload })
      reveal.value = { title: 'Secret de signature du webhook', value: data.secret }
    }
    hookForm.open = false
    load()
  } catch (e) {
    hookForm.error = apiErrorMessage(e)
  }
}

async function toggleHook(h) {
  await http.patch(`/webhooks/${h.id}`, { is_active: !h.is_active })
  load()
}

async function rotate(h) {
  if (!window.confirm('Générer un nouveau secret ? L\'ancien cesse aussitôt de fonctionner.')) return
  const { data } = await http.post(`/webhooks/${h.id}/secret`)
  reveal.value = { title: 'Nouveau secret de signature', value: data.secret }
}

async function removeHook(h) {
  if (!window.confirm('Supprimer cette adresse et son journal ?')) return
  await http.delete(`/webhooks/${h.id}`)
  load()
}

async function test(h) {
  try {
    const { data } = await http.post(`/webhooks/${h.id}/test`)
    data.data.status === 'delivered'
      ? toasts.success(`Test reçu (HTTP ${data.data.response_code}).`)
      : toasts.error(`Échec du test : ${data.data.error}`)
    if (log.id === h.id) loadLog(h)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function loadLog(h) {
  log.items = (await http.get(`/webhooks/${h.id}/deliveries`)).data.data
}

async function toggleLog(h) {
  if (log.id === h.id) {
    log.id = null
    return
  }
  log.id = h.id
  log.items = []
  await loadLog(h)
}

async function redeliver(d) {
  const { data } = await http.post(`/webhook-deliveries/${d.id}/redeliver`)
  data.data.status === 'delivered' ? toasts.success('Renvoyé.') : toasts.error(`Échec : ${data.data.error}`)
  const index = log.items.findIndex((i) => i.id === d.id)
  if (index >= 0) log.items[index] = data.data
}

watch(() => props.merchantId, () => {
  log.id = null
  load()
}, { immediate: true })
</script>
