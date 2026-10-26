<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Clients</h1>
      <div class="flex flex-wrap items-center gap-2">
        <input v-model="search" class="input w-full sm:w-56" placeholder="Rechercher…" @input="debouncedLoad">
        <select v-if="activities.length > 1" v-model="activity" class="input w-auto" aria-label="Activité" @change="load">
          <option value="">Toutes les activités</option>
          <option v-for="a in activities" :key="a.value" :value="a.value">{{ a.label }}</option>
        </select>
        <label class="flex items-center gap-2 text-sm whitespace-nowrap">
          <input v-model="onlySignups" type="checkbox" @change="load"> Inscrits en ligne
        </label>
        <button v-if="auth.can('merchants.manage')" class="btn-primary" @click="openForm()">+ Nouveau</button>
      </div>
    </div>

    <ConnectRequests v-if="auth.can('merchants.manage')" @accepted="load" />

    <div class="card overflow-x-auto">
      <table class="w-full text-sm stack-table">
        <thead class="bg-slate-50 text-left text-gray-600">
          <tr><th class="p-2">Nom</th><th class="p-2">Contact</th><th class="p-2">Ramassage</th><th class="p-2">Activités</th><th class="p-2">Facturation</th><th class="p-2">Courses</th><th class="p-2">Statut</th><th /></tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="m in merchants" :key="m.id">
            <td class="p-2 font-medium">
              {{ m.business_name }}
              <span v-if="m.source === 'signup'" class="ml-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700" :title="`Inscrit en ligne le ${new Date(m.created_at).toLocaleDateString('fr-FR')}`">
                {{ isNew(m) ? 'Nouveau · ' : '' }}inscrit en ligne
              </span>
            </td>
            <td data-label="Contact" class="p-2">{{ m.contact_name }}<div class="text-xs text-gray-500">{{ m.phone }}</div></td>
            <td data-label="Ramassage" class="p-2">
              {{ m.pickup_zone?.name || '—' }}<div class="text-xs text-gray-500">{{ m.pickup_address }}</div>
              <div :class="['text-xs', m.pickup_lat == null ? 'text-amber-700' : m.pickup_location_source === 'courier' ? 'text-sky-700' : 'text-emerald-700']">
                {{ m.pickup_lat == null ? '⚠ sans position' : `📍 ${SOURCES[m.pickup_location_source] || 'localisé'}` }}
              </div>
            </td>
            <td data-label="Activités" class="p-2">
              <div class="flex flex-wrap gap-1">
                <span v-for="a in m.activities" :key="a" :class="['rounded-full px-2 py-0.5 text-xs font-medium', activityBadge(a)]">{{ activityShort(a) }}</span>
              </div>
            </td>
            <td data-label="Facturation" class="p-2">
              <!-- Un seul bloc : la ligne devient une carte sur téléphone -->
              <div class="space-y-0.5">
                <div v-for="p in m.billing_plans || []" :key="p.id">
                  <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ p.formula_label }}</span>
                </div>
                <div v-if="m.billing_plans?.length" class="text-xs text-gray-500">Facture du mois</div>
                <template v-if="m.activities?.includes('ecommerce')">
                  <div>E-commerce : courses une à une</div>
                  <div class="text-xs text-gray-500">Frais payés par {{ m.default_fee_payer === 'recipient' ? 'le destinataire' : 'le client' }}</div>
                </template>
                <div v-else-if="!m.billing_plans?.length" class="text-xs text-amber-700">Sans formule en cours</div>
              </div>
            </td>
            <td data-label="Courses" class="p-2">{{ m.orders_count }}</td>
            <td data-label="Statut" class="p-2"><span :class="m.status === 'active' ? 'text-emerald-700' : 'text-red-600'">{{ m.status === 'active' ? 'Actif' : 'Suspendu' }}</span></td>
            <td class="p-2 text-right whitespace-nowrap">
              <RouterLink :to="{ path: '/admin/courses', query: { merchant_id: m.id } }" class="text-blue-600 mr-3">Courses</RouterLink>
              <button class="text-blue-600 mr-3" @click="billing = m">Contrat</button>
              <RouterLink :to="`/admin/marchands/${m.id}/comptes`" class="text-blue-600 mr-3">Comptes</RouterLink>
              <RouterLink v-if="m.activities?.some((a) => a !== 'ecommerce')" :to="`/admin/marchands/${m.id}/departements`" class="text-blue-600 mr-3">Départements</RouterLink>
              <button v-if="auth.can('merchants.manage')" class="text-blue-600" @click="openForm(m)">Modifier</button>
            </td>
          </tr>
          <tr v-if="!merchants.length"><td colspan="8" class="p-6 text-center text-gray-500">Aucun client.</td></tr>
        </tbody>
      </table>
    </div>

    <BillingPlanModal :open="!!billing" :merchant="billing" @close="billing = null" @saved="load" />

    <Modal :open="form.open" :title="form.id ? 'Modifier le client' : 'Nouveau client'" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <div v-if="form.error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ form.error }}</div>
        <div><label class="label">Nom commercial *</label><input v-model="form.data.business_name" class="input" required></div>
        <fieldset class="border rounded p-3">
          <legend class="text-sm font-medium px-1">Activités du client *</legend>
          <div class="flex flex-wrap gap-x-4 gap-y-1">
            <label v-for="a in activities" :key="a.value" class="flex items-center gap-2 text-sm">
              <input v-model="form.activities" type="checkbox" :value="a.value"> {{ a.label }}
            </label>
          </div>
          <p class="text-xs text-gray-500 mt-1">
            Distribution et MAD : facture du mois selon la formule (bouton « Formule »). E-commerce : frais retenus sur les reversements.
          </p>
        </fieldset>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Contact</label><input v-model="form.data.contact_name" class="input"></div>
          <div><label class="label">Téléphone *</label><input v-model="form.data.phone" class="input" required></div>
          <div><label class="label">WhatsApp</label><input v-model="form.data.whatsapp_phone" class="input"></div>
          <div><label class="label">E-mail</label><input v-model="form.data.email" type="email" class="input"></div>
        </div>
        <div>
          <label class="label">Zone de ramassage</label>
          <select v-model="form.data.pickup_zone_id" class="input">
            <option :value="null">—</option>
            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
          </select>
        </div>
        <div><label class="label">Adresse de ramassage</label><input v-model="form.data.pickup_address" class="input"></div>
        <div><label class="label">Repère</label><input v-model="form.data.pickup_landmark" class="input"></div>
        <fieldset class="border rounded p-3 space-y-2">
          <legend class="text-sm font-medium px-1">Position de ramassage (carte des livreurs)</legend>
          <p v-if="form.source && !locationChanged" class="text-xs text-gray-600">
            {{ form.source === 'courier' ? 'Estimée d\'après les ramassages des livreurs' : form.source === 'merchant' ? 'Indiquée par le client depuis sa boutique' : 'Saisie par l\'agence' }}
          </p>
          <p class="text-xs text-gray-500">Collez le lien Google Maps envoyé par le client (« Partager » → « Copier le lien »), ou placez le repère sur la carte.</p>
          <LocationPicker v-model="form.location" allow-link height="h-56" gps-label="📍 Ma position (sur place)" />
          <button v-if="form.location" type="button" class="text-xs text-red-600" @click="form.location = null">Retirer la position</button>
        </fieldset>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">Grille tarifaire</label>
            <select v-model="form.data.pricing_grid_id" class="input">
              <option :value="null">Grille par défaut</option>
              <option v-for="g in grids" :key="g.id" :value="g.id">{{ g.name }}</option>
            </select>
          </div>
          <div>
            <label class="label">Frais payés par</label>
            <select v-model="form.data.default_fee_payer" class="input">
              <option value="merchant">Client</option>
              <option value="recipient">Destinataire</option>
            </select>
          </div>
        </div>
        <div v-if="form.id">
          <label class="label">Statut</label>
          <select v-model="form.data.status" class="input">
            <option value="active">Actif</option>
            <option value="suspended">Suspendu</option>
          </select>
        </div>
        <fieldset v-else class="border rounded p-3 space-y-2">
          <legend class="text-sm font-medium px-1">Compte de connexion (facultatif)</legend>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="label">Nom</label><input v-model="form.owner.name" class="input"></div>
            <div><label class="label">Téléphone</label><input v-model="form.owner.phone" class="input"></div>
            <div class="col-span-2"><label class="label">Mot de passe (8 caractères min.)</label><input v-model="form.owner.password" type="text" class="input" autocomplete="new-password"></div>
          </div>
        </fieldset>
        <!-- Imprimé sur les factures du mois et les avoirs -->
        <details class="rounded border border-slate-200 p-3" :open="!!(form.data.ncc || form.data.vat_exempt)">
          <summary class="font-medium text-sm cursor-pointer">Facturation : raison sociale, NCC, TVA</summary>
          <div class="grid grid-cols-2 gap-3 mt-3">
            <div class="col-span-2"><label class="label" for="m-legal">Raison sociale</label><input id="m-legal" v-model="form.data.legal_name" class="input" maxlength="255" :placeholder="form.data.business_name"></div>
            <div><label class="label" for="m-ncc">NCC</label><input id="m-ncc" v-model="form.data.ncc" class="input" maxlength="30"></div>
            <div><label class="label" for="m-rccm">RCCM</label><input id="m-rccm" v-model="form.data.rccm" class="input" maxlength="60"></div>
            <div class="col-span-2"><label class="label" for="m-billing">Adresse de facturation</label><input id="m-billing" v-model="form.data.billing_address" class="input" maxlength="500" placeholder="Sinon : l'adresse de ramassage"></div>
            <div class="col-span-2">
              <label class="label" for="m-fne">Facture normalisée (FNE)</label>
              <select id="m-fne" v-model="form.data.fne_template" class="input">
                <option :value="null">Automatique : B2B avec un NCC, sinon B2C</option>
                <option value="B2B">B2B : entreprise ou professionnel avec NCC</option>
                <option value="B2G">B2G : institution publique</option>
                <option value="B2C">B2C : particulier</option>
              </select>
            </div>
            <label class="col-span-2 flex items-center gap-2 text-sm"><input v-model="form.data.vat_exempt" type="checkbox"> Exonéré de TVA</label>
            <template v-if="form.data.vat_exempt">
              <div><label class="label" for="m-exo">N° de l'attestation</label><input id="m-exo" v-model="form.data.vat_exemption_ref" class="input" maxlength="120"></div>
              <div><label class="label" for="m-exo-until">Valable jusqu'au</label><input id="m-exo-until" v-model="form.data.vat_exemption_until" type="date" class="input"></div>
              <div class="col-span-2">
                <label class="label" for="m-exo-type">Type d'exonération</label>
                <select id="m-exo-type" v-model="form.data.vat_exemption_type" class="input">
                  <option value="conventional">Conventionnelle (TVAC)</option>
                  <option value="legal">Légale (TVAD)</option>
                </select>
              </div>
            </template>
          </div>
        </details>
        <div><label class="label">Notes internes</label><textarea v-model="form.data.notes" rows="2" class="input" /></div>
        <button class="btn-primary w-full" :disabled="form.saving">Enregistrer</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BillingPlanModal from '../../components/BillingPlanModal.vue'
