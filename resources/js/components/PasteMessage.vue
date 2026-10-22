<template>
  <section :class="mobile ? 'm-card p-4 space-y-3' : 'card p-4 space-y-3'">
    <button v-if="!open" type="button" :class="mobile ? 'm-btn-secondary' : 'btn-secondary w-full'" @click="open = true">
      📋 Coller un message (WhatsApp, SMS…) pour remplir la course
    </button>
    <template v-else>
      <label :class="mobile ? 'm-label' : 'label'" for="paste-message">{{ mobile ? 'Collez ici le message de votre client' : 'Message du client ou de l\'e-commerçant' }}</label>
      <textarea
        id="paste-message"
        ref="area"
        v-model="text"
        rows="4"
        :class="mobile ? 'm-input' : 'input'"
        placeholder="Ex. : Awa 07 07 12 34 56, Yopougon Siporex derrière la pharmacie, 15 000 F à encaisser, livraison payée par le client"
      />
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" :class="mobile ? 'm-btn-primary' : 'btn-primary'" :disabled="busy || !text.trim()" @click="analyse">
          {{ busy ? 'Analyse…' : '✨ Analyser et remplir' }}
        </button>
        <button type="button" :class="mobile ? 'm-btn-secondary w-auto px-4' : 'btn-secondary'" @click="close">Fermer</button>
      </div>
      <p class="text-xs text-gray-500">Les champs remplis sont marqués « à vérifier » : relisez-les avant d'enregistrer.</p>
    </template>
  </section>
</template>

<script setup>
import { nextTick, onMounted, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'

/**
 * Message collé (conversation WhatsApp, SMS) analysé par le même moteur que le robot WhatsApp :
 * renvoie les champs trouvés et les communes possibles, la plus probable en premier.
 */
const props = defineProps({
  merchantId: { type: Number, default: null },
  mobile: { type: Boolean, default: false },
  // Ouvert d'emblée (bouton « Coller un message WhatsApp » de l'accueil)
  startOpen: { type: Boolean, default: false },
})
const emit = defineEmits(['parsed'])

const open = ref(props.startOpen)
const text = ref('')
const busy = ref(false)
const error = ref('')
const area = ref(null)

onMounted(() => {
  if (open.value) area.value?.focus()
})

watch(open, async (value) => {
  if (!value) return
  await nextTick()
  area.value?.focus()
})

function close() {
  open.value = false
  error.value = ''
}

async function analyse() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post('/orders/parse-message', { text: text.value, merchant_id: props.merchantId || undefined })
    const found = Object.keys(data.data).filter((k) => k !== 'zones' || data.data.zones.length)
    if (!found.length) {
      error.value = 'Rien de reconnu dans ce message : remplissez la course à la main.'
      return
    }
    emit('parsed', data.data)
    text.value = ''
    open.value = false
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>
