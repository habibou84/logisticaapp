<template>
  <Teleport to="body">
    <Transition enter-active-class="transition duration-150" enter-from-class="opacity-0" leave-active-class="transition duration-100" leave-to-class="opacity-0">
      <div v-if="state.open" class="fixed inset-0 z-[100] flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4" @mousedown.self="cancel">
        <div
          ref="box" role="alertdialog" aria-modal="true" :aria-labelledby="'app-dialog-title'"
          class="w-full max-w-md rounded-t-3xl bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-2xl sm:rounded-3xl"
          @keydown.esc="cancel"
        >
          <div class="mb-1 flex items-start gap-3">
            <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-full text-[20px]', state.danger ? 'bg-red-50' : 'bg-blue-50']" aria-hidden="true">{{ state.kind === 'prompt' ? '✏️' : (state.danger ? '⚠️' : '❓') }}</span>
            <div class="min-w-0 flex-1 pt-1.5">
              <h2 v-if="state.title" id="app-dialog-title" class="text-[17px] font-bold leading-snug text-slate-900">{{ state.title }}</h2>
              <p v-if="state.message" class="mt-1 whitespace-pre-line text-[15px] text-slate-600">{{ state.message }}</p>
            </div>
          </div>

          <form class="mt-4 space-y-4" @submit.prevent="ok">
            <template v-if="state.kind === 'prompt'">
              <textarea v-if="state.multiline" ref="field" v-model="state.value" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-[16px] focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200" />
              <input v-else ref="field" v-model="state.value" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-[16px] focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200" @focus="$event.target.select()">
            </template>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <button type="button" class="rounded-xl bg-slate-100 px-4 py-3 text-[15px] font-semibold text-slate-700 hover:bg-slate-200 sm:py-2.5" @click="cancel">Annuler</button>
              <button ref="okButton" type="submit" :class="['rounded-xl px-4 py-3 text-[15px] font-bold text-white sm:py-2.5', state.danger ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700']">{{ state.okLabel || (state.kind === 'prompt' ? 'Valider' : 'Confirmer') }}</button>
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { nextTick, ref, watch } from 'vue'
import { closeDialog, dialogState as state } from '../../composables/useDialog'

/** Fenêtre de confirmation ou de saisie de l'application (remplace confirm et prompt du navigateur) */
const field = ref(null)
const okButton = ref(null)
let previous = null

watch(() => state.open, async (open) => {
  if (open) {
    previous = document.activeElement
    await nextTick()
    ;(field.value || okButton.value)?.focus()
  } else {
    previous?.focus?.()
  }
})

function ok() {
  closeDialog(state.kind === 'prompt' ? state.value : true)
}

function cancel() {
  closeDialog(state.kind === 'prompt' ? null : false)
}
</script>