import ConnectRequests from '../../components/ConnectRequests.vue'
import LocationPicker from '../../components/LocationPicker.vue'
import Modal from '../../components/Modal.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { activityBadge, activityShort, myActivities } from '../../utils/activities'

const auth = useAuthStore()
const toasts = useToastStore()
const merchants = ref([])
const zones = ref([])
const grids = ref([])
const search = ref('')
const activity = ref('')
// Activités du périmètre de ce compte (cases du formulaire, filtre)
const activities = computed(() => myActivities(auth.user))
// Client dont on ouvre la formule de facturation
const billing = ref(null)
const route = useRoute()
// Lien de la notification « Nouveau client » : ?nouveaux=1
const onlySignups = ref(!!route.query.nouveaux)
const form = reactive({ open: false, id: null, data: {}, activities: [], owner: {}, error: '', saving: false, location: null, initialLocation: null, source: null })
const SOURCES = { merchant: 'par le client', staff: 'par l\'agence', courier: 'estimée (livreurs)' }
const locationChanged = computed(() => form.location?.lat !== form.initialLocation?.lat || form.location?.lng !== form.initialLocation?.lng)

const FIELDS = ['business_name', 'contact_name', 'phone', 'whatsapp_phone', 'email', 'pickup_zone_id', 'pickup_address',
  'pickup_landmark', 'pricing_grid_id', 'default_fee_payer', 'status', 'notes',
  'legal_name', 'ncc', 'rccm', 'billing_address', 'vat_exempt', 'vat_exemption_ref', 'vat_exemption_until', 'vat_exemption_type', 'fne_template']

