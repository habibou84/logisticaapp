<template>
  <section class="space-y-3">
    <div class="flex items-start justify-between gap-3">
      <div>
        <p class="font-medium">🔑 {{ hasPassword ? 'Mon mot de passe' : 'Définir un mot de passe' }}</p>
        <p class="text-sm text-slate-600">
          {{ hasPassword
            ? 'Changez-le quand vous voulez : vos autres appareils seront déconnectés.'
            : 'Votre compte n\'a pas encore de mot de passe : définissez-en un pour vous connecter.' }}
        </p>
      </div>
      <button v-if="!open" type="button" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium hover:bg-slate-50" @click="open = true">
        {{ hasPassword ? 'Changer' : 'Définir' }}
      </button>
    </div>

    <form v-if="open" class="space-y-3" @submit.prevent="save">
      <p v-if="error" class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2" role="alert">{{ error }}</p>
      <label v-if="hasPassword" class="block">
        <span class="text-sm font-medium text-slate-700">Mot de passe actuel</span>
        <input v-model="form.current_password" type="password" autocomplete="current-password" required :class="inputClass">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Nouveau mot de passe (8 caractères minimum)</span>
        <input v-model="form.password" type="password" autocomplete="new-password" required minlength="8" :class="inputClass">
      </label>
      <label class="block">
        <span class="text-sm font-medium text-slate-700">Confirmez le mot de passe</span>
        <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required minlength="8" :class="inputClass">
      </label>
      <div class="flex gap-2">
        <button type="submit" :disabled="busy" class="flex-1 rounded-xl bg-[var(--app-color,#0f172a)] py-2.5 font-semibold text-white disabled:opacity-60">
          {{ busy ? 'Enregistrement…' : 'Enregistrer' }}
        </button>
        <button type="button" class="rounded-xl border border-slate-300 px-4 py-2.5 font-medium" @click="close">Annuler</button>
      </div>
    </form>
  </section>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toasts'

const inputClass = 'mt-1 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-[var(--app-color,#0f172a)] focus:border-transparent'

const auth = useAuthStore()
const toasts = useToastStore()
const hasPassword = computed(() => auth.user?.has_password !== false)
const open = ref(false)
const busy = ref(false)
const error = ref('')
const form = reactive({ current_password: '', password: '', password_confirmation: '' })

async function save() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.put('/auth/password', { ...form, current_password: hasPassword.value ? form.current_password : null })
    auth.user = data.user
    toasts.success(data.message)
    close()
  } catch (e) {
    error.value = apiErrorMessage(e, "Le mot de passe n'a pas pu être enregistré.")
  } finally {
    busy.value = false
  }
}

function close() {
  open.value = false
  error.value = ''
  Object.assign(form, { current_password: '', password: '', password_confirmation: '' })
}
</script>
