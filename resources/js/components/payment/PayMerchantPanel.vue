<template>
  <div :class="['rounded-2xl border bg-white p-4 space-y-3', main?.status === 'verified' ? 'ring-2 ring-emerald-500 border-transparent' : '']">
    <p class="font-semibold">💸 Payer ce client</p>

    <p v-if="!methods.length" class="rounded-xl bg-red-50 p-3 text-sm text-red-700">
      ⚠️ Le client n'a encore donné aucun moyen de paiement. Demandez-lui de l'ajouter dans son application (Profil › Comment être payé), ou saisissez-le depuis la page E-commerçants.
    </p>

    <template v-if="main">
      <p v-if="main.status !== 'verified'" class="rounded-xl border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900">
        ⚠️ Ce moyen n'a pas été vérifié par l'agence{{ main.status === 'rejected' ? ' (refusé)' : '' }}. Appelez le client à son numéro habituel avant de payer.
      </p>
      <div class="flex items-center gap-3">
        <PaymentMethodIcon :type="main.type" />
        <div class="min-w-0 flex-1">
          <p class="font-semibold break-words">{{ main.type_label }}{{ main.bank_name ? ` · ${main.bank_name}` : '' }} · {{ main.display_number }}</p>
          <p class="text-xs text-slate-500">{{ main.holder_name || '—' }} · <PaymentMethodStatus :status="main.status" /></p>
        </div>
        <button type="button" class="btn-secondary text-xs" @click="copy(main.type === 'bank' ? main.number : main.display_number.replace(/\s/g, ''), 'Numéro copié.')">📋 Copier</button>
      </div>
      <div class="flex flex-wrap items-start gap-3">
        <button v-if="main.qr_url" type="button" class="shrink-0" title="QR code en grand" @click="bigQr = main.qr_url">
          <AuthImage :src="main.qr_url" alt="QR code" class="h-36 w-36 rounded-lg border object-contain bg-white" />
        </button>
        <div class="min-w-[12rem] flex-1 space-y-2 text-sm">
          <a v-if="main.payment_link" :href="main.payment_link" target="_blank" rel="noopener noreferrer" class="block w-full rounded-lg bg-sky-500 py-2 text-center font-semibold text-white hover:bg-sky-600">🔗 Ouvrir le lien de paiement</a>
          <button v-if="main.qr_url" type="button" class="btn-secondary w-full" @click="bigQr = main.qr_url">🔍 QR code en grand</button>
          <p class="text-xs text-slate-500">Montant : <b>{{ money(amount) }}</b> · <button type="button" class="underline" @click="copy(String(amount), 'Montant copié.')">copier</button></p>
        </div>
      </div>
    </template>

    <div v-if="others.length" class="space-y-1 border-t pt-2 text-xs text-slate-600">
      <p class="font-medium">Autres moyens</p>
      <div v-for="m in others" :key="m.id" class="flex flex-wrap items-center gap-2">
        <button type="button" class="text-blue-600 underline" @click="selected = m.id">{{ m.summary }}</button>
        <PaymentMethodStatus :status="m.status" />
      </div>
    </div>

    <Modal :open="!!bigQr" title="QR code du client" @close="bigQr = null">
      <AuthImage v-if="bigQr" :src="bigQr" alt="QR code" class="mx-auto w-full max-w-sm object-contain" />
      <p class="mt-2 text-center text-sm">Montant : <b>{{ money(amount) }}</b></p>
    </Modal>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'
import AuthImage from '../AuthImage.vue'
import Modal from '../Modal.vue'
import PaymentMethodIcon from './PaymentMethodIcon.vue'
import PaymentMethodStatus from './PaymentMethodStatus.vue'

/**
 * Au moment de payer un reversement : le moyen préféré du client (numéro à copier, lien de
 * paiement, QR code), un avertissement s'il n'est pas vérifié, et les autres moyens.
 */
const props = defineProps({
  methods: { type: Array, default: () => [] },
  amount: { type: Number, required: true },
})
const emit = defineEmits(['select'])

const toasts = useToastStore()
const bigQr = ref(null)
// Le moyen préféré, sinon le premier vérifié, sinon le premier
const selected = ref(null)
const main = computed(() => props.methods.find((m) => m.id === selected.value)
  ?? props.methods.find((m) => m.is_preferred)
  ?? props.methods.find((m) => m.status === 'verified')
  ?? props.methods[0])
const others = computed(() => props.methods.filter((m) => m.id !== main.value?.id))

// Le moyen choisi remplit « Payé par »
watch(main, (m) => m && emit('select', m.type), { immediate: true })

async function copy(text, message) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success(message)
  } catch {
    toasts.error('Copie impossible : sélectionnez le texte à la main.')
  }
}
</script>