async function load() {
  merchants.value = (await http.get('/merchants', {
    params: { search: search.value || undefined, activity: activity.value || undefined, source: onlySignups.value ? 'signup' : undefined, per_page: 200 },
  })).data.data
}

// Inscrit depuis moins de 7 jours
function isNew(m) {
  return Date.now() - new Date(m.created_at).getTime() < 7 * 86400000
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
}

function openForm(merchant = null) {
  form.id = merchant?.id ?? null
  form.data = Object.fromEntries(FIELDS.map((f) => [f, merchant?.[f] ?? null]))
  form.data.default_fee_payer ??= 'merchant'
  form.data.status ??= 'active'
  form.data.vat_exempt ??= false
  form.data.vat_exemption_type ??= 'conventional'
  // Seules les activités de son périmètre se cochent ; les autres restent telles quelles (serveur)
  const mine = activities.value.map((a) => a.value)
  form.activities = merchant ? (merchant.activities || []).filter((a) => mine.includes(a)) : [mine[0]]
  form.owner = { name: '', phone: '', password: '' }
  form.location = merchant?.pickup_lat != null ? { lat: merchant.pickup_lat, lng: merchant.pickup_lng, accuracy: null } : null
  form.initialLocation = form.location ? { ...form.location } : null
  form.source = merchant?.pickup_location_source ?? null
  form.error = ''
  form.open = true
}

async function save() {
  form.saving = true
  form.error = ''
  try {
    const payload = Object.fromEntries(Object.entries(form.data).filter(([k, v]) => v !== '' && (v !== null || form.id) && !(k === 'status' && !form.id)))
    if (!form.activities.length) {
      form.error = 'Cochez au moins une activité.'
      return
    }
    payload.activities = [...form.activities]
    if (!form.id && form.owner.phone) payload.owner = { ...form.owner, name: form.owner.name || form.data.contact_name || form.data.business_name }
    const { data } = form.id ? await http.patch(`/merchants/${form.id}`, payload) : await http.post('/merchants', payload)
    // Position : seulement si elle a changé (garde la source « marchand » ou « livreurs » sinon)
    if (locationChanged.value) {
      await http.put(`/merchants/${data.data.id}/pickup-location`, { lat: form.location?.lat ?? null, lng: form.location?.lng ?? null })
    }
    form.open = false
    toasts.success('Client enregistré.')
    load()
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

onMounted(async () => {
  load()
  zones.value = (await http.get('/zones')).data.data
  if (auth.can(['pricing.manage', 'merchants.manage'])) grids.value = (await http.get('/pricing-grids')).data.data
})
</script>
