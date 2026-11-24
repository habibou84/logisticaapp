<template>
  <!-- Nouveau livreur depuis la page Livreurs : son compte (nom, téléphone, mot de passe ou invitation) -->
  <Modal :open="open" :title="invitation ? 'Lien de connexion à envoyer' : 'Nouveau livreur'" @close="close">
    <form v-if="!invitation" class="space-y-3" @submit.prevent="save">
      <div v-if="error" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
      <div><label class="label" for="nc-name">Nom *</label><input id="nc-name" v-model="data.name" class="input" required autocomplete="off"></div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="label" for="nc-phone">Téléphone *</label><input id="nc-phone" v-model="data.phone" class="input" required inputmode="tel" autocomplete="off"></div>
        <div><label class="label" for="nc-email">E-mail</label><input id="nc-email" v-model="data.email" type="email" class="input" autocomplete="off"></div>
      </div>
      <div>
        <label class="label" for="nc-password">Mot de passe (facultatif, 8 caractères min.)</label>
        <div class="flex">
          <input id="nc-password" v-model="data.password" :type="showPassword ? 'text' : 'password'" class="input rounded-r-none" autocomplete="new-password" placeholder="Vide : un lien de connexion à envoyer par WhatsApp">
          <button type="button" class="rounded-r-lg border border-l-0 border-slate-300 px-3" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPassword = !showPassword">{{ showPassword ? '🙈' : '👁️' }}</button>
        </div>
        <p class="mt-1 text-xs text-slate-600">Sans mot de passe, vous recevez un lien personnel à envoyer par WhatsApp : le livreur arrive connecté dans son application.</p>
      </div>
      <p class="text-xs text-slate-600">Ensuite, vous réglez son véhicule et ses zones.</p>
      <button class="btn-primary w-full" :disabled="saving">{{ saving ? 'Création…' : data.password ? 'Créer le livreur' : 'Créer et préparer le lien WhatsApp' }}</button>
    </form>

    <div v-else class="space-y-3 text-sm">
      <p>Envoyez ce lien à <strong>{{ invitation.user.name }}</strong> ({{ invitation.user.phone }}). Il est personnel, ne sert qu'une fois et expire le {{ dateTime(invitation.expires_at) }}.</p>
      <a :href="invitation.whatsapp_link" target="_blank" rel="noopener" class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#25D366] py-3 font-semibold text-white hover:bg-[#1ebe5b]">💬 Envoyer par WhatsApp</a>
      <div class="flex gap-2">
        <input :value="invitation.url" readonly class="input flex-1 text-xs" aria-label="Lien de connexion" @focus="$event.target.select()">
        <button type="button" class="btn-secondary" @click="copy">Copier</button>
      </div>
      <button type="button" class="btn-primary w-full" @click="next">Régler son véhicule et ses zones</button>
    </div>
  </Modal>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { dateTime } from '../utils/format'
import Modal from './Modal.vue'

const props = defineProps({ open: Boolean })
// created : le livreur est créé ({ courierId }) ; la page ouvre ensuite son profil
const emit = defineEmits(['close', 'created'])
const toasts = useToastStore()
const data = reactive({ name: '', phone: '', email: '', password: '' })
const saving = ref(false)
const error = ref('')
const showPassword = ref(false)
const invitation = ref(null)
const created = ref(null)

watch(() => props.open, (value) => {
  if (!value) return
  Object.assign(data, { name: '', phone: '', email: '', password: '' })
  error.value = ''
  invitation.value = null
  created.value = null
  showPassword.value = false
})

async function save() {
  saving.value = true
  error.value = ''
  try {
    const payload = Object.fromEntries(Object.entries({ ...data, role: 'courier' }).filter(([, v]) => v !== ''))
    const { data: res } = await http.post('/users', payload)
    created.value = res.data.courier?.id ?? null
    toasts.success('Livreur créé.')
    // Créé sans mot de passe : le lien de connexion est prêt à envoyer
    if (!payload.password) {
      try {
        invitation.value = { ...(await http.post(`/users/${res.data.id}/invitation`)).data.data, user: res.data }
      } catch (e) {
        toasts.error(apiErrorMessage(e))
        next()
      }
    } else next()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

function next() {
  emit('created', { courierId: created.value })
}

function close() {
  if (created.value !== null || invitation.value) next()
  else emit('close')
}

async function copy() {
  try {
    await navigator.clipboard.writeText(invitation.value.url)
    toasts.success('Lien copié.')
  } catch {
    toasts.error('Copie impossible : sélectionnez le lien.')
  }
}
</script>
