<template>
  <div class="max-w-5xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-xl font-bold">Simulateur WhatsApp</h1>
      <RouterLink to="/admin/whatsapp" class="btn-secondary">Paramètres WhatsApp</RouterLink>
    </div>
    <p class="text-sm text-gray-600">
      Écrivez au bot comme un e-commerçant depuis son téléphone : demande de course, commande de client transférée,
      suivi d'un colis… Les courses confirmées ici sont réellement créées (source « whatsapp »).
    </p>

    <div class="grid md:grid-cols-[1fr_20rem] gap-4">
      <!-- Conversation -->
      <div class="card flex flex-col h-[70vh] overflow-hidden">
        <div class="flex items-center gap-3 border-b p-3 bg-emerald-700 text-white">
          <span class="text-2xl">💬</span>
          <div class="min-w-0">
            <p class="font-semibold truncate">{{ companyName }}</p>
            <p class="text-xs opacity-80 truncate">vu par {{ sender?.label || 'choisissez un expéditeur' }}</p>
          </div>
        </div>

        <div ref="scroller" class="flex-1 overflow-y-auto p-4 space-y-3 bg-[#efeae2]">
          <p v-if="!log.length" class="text-center text-sm text-gray-500 py-10">Envoyez « Bonjour » pour commencer.</p>
          <div v-for="(m, i) in log" :key="i" :class="['flex', m.from === 'me' ? 'justify-end' : 'justify-start']">
            <div :class="['max-w-[85%] rounded-lg px-3 py-2 shadow-sm text-sm', m.from === 'me' ? 'bg-[#d9fdd3]' : 'bg-white']">
              <p class="whitespace-pre-line" v-html="formatText(m.text)" />
              <div v-if="m.buttons?.length" class="mt-2 -mx-3 -mb-2 border-t divide-y">
                <button
                  v-for="b in m.buttons"
                  :key="b.id"
                  type="button"
                  class="w-full py-2 text-center text-sky-700 font-medium hover:bg-sky-50 disabled:text-gray-400"
                  :disabled="sending || i !== log.length - 1"
                  @click="send(b.title, b.id)"
                >
                  {{ b.title }}
                </button>
              </div>
            </div>
          </div>
          <p v-if="sending" class="text-xs text-gray-500">Le bot écrit…</p>
        </div>

        <form class="flex gap-2 border-t p-3 bg-white" @submit.prevent="send(draft)">
          <textarea
            v-model="draft"
            rows="2"
            class="input flex-1 resize-none"
            placeholder="Votre message…"
            aria-label="Message"
            :disabled="!sender"
            @keydown.enter.exact.prevent="send(draft)"
          />
          <button class="btn-primary self-end" :disabled="sending || !draft.trim() || !sender">Envoyer</button>
        </form>
      </div>

      <!-- Expéditeur et exemples -->
      <aside class="space-y-4">
        <div class="card p-4 space-y-2">
          <label class="label" for="sender">Écrire en tant que</label>
          <select id="sender" v-model="senderKey" class="input" @change="log = []">
            <option v-for="s in senders" :key="s.key" :value="s.key">{{ s.label }}</option>
          </select>
          <p class="text-xs text-gray-500">Numéro WhatsApp du marchand, ou un numéro inconnu pour voir le refus.</p>
        </div>

        <div class="card p-4 space-y-2 text-sm">
          <h2 class="font-semibold">Exemples à essayer</h2>
          <button v-for="ex in examples" :key="ex" type="button" class="block w-full text-left rounded bg-slate-50 hover:bg-slate-100 p-2 whitespace-pre-line text-xs" @click="draft = ex">
            {{ ex }}
          </button>
        </div>

        <p v-if="!settings?.ai_parser" class="text-xs text-gray-500">
          Analyse par règles. Ajoutez <code>ANTHROPIC_API_KEY</code> dans <code>.env</code> pour que Claude comprenne aussi les messages sans format.
        </p>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

const auth = useAuthStore()
const toasts = useToastStore()
const merchants = ref([])
const settings = ref(null)
const senderKey = ref('')
const draft = ref('')
const log = ref([])
const sending = ref(false)
const scroller = ref(null)

const companyName = computed(() => auth.user?.company?.name || 'Agence')
const senders = computed(() => [
  ...merchants.value
    .filter((m) => m.whatsapp_phone || m.phone)
    .map((m) => ({ key: `m${m.id}`, label: `${m.business_name} (${m.whatsapp_phone || m.phone})`, phone: m.whatsapp_phone || m.phone })),
  { key: 'unknown', label: 'Numéro inconnu (+225 07 99 99 99 99)', phone: '+2250799999999' },
])
const sender = computed(() => senders.value.find((s) => s.key === senderKey.value))

const examples = [
  'Bonjour',
  'Nom : Awa Koné\nTél : 07 08 09 10 11\nCommune : Yopougon\nAdresse : Siporex\nMontant : 15000',
  "Bonsoir, c'est pour Awa à Yopougon Siporex face à la pharmacie, son numéro 07 08 09 10 11, total 12 500 F livraison à la charge du client",
  'Une livraison à Cocody svp',
]

// *gras* WhatsApp → <strong>, texte échappé
function formatText(text) {
  const escaped = text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
  return escaped.replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>')
}

async function scrollDown() {
  await nextTick()
  if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
}

async function send(text, buttonId = null) {
  if (!sender.value || sending.value || (!buttonId && !text.trim())) return
  log.value.push({ from: 'me', text })
  if (!buttonId) draft.value = ''
  sending.value = true
  scrollDown()
  try {
    const { data } = await http.post('/whatsapp/simulate', {
      from: sender.value.phone,
      ...(buttonId ? { button_id: buttonId, button_title: text } : { text }),
    })
    for (const r of data.data.replies) log.value.push({ from: 'bot', text: r.text, buttons: r.buttons })
    if (data.data.order_id) toasts.success('Course créée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    sending.value = false
    scrollDown()
  }
}

onMounted(async () => {
  const [m, s] = await Promise.all([http.get('/merchants', { params: { per_page: 200 } }), http.get('/whatsapp/settings')])
  merchants.value = m.data.data
  settings.value = s.data.data
  senderKey.value = senders.value[0]?.key || ''
})
</script>
