<template>
  <div class="space-y-3">
    <p v-if="merchantSide" class="text-sm text-slate-600">
      Votre agence vous reverse l'argent de vos ventes avec ces moyens. Le moyen ⭐ est utilisé en premier.
    </p>
    <p v-if="loading" class="text-sm text-gray-500">Chargement…</p>

    <!-- Nouveau moyen à vérifier : l'agence appelle le client à son numéro habituel avant de valider -->
    <p v-if="!merchantSide && pending.length" class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
      ⚠️ {{ pending.length > 1 ? `${pending.length} moyens ajoutés ou changés` : 'Moyen ajouté ou changé' }} par le client :
      <b>{{ pending.map((m) => m.summary).join(', ') }}</b>. Vérifiez auprès du client (appel à son numéro habituel) avant de valider.
    </p>

    <article
      v-for="m in methods" :key="m.id"
      :class="['rounded-2xl border bg-white p-3 space-y-2', m.is_preferred ? 'ring-2 ring-emerald-500 border-transparent' : '', !merchantSide && m.status === 'pending' ? 'ring-2 ring-amber-400 border-transparent' : '']"
    >
      <div class="flex items-center gap-3">
        <PaymentMethodIcon :type="m.type" />
        <div class="min-w-0 flex-1">
          <p class="font-semibold break-words">{{ m.type_label }}{{ m.bank_name ? ` · ${m.bank_name}` : '' }} · {{ m.display_number }} <span v-if="m.is_preferred" title="Utilisé en premier">⭐</span></p>
          <p v-if="m.holder_name" class="text-xs text-slate-500">{{ m.holder_name }}</p>
        </div>
        <AuthImage v-if="m.qr_url" :src="`${m.qr_url}`" alt="QR code" class="h-12 w-12 rounded border object-contain" />
      </div>
      <div class="flex flex-wrap items-center gap-2 text-xs">
        <PaymentMethodStatus :status="m.status" :merchant="merchantSide" :title="m.verified_by ? `Par ${m.verified_by} le ${dateTime(m.verified_at)}` : null" />
        <a v-if="m.payment_link" :href="m.payment_link" target="_blank" rel="noopener noreferrer" class="rounded-full bg-slate-100 px-2 py-0.5 hover:bg-slate-200">🔗 Lien de paiement</a>
        <span v-if="m.qr_url" class="rounded-full bg-slate-100 px-2 py-0.5">▦ QR code</span>
        <span v-if="m.status === 'rejected' && m.rejected_reason" class="text-red-700">{{ m.rejected_reason }}</span>
      </div>
      <div v-if="canEdit" class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
        <button v-if="!merchantSide && m.status !== 'verified'" class="font-semibold text-emerald-700" :disabled="busy" @click="verify(m)">✔ Valider</button>
        <button v-if="!merchantSide && m.status === 'pending'" class="text-red-700" :disabled="busy" @click="reject(m)">✕ Refuser</button>
        <button v-if="!m.is_preferred" class="text-blue-600" :disabled="busy" @click="prefer(m)">Mettre en premier</button>
        <button class="text-blue-600" @click="openForm(m)">Modifier</button>
        <button class="text-red-600" :disabled="busy" @click="remove(m)">Retirer</button>
      </div>
    </article>

    <p v-if="!loading && !methods.length" class="rounded-xl border border-dashed p-4 text-center text-sm text-slate-500">
      {{ merchantSide ? 'Aucun moyen de paiement pour l\'instant.' : 'Le client n\'a encore donné aucun moyen de paiement.' }}
    </p>
    <button v-if="canEdit && methods.length < 6" type="button" class="tap w-full rounded-xl border-2 border-dashed border-slate-300 py-3 font-medium text-slate-700" @click="openForm()">
      + Ajouter un moyen de paiement
    </button>
    <p v-if="merchantSide" class="rounded-xl border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900">
      🔒 Pour votre sécurité, chaque changement est vérifié par l'agence avant d'être utilisé, et vous êtes prévenu sur WhatsApp.
    </p>

    <!-- Historique : pour la caisse -->
    <div v-if="history?.length" class="rounded-xl border bg-white p-3">
      <p class="mb-1 text-sm font-semibold">Historique</p>
      <ul class="space-y-0.5 text-xs text-slate-700">
        <li v-for="(h, i) in history" :key="i">{{ dateTime(h.at) }} · {{ h.by_merchant ? 'le client' : (h.user || 'l\'agence') }} — {{ h.summary }}</li>
      </ul>
    </div>

    <Modal :open="form.open" :title="form.id ? 'Modifier le moyen de paiement' : 'Ajouter un moyen de paiement'" @close="form.open = false">
      <form class="space-y-4" @submit.prevent="save">
        <fieldset>
          <legend class="label">Moyen</legend>
          <div class="grid grid-cols-3 gap-2 text-sm">
            <button
              v-for="(label, value) in TYPES" :key="value" type="button"
              :class="['rounded-xl border py-2', form.data.type === value ? 'border-2 border-slate-900 bg-slate-50 font-semibold' : 'border-slate-300']"
              @click="form.data.type = value"
            >{{ label }}</button>
          </div>
        </fieldset>
        <div v-if="form.data.type === 'bank'">
          <label class="label" for="pm-bank">Banque</label>
          <input id="pm-bank" v-model="form.data.bank_name" class="input" maxlength="80" required placeholder="SGCI, Ecobank…">
        </div>
        <div>
          <label class="label" for="pm-number">{{ form.data.type === 'bank' ? 'RIB ou numéro de compte' : 'Numéro' }}</label>
          <input id="pm-number" v-model="form.data.number" class="input" :inputmode="form.data.type === 'bank' ? 'text' : 'tel'" maxlength="60" required :placeholder="form.data.type === 'bank' ? 'CI008 01234 …' : '07 00 00 00 00'">
        </div>
        <div>
          <label class="label" for="pm-holder">Au nom de <span class="font-normal text-gray-500">(facultatif)</span></label>
          <input id="pm-holder" v-model="form.data.holder_name" class="input" maxlength="120">
        </div>
        <div v-if="form.data.type !== 'bank'">
          <label class="label" for="pm-link">Lien de paiement marchand <span class="font-normal text-gray-500">(facultatif)</span></label>
          <input id="pm-link" v-model="form.data.payment_link" type="url" class="input" maxlength="500" placeholder="https://pay.wave.com/m/…">
        </div>
        <div>
          <p class="label">QR code <span class="font-normal text-gray-500">(facultatif)</span></p>
          <div class="flex items-center gap-3">
            <img v-if="form.preview" :src="form.preview" alt="QR code" class="h-20 w-20 rounded border object-contain">
            <AuthImage v-else-if="form.currentQr && !form.removeQr" :src="form.currentQr" alt="QR code" class="h-20 w-20 rounded border object-contain" />
            <div class="space-y-1 text-sm">
              <label class="btn-secondary cursor-pointer">📷 Photo ou image<input type="file" accept="image/*" class="hidden" @change="pickQr"></label>
              <button v-if="form.preview || (form.currentQr && !form.removeQr)" type="button" class="block text-red-600" @click="dropQr">Retirer le QR code</button>
            </div>
          </div>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.data.is_preferred" type="checkbox"> Utiliser ce moyen en premier</label>
        <p v-if="merchantSide" class="text-xs text-slate-500">Après l'enregistrement, ce moyen reste « à vérifier » jusqu'à la validation de votre agence.</p>
        <p v-if="form.error" class="text-sm text-red-600">{{ form.error }}</p>
        <button class="btn-primary w-full" :disabled="form.saving">{{ form.saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'
import { dateTime } from '../../utils/format'
import AuthImage from '../AuthImage.vue'
import Modal from '../Modal.vue'
import PaymentMethodIcon from './PaymentMethodIcon.vue'
import PaymentMethodStatus from './PaymentMethodStatus.vue'

/**
 * Moyens de paiement d'un client : le client les gère depuis son application (ils restent
 * « à vérifier »), la caisse les vérifie, en saisit elle-même et voit l'historique.
 */
const props = defineProps({
  merchantId: { type: Number, required: true },
  merchantSide: { type: Boolean, default: false },
  canEdit: { type: Boolean, default: true },
})
const emit = defineEmits(['changed'])

const TYPES = { wave: 'Wave', orange_money: 'Orange Money', mtn_momo: 'MTN MoMo', moov_money: 'Moov Money', bank: 'Banque' }
const toasts = useToastStore()
const methods = ref([])
const history = ref(null)
const loading = ref(false)
const busy = ref(false)
const pending = computed(() => methods.value.filter((m) => m.status === 'pending'))
const form = reactive({ open: false, id: null, data: {}, file: null, preview: null, currentQr: null, removeQr: false, error: '', saving: false })

async function load() {
  loading.value = true
  try {
    const { data } = await http.get(`/merchants/${props.merchantId}/payment-methods`)
    methods.value = data.data
    history.value = data.history
  } finally {
    loading.value = false
  }
}

function openForm(m = null) {
  form.id = m?.id ?? null
  form.data = {
    type: m?.type ?? 'wave',
    number: m ? (m.type === 'bank' ? m.number : m.display_number) : '',
    holder_name: m?.holder_name ?? '',
    bank_name: m?.bank_name ?? '',
    payment_link: m?.payment_link ?? '',
    is_preferred: m ? m.is_preferred : !methods.value.length,
  }
  clearPreview()
  form.file = null
  form.currentQr = m?.qr_url ?? null
  form.removeQr = false
  form.error = ''
  form.open = true
}

function clearPreview() {
  if (form.preview) URL.revokeObjectURL(form.preview)
  form.preview = null
}

function pickQr(e) {
  const file = e.target.files?.[0]
  if (!file) return
  clearPreview()
  form.file = file
  form.preview = URL.createObjectURL(file)
  form.removeQr = false
}

function dropQr() {
  clearPreview()
  form.file = null
  form.removeQr = true
}

async function save() {
  form.saving = true
  form.error = ''
  try {
    const body = new FormData()
    for (const [k, v] of Object.entries(form.data)) {
      if (k === 'is_preferred') body.append(k, v ? '1' : '0')
      else if (v !== null && v !== '') body.append(k, v)
    }
    if (form.file) body.append('qr', form.file)
    if (form.removeQr) body.append('remove_qr', '1')
    if (form.id) await http.post(`/merchant-payment-methods/${form.id}`, body)
    else await http.post(`/merchants/${props.merchantId}/payment-methods`, body)
    form.open = false
    toasts.success(props.merchantSide ? 'Enregistré. Votre agence va le vérifier.' : 'Moyen de paiement enregistré.')
    await refresh()
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

async function act(fn, message) {
  busy.value = true
  try {
    await fn()
    if (message) toasts.success(message)
    await refresh()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function refresh() {
  await load()
  emit('changed', methods.value)
}

const prefer = (m) => act(() => http.post(`/merchant-payment-methods/${m.id}/preferred`), 'Utilisé en premier.')
const verify = (m) => act(() => http.post(`/merchant-payment-methods/${m.id}/verify`), 'Moyen de paiement validé.')
function reject(m) {
  const reason = window.prompt('Pourquoi ce moyen est-il refusé ? (facultatif)')
  if (reason === null) return
  act(() => http.post(`/merchant-payment-methods/${m.id}/reject`, { reason: reason || undefined }), 'Moyen de paiement refusé.')
}
function remove(m) {
  if (!window.confirm(`Retirer ${m.summary} ?`)) return
  act(() => http.delete(`/merchant-payment-methods/${m.id}`), 'Moyen de paiement retiré.')
}

watch(() => props.merchantId, load, { immediate: true })
onBeforeUnmount(clearPreview)
</script>
