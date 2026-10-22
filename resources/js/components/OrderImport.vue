<template>
  <div class="space-y-4">
    <section class="card p-4 space-y-3">
      <p class="text-sm text-gray-600">
        Une ligne par course : téléphone et commune obligatoires ; nom, quartier, adresse, repère, montant, qui paie la livraison
        (« client » ou « moi »), description, référence, date et note facultatifs. Fichier CSV ou Excel (.xlsx), 500 lignes au plus.
      </p>
      <button type="button" class="btn-secondary" @click="downloadTemplate">⬇️ Télécharger le modèle</button>

      <div v-if="staff">
        <label class="label" for="imp-merchant">E-commerçant *</label>
        <select id="imp-merchant" v-model="merchantId" class="input" @change="reset">
          <option :value="null" disabled>Choisir…</option>
          <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.business_name }}</option>
        </select>
      </div>

      <div>
        <label class="label" for="imp-file">Fichier</label>
        <input id="imp-file" ref="fileInput" type="file" accept=".csv,.xlsx,text/csv" class="input" @change="pick">
      </div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <p v-if="busy" class="text-sm text-gray-500">Analyse en cours…</p>
    </section>

    <!-- Aperçu -->
    <section v-if="preview && !result" class="card p-4 space-y-3">
      <div class="flex flex-wrap gap-4 text-sm">
        <span class="text-emerald-700 font-semibold">{{ preview.valid }} course(s) prête(s)</span>
        <span v-if="preview.invalid" class="text-red-600 font-semibold">{{ preview.invalid }} ligne(s) en erreur</span>
        <span>Frais de livraison : <strong>{{ money(preview.total_fees) }}</strong></span>
        <span>À encaisser : <strong>{{ money(preview.total_cod) }}</strong></span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Ligne</th><th class="p-2">Client</th><th class="p-2">Commune</th><th class="p-2 text-right">Articles</th><th class="p-2 text-right">Livraison</th><th class="p-2 text-right">À encaisser</th><th class="p-2">Contrôle</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="r in preview.rows" :key="r.line" :class="r.errors.length ? 'bg-red-50' : ''">
              <td class="p-2">{{ r.line }}</td>
              <td class="p-2">{{ r.data.recipient_name || '—' }}<div class="text-xs text-gray-500">{{ r.data.recipient_phone }}</div></td>
              <td class="p-2">{{ r.zone_name || '—' }}</td>
              <td class="p-2 text-right">{{ money(r.data.items_amount || 0) }}</td>
              <td class="p-2 text-right">{{ r.errors.length ? '—' : money(r.delivery_fee) }}<div v-if="!r.errors.length" class="text-xs text-gray-500">{{ r.data.fee_payer === 'recipient' ? 'client' : 'marchand' }}</div></td>
              <td class="p-2 text-right font-semibold">{{ r.errors.length ? '—' : money(r.cod_amount) }}</td>
              <td class="p-2">
                <span v-if="!r.errors.length" class="text-emerald-700">✓</span>
                <ul v-else class="text-xs text-red-700 list-disc pl-4"><li v-for="err in r.errors" :key="err">{{ err }}</li></ul>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-primary" :disabled="busy || !preview.valid" @click="run">
          {{ preview.invalid ? `Importer les ${preview.valid} course(s) valides` : `Importer les ${preview.valid} course(s)` }}
        </button>
        <button type="button" class="btn-secondary" @click="reset">Choisir un autre fichier</button>
      </div>
    </section>

    <!-- Résultat -->
    <section v-if="result" class="card p-4 space-y-2 text-sm">
      <p class="font-semibold text-emerald-700">✅ {{ result.created.length }} course(s) créée(s).</p>
      <p v-for="e in result.errors" :key="e.line" class="text-red-600">Ligne {{ e.line }} : {{ e.message }}</p>
      <div class="flex flex-wrap gap-1">
        <RouterLink v-for="c in result.created" :key="c.id" :to="`${base}/courses/${c.id}`" class="rounded bg-slate-100 px-2 py-1 font-mono text-xs">{{ c.tracking_code }}</RouterLink>
      </div>
      <button type="button" class="btn-secondary" @click="reset">Importer un autre fichier</button>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { money } from '../utils/format'

const auth = useAuthStore()
const staff = computed(() => !auth.user?.merchant_id)
const base = computed(() => (staff.value ? '/admin' : '/marchand'))

const merchants = ref([])
const merchantId = ref(null)
const file = ref(null)
const fileInput = ref(null)
const preview = ref(null)
const result = ref(null)
const error = ref('')
const busy = ref(false)

function form(extra = {}) {
  const data = new FormData()
  data.append('file', file.value)
  if (staff.value) data.append('merchant_id', merchantId.value)
  Object.entries(extra).forEach(([k, v]) => data.append(k, v))
  return data
}

async function pick(event) {
  file.value = event.target.files[0] || null
  preview.value = null
  result.value = null
  error.value = ''
  if (!file.value) return
  if (staff.value && !merchantId.value) {
    error.value = 'Choisissez d\'abord l\'e-commerçant.'
    return
  }
  busy.value = true
  try {
    preview.value = (await http.post('/orders/import', form({ dry_run: 1 }))).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function run() {
  busy.value = true
  error.value = ''
  try {
    result.value = (await http.post('/orders/import', form({ skip_invalid: preview.value.invalid ? 1 : 0 }))).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

function reset() {
  preview.value = null
  result.value = null
  file.value = null
  error.value = ''
  if (fileInput.value) fileInput.value.value = ''
}

async function downloadTemplate() {
  const { data } = await http.get('/orders/import/template', { responseType: 'blob' })
  const url = URL.createObjectURL(data)
  const link = Object.assign(document.createElement('a'), { href: url, download: 'modele-import-courses.csv' })
  link.click()
  URL.revokeObjectURL(url)
}

onMounted(async () => {
  if (staff.value) merchants.value = (await http.get('/merchants', { params: { per_page: 200, status: 'active' } })).data.data
})
</script>
