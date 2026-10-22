<template>
  <div v-if="slip" class="space-y-4">
    <section class="m-card p-4 space-y-1">
      <p class="text-xs text-slate-500">Bon de retour {{ slip.reference }}</p>
      <p class="text-lg font-semibold">🏪 {{ slip.merchant.business_name }}</p>
      <a :href="telLink(slip.merchant.phone)" class="text-blue-600">{{ slip.merchant.phone }}</a>
      <p class="text-sm text-slate-600">{{ [slip.merchant.zone, slip.merchant.address].filter(Boolean).join(' · ') }}</p>
    </section>

    <p v-if="slip.status !== 'open'" class="rounded-xl bg-emerald-50 text-emerald-900 p-4">
      ✅ {{ slip.status_label }}<span v-if="slip.handed_at"> le {{ dateTime(slip.handed_at) }}, reçu par {{ slip.received_by_name }}</span>.
    </p>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <section class="space-y-2">
        <h2 class="font-semibold">Colis remis ({{ selected.length }} / {{ slip.orders.length }})</h2>
        <div class="m-card divide-y">
          <label v-for="o in slip.orders" :key="o.id" class="flex items-center gap-3 p-4">
            <input v-model="selected" type="checkbox" :value="o.id" class="h-5 w-5">
            <span class="flex-1 min-w-0">
              <span class="block font-medium truncate">{{ o.recipient_name || o.recipient_phone }}</span>
              <span class="block text-xs text-slate-500">{{ o.tracking_code }} · {{ o.incident || o.status_label }}</span>
            </span>
          </label>
        </div>
        <p v-if="selected.length < slip.orders.length" class="text-sm text-amber-700">Les colis décochés restent à rendre et sortent de ce bon.</p>
      </section>

      <div>
        <label class="m-label" for="ho-name">Nom de la personne qui reçoit *</label>
        <input id="ho-name" v-model="form.received_by_name" class="m-input" required maxlength="120" autocomplete="off">
      </div>

      <div>
        <p class="m-label">Signature du marchand</p>
        <SignaturePad v-model="form.signature" />
      </div>

      <div>
        <label class="m-label" for="ho-photo">Ou photo de la remise</label>
        <input id="ho-photo" type="file" accept="image/*" capture="environment" class="block w-full text-sm" @change="form.photo = $event.target.files[0] || null">
      </div>

      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <button class="m-btn-primary" :disabled="busy || !selected.length || !form.received_by_name || (!form.signature && !form.photo)">
        ✅ Valider la remise de {{ selected.length }} colis
      </button>
    </form>
  </div>
  <p v-else-if="error" class="text-center text-red-600 py-8">{{ error }}</p>
  <p v-else class="text-center text-slate-400 py-10">Chargement…</p>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import SignaturePad from '../../components/mobile/SignaturePad.vue'
import { currentPosition } from '../../composables/useGeolocation'
import { useToastStore } from '../../stores/toasts'
import { dateTime, telLink } from '../../utils/format'

const route = useRoute()
const router = useRouter()
const toasts = useToastStore()
const slip = ref(null)
const selected = ref([])
const form = reactive({ received_by_name: '', signature: null, photo: null })
const busy = ref(false)
const error = ref('')

async function submit() {
  busy.value = true
  error.value = ''
  try {
    const body = new FormData()
    body.append('received_by_name', form.received_by_name)
    if (form.signature) body.append('signature', form.signature)
    if (form.photo) body.append('photo', form.photo)
    if (selected.value.length < slip.value.orders.length) selected.value.forEach((id) => body.append('order_ids[]', id))
    const position = await currentPosition().catch(() => null)
    if (position) Object.entries(position).forEach(([k, v]) => body.append(k, v))
    await http.post(`/return-slips/${slip.value.id}/hand-over`, body)
    toasts.success(`${selected.value.length} colis rendus à ${slip.value.merchant.business_name}.`)
    router.push('/livreur')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    slip.value = (await http.get(`/return-slips/${route.params.id}`)).data.data
    selected.value = slip.value.orders.map((o) => o.id)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Bon de retour introuvable.')
  }
})
</script>
