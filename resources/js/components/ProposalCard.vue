<template>
  <section :class="mobile ? 'rounded-[22px] bg-amber-50 p-4 ring-1 ring-amber-200 space-y-3' : 'card p-4 space-y-3 border border-amber-300 bg-amber-50'">
    <div>
      <p class="font-semibold text-amber-900"><Icone nom="sablier" taille="petite" class="inline align-[-0.15em]" /> À valider : {{ proposal.summary }}</p>
      <p class="text-sm text-amber-900 mt-1">
        {{ proposal.proposed_by || 'Le livreur' }} indique : <strong>{{ proposal.reason }}</strong>
        <span v-if="proposal.created_at" class="text-amber-800"> · {{ dateTime(proposal.created_at) }}</span>
      </p>
      <p v-if="proposal.note" class="text-sm text-amber-900 mt-1">« {{ proposal.note }} »</p>
      <p class="text-xs text-amber-800 mt-1">
        {{ mobile ? 'Vérifiez auprès du destinataire si besoin.' : 'Vérifiez auprès du destinataire ou du client si besoin.' }}
        Rien ne change sur la course tant que ce n'est pas validé.
      </p>
    </div>

    <template v-if="proposal.can_decide">
      <div v-if="proposal.kind === 'reschedule'">
        <label :class="mobile ? 'm-label' : 'label'" :for="`proposal-date-${proposal.id}`">Date de livraison</label>
        <input :id="`proposal-date-${proposal.id}`" v-model="day" type="date" :min="today()" :class="mobile ? 'm-input' : 'input'">
      </div>
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <div class="grid grid-cols-2 gap-2">
        <button :class="mobile ? 'm-btn-primary py-3' : 'btn-primary'" :disabled="busy" @click="approve">
          <Icone nom="valider" taille="petite" />{{ proposal.kind === 'reschedule' ? 'Valider le report' : 'Valider le retour' }}
        </button>
        <button :class="mobile ? 'm-btn-secondary py-3' : 'btn-secondary'" :disabled="busy" @click="reject">Refuser</button>
      </div>
    </template>
  </section>
</template>

<script setup>
import Icone from './ui/Icone.vue'
import { ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { promptDialog } from '../composables/useDialog'
import { useToastStore } from '../stores/toasts'
import { dateTime, today } from '../utils/format'

/**
 * Report ou retour signalé par le livreur, à valider par l'équipe (fiche de la course) ou par le client (suivi du
 * colis) : validé, il prend effet ; refusé, la course reste en échec et l'équipe décide de la suite.
 */
const props = defineProps({
  orderId: { type: Number, required: true },
  proposal: { type: Object, required: true },
  mobile: { type: Boolean, default: false },
})
const emit = defineEmits(['updated'])
const toasts = useToastStore()
const busy = ref(false)
const error = ref('')
const day = ref(null)

// Date proposée par le livreur, ou aujourd'hui si elle est passée
watch(() => props.proposal, (p) => {
  day.value = p.proposed_date && p.proposed_date >= today() ? p.proposed_date : today()
  error.value = ''
}, { immediate: true })

async function approve() {
  busy.value = true
  error.value = ''
  try {
    const payload = props.proposal.kind === 'reschedule' ? { date: day.value } : {}
    const { data } = await http.post(`/orders/${props.orderId}/proposal/approve`, payload)
    toasts.success(props.proposal.kind === 'reschedule' ? 'Report validé.' : 'Retour validé.')
    emit('updated', data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function reject() {
  const note = await promptDialog('Pourquoi refusez-vous ? (ex. : le destinataire n\'a jamais demandé de report)', '', { okLabel: 'Refuser', danger: true })
  if (note === null) return
  if (!note.trim()) {
    error.value = 'Dites pourquoi : l\'équipe et le livreur le verront.'
    return
  }
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/orders/${props.orderId}/proposal/reject`, { note: note.trim() })
    toasts.success('Refusé : la course reste en échec de livraison.')
    emit('updated', data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>
